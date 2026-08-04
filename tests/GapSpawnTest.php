<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Story S1.2 Phase 4: Gap-Triggered Spawn
 */
class GapSpawnTest extends TestCase
{
    /**
     * RED → GREEN: PLATEAU > 5×P → GAP_SPAWN.
     */
    public function testGapSpawnOnPlateau(): void
    {
        $plateau = new PlateauDetector(2, plateauSleepUs: 0);
        $logFile = tempnam(sys_get_temp_dir(), 'gapspawn_');
        $hive = new Hive(plateau: $plateau, maxTicks: 25, logFile: $logFile);
        $hive->run();

        $log = file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('GAP_SPAWN', $log,
            'PLATEAU > 20 ticks must trigger GAP_SPAWN');
    }

    /**
     * E=0 → нет gap-spawn.
     */
    public function testNoGapSpawnWhenAllDead(): void
    {
        $plateau = new PlateauDetector(1, plateauSleepUs: 0);
        $logFile = tempnam(sys_get_temp_dir(), 'gapspawn_dead_');
        $hive = new Hive(plateau: $plateau, maxTicks: 30, logFile: $logFile);
        $hive->run();

        $log = file_get_contents($logFile);
        unlink($logFile);

        $lines = explode("\n", $log);
        $lastDeath = 0;
        $gapSpawnAfterDeath = false;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'DEATH:')) {
                $lastDeath = $i;
            }
            if (str_contains($line, 'GAP_SPAWN') && $i > $lastDeath && $lastDeath > 0) {
                $gapSpawnAfterDeath = true;
            }
        }

        $this->assertFalse($gapSpawnAfterDeath,
            'GAP_SPAWN must not occur after all bees are dead');
    }
}
