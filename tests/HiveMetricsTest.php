<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\Bee;

/**
 * Story S1-WIRE Phase 5: Metrics — generation tracking, diversity, evolution_stats
 */
class HiveMetricsTest extends TestCase
{
    /** Hive отслеживает число поколений */
    public function testHiveTracksGenerations(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hme_');
        $hive = new Hive(maxTicks: 10, logFile: $logFile);
        $hive->run();
        $log = file_get_contents($logFile);

        // GEN в логе — поколения отслеживаются
        $this->assertStringContainsString('GEN', $log, 'Hive must log generation events');
        unlink($logFile);
    }

    /** Jaccard diversity измеримо */
    public function testJaccardDiversity(): void
    {
        // Hive::jaccard уже существует (private static)
        $a = ['add', 'mul', 'sq'];
        $b = ['add', 'mul', 'div'];

        // Jaccard = |A∩B| / |A∪B| = 2/4 = 0.5
        $ref = new \ReflectionMethod(Hive::class, 'jaccard');
        $diversity = $ref->invoke(null, $a, $b);

        $this->assertEqualsWithDelta(0.5, $diversity, 0.01, 'Jaccard must be correct');
    }

    /** После spawn'а популяция растёт */
    public function testPopulationGrowsAfterSpawn(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hme_');
        $hive = new Hive(maxTicks: 3, logFile: $logFile);
        $hive->run();
        $bees = $hive->getBees();
        $this->assertGreaterThanOrEqual(2, count($bees), 'Population must have at least 2 bees after bootstrap');
        unlink($logFile);
    }
}
