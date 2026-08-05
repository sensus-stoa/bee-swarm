<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;

/**
 * MEMORY-GUARD-DRATIO Ф2 (аудит 05.08 §2.5.8): телеметрия D_ratio
 * скользящим окном. Каждые N тиков: D_RATIO: win=N D=0.32 pop=8
 * Коридор §2.5.8: D ∈ [0.1, 0.5] здоровье; <0.1 кристалл; >0.5 хаос.
 */
class HiveDRatioTelemetryTest extends TestCase
{
    public function testDRatioLoggedAtInterval(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'dratio_');
        $hive = new Hive(
            plateau: new \BeeSwarm\Infra\PlateauDetector(50, 0),
            maxTicks: 5,
            logFile: $logFile,
        );
        $hive->setDRatioInterval(2);
        $hive->run();

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('D_RATIO', $log, 'D_RATIO must be logged at interval');
        // Формат: win=2 D=... pop=3
        $this->assertMatchesRegularExpression(
            '/D_RATIO: win=2 D=\d+\.\d+ pop=\d+/',
            $log,
            'D_RATIO format: win, D, pop'
        );
    }
}
