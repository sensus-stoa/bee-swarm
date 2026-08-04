<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * E2E: Foraged pipeline — метрики дают открытия.
 *
 * С сильными фикстурами (R²~0.85) NullCalibrator поднимает epsilon
 * и foraged-задачи производят НАСТОЯЩИЕ открытия (не сигнал).
 */
class SignalGradientE2ETest extends TestCase
{
    public function testForagedMetricsProduceDiscoveries(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');

        $logFile = tempnam(sys_get_temp_dir(), 'foraged_e2e_');
        $plateau = new PlateauDetector(50, plateauSleepUs: 0);
        $hive = new Hive(plateau: $plateau, maxTicks: 25, logFile: $logFile);
        $hive->run();

        $log = file_get_contents($logFile);
        unlink($logFile);

        // Должны быть открытия из foraged-домена
        $foragedDiscoveries = substr_count($log, '[foraged]');
        $this->assertGreaterThan(0, $foragedDiscoveries,
            "Expected ≥1 foraged discovery. Got {$foragedDiscoveries}. Pipeline broken?");
    }
}
