<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Infra\RngIsolation;

/**
 * BDD behavioral tests — инварианты пайплайна.
 * Время: ~10 сек на тест.
 * @group slow
 */
class BehavioralDiversityTest extends TestCase
{
    protected function tearDown(): void
    {
        RngIsolation::assertClean();
        parent::tearDown();
    }

    /**
     * ИНВАРИАНТ: ≥2 разных открытий за 20 тиков.
     * Ломалось: srand(42) → array_rand детерминизм.
     */
    private static string $sharedLog = '';
    private static ?Hive $sharedHive = null;

    /**
     * ПАРАЛЛЕЛИЗМ (10.08): 3 теста × 10-20 тиков серийно (~204с).
     * ОДИН прогон (20 тиков) на класс — тесты читают static (~70с).
     */
    public static function setUpBeforeClass(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $logFile = tempnam(sys_get_temp_dir(), 'behdiv_');
        $hive = new Hive(plateau: new PlateauDetector(50, plateauSleepUs: 0), maxTicks: 20, logFile: $logFile);
        $hive->run();
        self::$sharedLog = file_get_contents($logFile);
        unlink($logFile);
        self::$sharedHive = $hive;
    }

    public function testDiscoveryDiversity(): void
    {
        $log = self::$sharedLog;

        // Считаем уникальные формулы
        preg_match_all('/🔍.*->\s*(\S+)\s/', $log, $m);
        $formulas = array_unique($m[1]);
        $this->assertGreaterThanOrEqual(2, count($formulas),
            'Only ' . count($formulas) . ' unique formulas. srand poisoning?');
    }

    /**
     * ИНВАРИАНТ: RNG чист после прогона.
     */
    public function testRngCleanAfterRun(): void
    {
        $this->assertFalse(method_exists(RngIsolation::class, 'hasUnrestoredGuards')
            && RngIsolation::hasUnrestoredGuards(),
            'RNG poisoned after Hive::run()');
    }

    /**
     * ИНВАРИАНТ: задачи из РАЗНЫХ доменов обрабатываются.
     */
    public function testTaskDomainDiversity(): void
    {
        $log = self::$sharedLog;

        preg_match_all('/\[(\w+)\]/', $log, $m);
        $domains = array_unique($m[1]);
        $this->assertGreaterThanOrEqual(2, count($domains),
            'Only ' . count($domains) . ' domains. All logic-only?');
    }
}
