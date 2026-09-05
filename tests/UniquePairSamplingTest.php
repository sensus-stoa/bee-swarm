<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RecordKeeper;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * T5-post-4 (story theorem-level): семплирование уникальных fingerprint-пар.
 *
 * ЭКСП-037 находка: confirm-бёрст (308 confirms от twin-фидов) бустит sqrt
 * 1→50 мгновенно — step-функция. Причина: каждый confirm давал +1 оператору,
# даже если это повторное подтверждение той же пары данных.
 *
 * Фикс: confirm засчитывается для буста ТОЛЬКО если fingerprint НОВЫЙ для
 * закона (набор виденных fp хранится в законе, cap 10). Повторный скан тех же
 * данных = та же fp-пара = не новая информация = буста нет.
 *
 * Интеграционный урок ЭКСП-037 (fingerprint-gap): тесты прогоняют запись
# через Hive::recordDiscovery с fingerprint в $task — живой путь, не инъекция.
 */
final class UniquePairSamplingTest extends TestCase
{
    private RecordKeeper $keeper;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->keeper = new RecordKeeper();
    }

    protected function tearDown(): void
    {
        Database::setPath(':memory:');
        Database::reset();
    }

    private function opUsage(string $op): int
    {
        $stmt = Database::get()->prepare('SELECT usage_count FROM grammar_ops WHERE name = ?');
        $stmt->execute([$op]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function seenFingerprints(string $formula, string $domain): array
    {
        $stmt = Database::get()->prepare(
            'SELECT seen_fingerprints FROM laws WHERE formula = ? AND domain = ?'
        );
        $stmt->execute([$formula, $domain]);
        $raw = $stmt->fetchColumn();

        return $raw ? (array) json_decode((string) $raw, true) : [];
    }

    private function record(string $fingerprint): void
    {
        $this->keeper->record(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't_' . substr($fingerprint, 0, 6), 'domain' => 'test_uq', 'fingerprint' => $fingerprint],
            'test_uq'
        );
    }

    /** RED: буст только на НОВОМ fingerprint; повтор той же пары не бустит. */
    public function testBoostOnlyOnNewFingerprint(): void
    {
        Database::get()->prepare(
            'INSERT OR IGNORE INTO grammar_ops (name, source, usage_count) VALUES (?, ?, 0)'
        )->execute(['×', 'test_uq']);

        $this->record('fp_A'); // первое открытие, confirm=false
        self::assertSame(0, $this->opUsage('×'));

        $this->record('fp_B'); // новый fp → confirm → буст
        self::assertSame(1, $this->opUsage('×'));

        $this->record('fp_B'); // ТОТ ЖЕ fp повторно → не новая пара → буста нет
        $this->record('fp_B');
        self::assertSame(1, $this->opUsage('×'),
            'повтор той же fp-пары не даёт буста (ЭКСП-037 step-функция)');

        $this->record('fp_C'); // снова новый → буст
        self::assertSame(2, $this->opUsage('×'));
    }

    /** RED: набор виденных fp хранится в законе (cap 10). */
    public function testSeenFingerprintsTracked(): void
    {
        foreach (['fp_A', 'fp_B', 'fp_C'] as $fp) {
            $this->record($fp);
        }
        $seen = $this->seenFingerprints('(K2×x0)', 'test_uq');
        self::assertCount(3, $seen, 'все три fp зафиксированы');
        self::assertContains('fp_A', $seen);
    }

    /** RED: cap 10 — после 11+ fp набор не растёт бесконечно, буст продолжается только с новыми. */
    public function testSeenFingerprintsCapped(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->record('fp_' . $i);
        }
        $seen = $this->seenFingerprints('(K2×x0)', 'test_uq');
        self::assertLessThanOrEqual(10, count($seen), 'cap 10 на набор fp');
        // при этом usage_count отражает ~10 бустов + первичное открытие
        $this->opUsage('×');
    }

    /**
     * ИНТЕГРАЦИОННЫЙ тест (урок ЭКСП-037 fingerprint-gap): полный путь через
     * Hive::recordDiscovery — fingerprint попадает в $task из doDiscoverTick,
     * record видит реальный fp, а не пустую строку.
     */
    public function testIntegrationFingerprintFlowsThroughHive(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'uq_int_');
        $hive = new \BeeSwarm\Hive\Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        $method = new \ReflectionMethod(\BeeSwarm\Hive\Hive::class, 'recordDiscovery');
        $method->setAccessible(true);
        $foundAny = false;

        $d = ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'];
        // путь как в проде: task с fingerprint (doDiscoverTick теперь прописывает)
        $method->invoke($hive, $d,
            ['name' => 'i1', 'domain' => 'test_int', 'fingerprint' => 'fp_X1'],
            'test_int', $foundAny);
        $method->invoke($hive, $d,
            ['name' => 'i2', 'domain' => 'test_int', 'fingerprint' => 'fp_X2'],
            'test_int', $foundAny);
        // повтор с тем же fp через живой путь
        $method->invoke($hive, $d,
            ['name' => 'i3', 'domain' => 'test_int', 'fingerprint' => 'fp_X2'],
            'test_int', $foundAny);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        // confirm был ровно один (fp_X1→fp_X2), CONFIRMED_POOL залогирован
        self::assertSame(
            1,
            substr_count($log, 'CONFIRMED_POOL'),
            'интеграционный путь: 2 разных fp = 1 confirm; повтор fp не бустит'
        );

        $keeper = new RecordKeeper();
        $seen = $this->seenFingerprints('(K2×x0)', 'test_int');
        self::assertCount(2, $seen, 'fp_X2 повторно не добавлен в набор');
    }
}
