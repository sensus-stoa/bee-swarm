<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\LawShape;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * VERIF-COLLABEL-PARITY WU-3: регресс-гвард живого пути.
 *
 * Хендофф: «тест foraged-закон с col_labels переживает resample_1..5».
 * Fingerprint-gap урок (05.09): каждое новое поле task-контракта обязано
 * иметь integration-тест пути recordDiscovery — unit-тесты VTS напрямую
 * не ловят потерю labels на живом пути (TaskRouter → doDiscoverTick →
 * recordDiscovery → spawnForLaw).
 *
 * Пин: foraged-закон (доменная формула + labels в task) проходит полный
 * цикл верификации: resample 5/5 VCONFIRMED → escrow PAID.
 * До фикса этот путь давал 5×VREFUTED → burn (sandbox: 270 refuted).
 */
final class ForagedLawVerificationLiveTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        // Escrow-грейс = 0: консенсус считается сразу (паттерн EscrowWiringTest).
        putenv('ESCROW_GRACE_TASKS=0');
        $this->logFile = tempnam(sys_get_temp_dir(), 'vclp_live_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        putenv('ESCROW_GRACE_TASKS');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * Живой путь recordDiscovery с доменным атомом + col_labels в task.
     */
    private function discoverForagedLaw(string $formula, array $labels, string $domain): void
    {
        $this->injectRoutedBee();
        [$X, $y] = $this->linearFixture(12);
        $foundAny = false;
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, [
            [
                'atom' => $formula,
                'cv' => 0.001,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'foraged_vclp',
                'domain' => $domain,
                'fingerprint' => 'fp_' . md5($domain),
                'col_labels' => $labels,
            ],
            $domain,
            &$foundAny,
            $X,
            $y,
        ]);
        self::assertTrue($foundAny, 'закон записан');
    }

    private function injectRoutedBee(): void
    {
        $bees = new \ReflectionProperty(Hive::class, 'bees');
        $bees->setAccessible(true);
        $all = $bees->getValue($this->hive);
        $first = array_values(array_filter($all, fn ($b) => $b->isAlive()))[0] ?? null;
        self::assertNotNull($first, 'bootstrap дал живую пчелу');
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $rb->setValue($this->hive, $first);
    }

    /**
     * y = 2*x (закон K2*x0 в generic-мире; имя колонки доменное).
     *
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private function linearFixture(int $n): array
    {
        $X = [];
        $y = [];
        foreach (range(1, $n) as $i) {
            $X[] = [(float) $i];
            $y[] = 2.0 * $i;
        }

        return [$X, $y];
    }

    /**
     * WU-3 пин 1: живой путь кладёт GENERIC-маску в law_shape (не доменную).
     */
    public function testLivePathForagedLawWritesGenericShape(): void
    {
        $this->discoverForagedLaw('(K2×col0)', ['col0'], 'vclp_live_shape');

        $row = Database::get()->query(
            "SELECT law_formula, law_formula_generic, law_shape FROM verification_tasks
             WHERE domain = 'vclp_live_shape' LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'V-задачи записаны живым путём');
        self::assertSame('(K2×col0)', $row['law_formula'], 'law_formula доменный');
        self::assertSame('(K2×x0)', $row['law_formula_generic'], 'generic-канон по labels');
        self::assertSame(
            LawShape::of('(K2×x0)'),
            $row['law_shape'],
            'law_shape generic — иначе ресемпл-срез исполнителя НЕ СМОЖЕТ подтвердить'
        );
    }

    /**
     * WU-3 пин 2 (главный): foraged-закон ПЕРЕЖИВАЕТ resample_1..5 —
     * консенсус 5/5 VCONFIRMED, escrow PAID, burn НЕТ.
     */
    public function testForagedLawSurvivesResampleConsensus(): void
    {
        $this->discoverForagedLaw('(K2×col0)', ['col0'], 'vclp_live_consensus');

        $executed = $this->hive->runPendingVerificationTasks('vclp_live_consensus', 10);
        self::assertSame(6, $executed, 'исполнены 5 resample + 1 inverted');

        $log = (string) file_get_contents($this->logFile);
        $resampleConfirmed = substr_count($log, 'VCONFIRMED task=resample');
        self::assertSame(
            5,
            $resampleConfirmed,
            'ресемплы 5/5 подтверждают форму (до фикса: 5×VREFUTED на foraged-законе)'
        );
        // Контракт 10.09: inverted-shape-refuted = НОРМА ко-гейта знака
        // (зеркало не подтверждено, escrow не трогается) — VREFUTED на
        // inverted НЕ сжигает эскроу. Аномалия была бы ANOMALY, не VREFUTED.
        self::assertSame(0, substr_count($log, 'ANOMALY'), 'нет аномалий');

        $escrow = Database::get()->query(
            "SELECT status FROM law_escrow WHERE domain = 'vclp_live_consensus'"
        )->fetchColumn();
        self::assertSame('paid', $escrow, 'консенсус закрыл escrow выплатой — закон пережил верификацию');
    }
}
