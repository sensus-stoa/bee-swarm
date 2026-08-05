<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Проверка: выборка задач колеблется каждый тик (5→7→6→8...).
 * Wakeup на count($tasks) — если срабатывает при ЛЮБОМ росте выборки,
 * плато не наберёт 500 тиков даже после growth-only фикса.
 */
class HivePlateauWakeupOscillationTest extends TestCase
{
    public function testOscillatingTaskCountDoesNotWakePlateau(): void
    {
        $plateau = new PlateauDetector(50, 0);

        // Forager: каждый скан даёт 15 задач (пул растёт)
        $forager = new class() extends Forager {
            private int $scan = 0;

            public function __construct()
            {
                parent::__construct();
            }

            public function scanWithAccumulator(array $dirs): array
            {
                $this->scan++;
                $tasks = [];
                for ($i = 0; $i < 15; $i++) {
                    $tasks[] = [
                        'name' => "foraged_osc_{$this->scan}_{$i}_c0c1",
                        'data' => [[1, 2], [2, 4], [3, 6]],
                        'domain' => 'test',
                        'source_path' => '/tmp/osc.csv',
                        'col_labels' => ['x', 'y'],
                    ];
                }
                return $tasks;
            }

            public function hasNewContent(): bool
            {
                return false;
            }
        };

        $logFile = tempnam(sys_get_temp_dir(), 'osc_');
        $hive = new Hive($plateau, $forager, maxTicks: 220, logFile: $logFile);
        $hive->run();

        // 220 тиков: ~2 скана (100, 200), пул рос. Если плато НЕ набралось
        // хотя бы до 20 — wakeup на росте выборки продолжает мешать.
        $consecutive = $plateau->getConsecutiveNoDiscovery();
        echo "consecutiveNoDiscovery after 220 ticks: {$consecutive}\n";
        $this->assertGreaterThan(
            20,
            $consecutive,
            'Oscillating task count must not reset plateau continuously'
        );
        unlink($logFile);
    }
}
