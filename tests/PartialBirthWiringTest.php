<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * §2.5.2 wiring (story DISSIPATION-LOOP, недостающий вызов partialBirth):
 * Grammar Ceiling Break в живом пути.
 *
 * Контракт: Search::find вернул ЛУЧШУЮ формулу (sFormula), но задача FAILED
 * (диагноз GRAMMAR/DEPTH, кандидаты не приняты) → Hive::partialBirth
 * пытается родить B-атом из частичной гипотезы → PARTIAL-BIRTH лог →
 * следующий поиск может скомпоновать. Гейты partialBirth (терминалы ≥2,
 * cv < 0.5, компрессия, голод линии) фильтруют мусор.
 *
 * Staleness-чек 05.09: partialBirth (Hive:721) НИКЕМ не вызывался —
 * dead code в живом пути. Этот тест фиксирует контракт живого вызова.
 */
final class PartialBirthWiringTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'pb_wire_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    private function invokeWiring(array $X, array $y, string $taskName, float $cv, string $diagnosis, ?string $formula = '(x0addx1)'): void
    {
        $m = new \ReflectionMethod(Hive::class, 'runPartialBirthAttempt');
        $m->setAccessible(true);
        $m->invoke($this->hive, $X, $y, ['name' => $taskName, 'domain' => 'test_pb'], $cv, $diagnosis, $formula);
    }

    /** RED: частичная гипотеза при голодной линии + diagnosis GRAMMAR → PARTIAL-BIRTH. */
    public function testPartialBirthEmittedOnFailedTaskWithFormula(): void
    {
        // Голод линии (гейт 4): lineageProgress stale > 0
        $p = new \ReflectionProperty(Hive::class, 'lineageProgress');
        $p->setAccessible(true);
        $p->setValue($this->hive, ['lineage_0' => 3]);

        // Частичная гипотеза: 2 терминала, короткая, cv < 0.5 (все гейты пройдены)
        $X = [[1.0], [2.0], [3.0], [4.0]];
        $y = [2.0, 4.0, 6.0, 8.0];

        $this->invokeWiring($X, $y, 'pb1', 0.35, 'GRAMMAR');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString(
            'PARTIAL-BIRTH',
            $log,
            'частичная гипотеза при отказе и голодной линии обязана родить B-атом'
        );

        // атом зарегистрирован в grammar_ops
        $n = Database::get()->query(
            "SELECT COUNT(*) FROM grammar_ops WHERE source = 'birth'"
        )->fetchColumn();
        self::assertSame(1, (int) $n, 'рождённый атом попадает в grammar_ops как candidate');
    }

    /** RED: точная формула (n=2, cv=0) → рождение происходит (гейт cv<0.5, не exact-исключение). */
    public function testGatesFilterGarbage(): void
    {
        $p = new \ReflectionProperty(Hive::class, 'lineageProgress');
        $p->setAccessible(true);
        $p->setValue($this->hive, ['lineage_0' => 1]);

        // cv=0.9 → гейт 2 (cv >= 0.5 → false)
        $this->invokeWiring([[1.0], [2.0]], [1.0, 2.0], 'pb2', 0.9, 'GRAMMAR');
        $log = (string) file_get_contents($this->logFile);
        self::assertStringNotContainsString('PARTIAL-BIRTH', $log, 'cv >= 0.5 → отказ');

        // 1 терминал → гейт 1 (формула с одним xN — нетривиальность не пройдена)
        $this->invokeWiring([[1.0], [2.0]], [1.0, 2.0], 'pb3', 0.3, 'GRAMMAR', '(x0)');
        $log = (string) file_get_contents($this->logFile);
        $before = substr_count($log, 'PARTIAL-BIRTH');
        self::assertSame(0, $before, '1 терминал → нетривиальность не пройдена');

        // сытая линия (stale=0) → гейт 4
        $p2 = new \ReflectionProperty(Hive::class, 'lineageProgress');
        $p2->setAccessible(true);
        $p2->setValue($this->hive, ['lineage_0' => 0]);
        $this->invokeWiring([[1.0], [2.0], [3.0], [4.0]], [2.0, 4.0, 6.0, 8.0], 'pb4', 0.3, 'GRAMMAR');
        $log = (string) file_get_contents($this->logFile);
        self::assertSame(0, substr_count($log, 'PARTIAL-BIRTH'), 'сытая линия не рождает');
    }

    /** RED: атом с повторным открытием → RCB PROMOTED (двухфазность). */
    public function testBirthAtomIsCandidateNotActive(): void
    {
        $p = new \ReflectionProperty(Hive::class, 'lineageProgress');
        $p->setAccessible(true);
        $p->setValue($this->hive, ['lineage_0' => 2]);

        $this->invokeWiring([[1.0], [2.0], [3.0], [4.0]], [2.0, 4.0, 6.0, 8.0], 'pb5', 0.3, 'GRAMMAR');

        $row = Database::get()->query(
            "SELECT status FROM grammar_ops WHERE source = 'birth' LIMIT 1"
        )->fetchColumn();
        self::assertSame('candidate', $row, 'RCB: рождение = candidate, активация после reuse≥1');
    }
}
