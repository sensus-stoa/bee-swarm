<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\TaskGenerator;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Infra\RngIsolation;

/**
 * BDD-style behavioral tests: проверяет ИНВАРИАНТЫ системы, а не unit-функции.
 *
 * Каждый тест запускает ПОЛНЫЙ tick loop и проверяет поведение всей системы.
 * Это не unit-тесты — они ловят нелокальные side effects, state leakage,
 * и RNG poisoning которые невидимы в изоляции.
 *
 * Время: ~10-15 секунд весь класс. Запускать в CI/gate.sh, не в каждом коммите.
 */
class BehavioralDiversityTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = tempnam(sys_get_temp_dir(), 'behd_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        // GUARD: каждый тест должен оставлять RNG чистым
        RngIsolation::assertClean();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════
    // ИНВАРИАНТ 1: Diversity — улей делает разНЫЕ открытия
    // ═══════════════════════════════════════════════════════════

    /**
     * ИНВАРИАНТ: за 50 тиков ≥ 2 РАЗНЫХ открытия.
     *
     * Ломается при: srand(42) без restore → array_rand всегда выбирает
     * один и тот же task → один и тот же закон → 1 discovery за всё время.
     *
     * Это ЯВНЫЙ backstop против RNG poisoning.
     */
    public function testDiscoveriesAreDiverse(): void
    {
        $hive = $this->runHive(50);

        $laws = \BeeSwarm\Infra\Database::get()->query(
            "SELECT COUNT(DISTINCT formula) FROM laws"
        )->fetchColumn();

        $this->assertGreaterThanOrEqual(2, (int) $laws,
            "CRITICAL: Only " . ((int) $laws) . " distinct laws after 50 ticks. " .
            "Possible RNG poisoning — array_rand always picks same task. " .
            "Check: srand() called without restore in getTasks() or createComposeTasks()."
        );
    }

    /**
     * ИНВАРИАНТ: разнообразие задач — за 50 тиков посещается ≥ 3 разных задач.
     *
     * Ломается при: array_rand всегда даёт один индекс → всегда одна задача.
     */
    public function testTaskDiversity(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'taskdiv_');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 50,
            logFile: $logFile,
        );
        $hive->run();

        $log = file_exists($logFile) ? file_get_contents($logFile) : '';
        unlink($logFile);

        // Считаем уникальные имена задач из лога
        preg_match_all('/-> (.+?) \(/', $log, $matches);
        $taskNames = array_unique($matches[1] ?? []);

        $this->assertGreaterThanOrEqual(3, count($taskNames),
            'Only ' . count($taskNames) . ' distinct tasks visited. RNG poisoning? ' .
            'Tasks: ' . implode(', ', array_slice($taskNames, 0, 10))
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ИНВАРИАНТ 2: RNG State Leakage
    // ═══════════════════════════════════════════════════════════

    /**
     * ИНВАРИАНТ: после Hive::run() RNG НЕ заражён.
     *
     * array_rand() на массиве из 1000 элементов должен давать разНЫЕ значения
     * на последовательных вызовах.
     */
    public function testRngNotPoisonedAfterFullRun(): void
    {
        $this->runHive(20);

        $this->assertFalse(
            RngIsolation::hasUnrestoredGuards(),
            'Unrestored RNG guards after Hive::run(). ' .
            'srand(N) was called without restore() somewhere in the tick loop.'
        );
    }

    /**
     * ИНВАРИАНТ: TaskGenerator::createComposeTasks() восстанавливает RNG.
     *
     * Проверяем напрямую — после вызова createComposeTasks RNG должен быть случайным.
     */
    public function testCreateComposeTasksRestoresRng(): void
    {
        $gen = new TaskGenerator();

        // Вызываем 3 раза — каждый вызов GEN_ должен быть одинаковым (srand(42)),
        // но ПОСЛЕ каждого вызова RNG должен быть случайным
        for ($i = 0; $i < 3; $i++) {
            $gen->createComposeTasks();

            $this->assertFalse(
                RngIsolation::hasUnrestoredGuards(),
                "RNG guards unrestored after createComposeTasks() call #{$i}. " .
                "srand(42) was NOT restored."
            );
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ИНВАРИАНТ 3: System Health
    // ═══════════════════════════════════════════════════════════

    /**
     * ИНВАРИАНТ: улей не падает и возвращает tick count.
     */
    public function testHiveCompletesWithoutError(): void
    {
        $hive = $this->runHive(30);
        $bees = $hive->getBees();

        $this->assertNotEmpty($bees, 'No bees after run — bootstrap failed?');
        $alive = count(array_filter($bees, fn ($b) => $b->isAlive()));
        $this->assertGreaterThan(0, $alive, 'All bees dead — starvation loop?');
    }

    /**
     * ИНВАРИАНТ: логи содержат SIGNAL маркер (cv-метрики работают).
     *
     * Ломается при: bestCv не возвращается из DiscoveryEngine, или
     * null-калибровка ломает порог.
     */
    public function testSignalMetricProduced(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'sig_');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 50,
            logFile: $logFile,
        );
        $hive->run();

        $log = file_exists($logFile) ? file_get_contents($logFile) : '';
        unlink($logFile);

        $signalCount = substr_count($log, 'SIGNAL:');
        $discoveryCount = substr_count($log, '🔍');

        // At least one of SIGNAL or discovery should happen
        $this->assertTrue(
            $signalCount > 0 || $discoveryCount > 0,
            "Neither SIGNAL nor discoveries after 50 ticks. " .
            "Pipeline completely broken? SIGNAL={$signalCount} DISC={$discoveryCount}"
        );
    }

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════

    private function runHive(int $maxTicks): Hive
    {
        $logFile = tempnam(sys_get_temp_dir(), 'behd_');
        $plateau = new PlateauDetector(50, plateauSleepUs: 0);
        $hive = new Hive(plateau: $plateau, maxTicks: $maxTicks, logFile: $logFile);
        $hive->run();
        unlink($logFile);
        return $hive;
    }
}
