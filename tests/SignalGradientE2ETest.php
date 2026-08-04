<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * E2E: Signal Gradient Reward — интеграционный тест.
 *
 * Запускает мини-улей на реальных метриках и проверяет что SIGNAL логируются.
 */
class SignalGradientE2ETest extends TestCase
{
    /**
     * E2E: улей на Journal → SIGNAL в логах.
     *
     * Predicted: 0 SIGNAL — двойной Search::find не работает.
     */
    public function testSignalAppearsInProductionLog(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'signal_e2e_');
        $plateau = new PlateauDetector(50, plateauSleepUs: 0);

        // Запускаем на 20 тиков — должен успеть обработать метрики
        $hive = new Hive(plateau: $plateau, maxTicks: 20, logFile: $logFile);
        $hive->run();

        $log = file_get_contents($logFile);
        unlink($logFile);

        // Проверяем что SIGNAL появился (CV метрик между ε и null_floor)
        $signalCount = substr_count($log, 'SIGNAL:');
        $this->assertGreaterThan(
            0,
            $signalCount,
            "Expected ≥1 SIGNAL from metrics data. Got {$signalCount}. Check cvThreshold vs null_floor."
        );
    }
}
