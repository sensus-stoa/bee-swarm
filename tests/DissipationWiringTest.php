<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 (story DISSIPATION-LOOP): wiring всех четырёх механизмов в Hive.
 *
 * Интеграционные тесты полного пути (урок ЭКСП-037 fingerprint-gap):
 * каждое новое поле/вызов task-контракта проверяется через живой путь
 * Hive::doTick → recordDiscovery, не инъекцией.
 *
 * Контракт стори: диссипация — наблюдатель. DISSIPATION-лог + atom-penalty,
 * discovery не блокируется. E2E-индикатор: DISSIPATION events ≥ 1.
 */
final class DissipationWiringTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'diss_wire_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run(); // bootstrap
    }

    protected function tearDown(): void
    {
        if (isset($this->logFile) && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    private function invokeDiscovery(array $d, array $task, string $domain, bool &$foundAny): void
    {
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        // invoke не передаёт by-ref параметры — только invokeArgs с reference
        $m->invokeArgs($this->hive, [$d, $task, $domain, &$foundAny]);
    }

    /** RED: discovery регистрирует закон в law_generations (живой путь). */
    public function testDiscoveryRegistersGeneration(): void
    {
        $foundAny = false;
        $this->invokeDiscovery(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 'dw1', 'domain' => 'test_wire', 'fingerprint' => 'fp_1'],
            'test_wire',
            $foundAny
        );

        // register получает КАНОН (тот же ключ, что в laws — иначе fake-LOSS:
        // aliveFormulas сравнивает law_generations с канонами из laws)
        $stmt = Database::get()->prepare(
            'SELECT generation FROM law_generations WHERE formula = ? AND domain = ?'
        );
        $stmt->execute(['(K2×x0)', 'test_wire']);
        $gen = $stmt->fetchColumn();
        self::assertNotFalse($gen, 'discovery обязан регистрировать закон в law_generations');
    }

    /** RED: аудит потерь эмитит DISSIPATION лог и falsify атомам LOSS-формулы. */
    public function testLossAuditEmitsDissipationAndPenalty(): void
    {
        // закон РЕГИСТРИРУЕТСЯ, но НЕ записывается в laws (эмуляция исчезновения:
        // пчела нашла и умерла, запись потеряна — preservation-кейс §2.5.4)
        $reg = new \BeeSwarm\Hive\LawRegistry(preserveCheckGen: 15, eps: 0.15);
        $reg->register('(K2×x0)', 'test_wire2', generation: 1);

        // диссипативный аудит: gen >= 15, reservoir пуст → vanished → LOSS
        $m = new \ReflectionMethod(Hive::class, 'runDissipationAudit');
        $m->setAccessible(true);
        $m->invoke($this->hive, currentGeneration: 15);

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('DISSIPATION', $log,
            'LOSS-событие обязано логироваться как DISSIPATION');

        // атом формулы (K2×x0): × получил falsify
        $stmt = Database::get()->prepare('SELECT penalty_count FROM atom_penalties WHERE atom = ?');
        $stmt->execute(['×']);
        $count = $stmt->fetchColumn();
        self::assertNotFalse($count, 'LOSS обязано штрафовать операторы формулы');
        self::assertSame(1, (int) $count);
    }

    /** RED: confirm-путь реабилитирует атомы (успех декрементирует штраф). */
    public function testConfirmRehabilitatesAtoms(): void
    {
        // пред-штраф: × имеет 5 фальсификаций
        Database::get()->prepare(
            'INSERT INTO atom_penalties (atom, penalty_count) VALUES (?, 5)'
        )->execute(['×']);

        $foundAny = false;
        // первое открытие
        $this->invokeDiscovery(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 'dw1', 'domain' => 'test_wire3', 'fingerprint' => 'fp_1'],
            'test_wire3',
            $foundAny
        );
        // confirm на другом fingerprint
        $this->invokeDiscovery(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 'dw2', 'domain' => 'test_wire3', 'fingerprint' => 'fp_2'],
            'test_wire3',
            $foundAny
        );

        $stmt = Database::get()->prepare('SELECT penalty_count FROM atom_penalties WHERE atom = ?');
        $stmt->execute(['×']);
        self::assertSame(4, (int) $stmt->fetchColumn(),
            'подтверждение закона реабилитирует его операторы (декремент)');
    }

    /** Discovery не блокируется диссипацией (контракт наблюдателя). */
    public function testDiscoveryNotBlocked(): void
    {
        // заполнить penalties сверх порога
        Database::get()->prepare(
            'INSERT INTO atom_penalties (atom, penalty_count) VALUES (?, 50)'
        )->execute(['×']);

        $foundAny = false;
        $this->invokeDiscovery(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 'dw1', 'domain' => 'test_wire4', 'fingerprint' => 'fp_1'],
            'test_wire4',
            $foundAny
        );

        self::assertTrue($foundAny, 'законы с заштрафованными атомами всё равно записываются (наблюдатель)');
    }
}
