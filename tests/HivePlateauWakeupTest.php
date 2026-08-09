<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * CONCERNS A (05.08): consumeTask уменьшал пул почти каждый тик →
 * «Wakeup on new task count» (!==) будил плато бесконечно → GAP_SPAWN мёртв.
 * Фикс: wakeup ТОЛЬКО на рост (>), потребление плато не будит.
 */
class HivePlateauWakeupTest extends TestCase
{
    public function testConsumeDoesNotResetPlateau(): void
    {
        // SEARCH_DEPTH=2: тест про плато-механику, не про глубину —
        // depth=3 даёт больше находок → foundAny сбрасывает плато (флак)
        putenv('SEARCH_DEPTH=2');
        $plateau = new PlateauDetector(50, 0);

        // Forager, который каждый скан даёт 10 задач — но hasNewContent=false
        $forager = new class() extends Forager {
            public function __construct()
            {
                parent::__construct();
            }

            public function scanWithAccumulator(array $dirs): array
            {
                $tasks = [];
                for ($i = 0; $i < 10; $i++) {
                    $tasks[] = [
                        'name' => "foraged_num_test_{$i}_c0c1",
                        'data' => [[1, 2], [2, 4], [3, 6]],
                        'domain' => 'test',
                        'source_path' => '/tmp/test.csv',
                        'col_labels' => ['x', 'y'],
                    ];
                }
                return $tasks;
            }

            public function hasNewContent(): bool
            {
                return false; // нет новой пищи — только существующие задачи
            }
        };

        $logFile = tempnam(sys_get_temp_dir(), 'wake_');
        try {
            $hive = new Hive($plateau, $forager, maxTicks: 30, logFile: $logFile);
            $hive->run();

            // За 30 тиков потребление удалило ~30 задач из пула, но НИ ОДИН
            // wakeup от count-изменения не сработал (только РОСТ будит).
            // Плато должно накопиться (нет foundAny, нет wakeup-источников).
            $this->assertGreaterThan(
                0,
                $plateau->getConsecutiveNoDiscovery(),
                'Consumption must NOT reset plateau — consecutiveNoDiscovery must grow'
            );
        } finally {
            putenv('SEARCH_DEPTH'); // cleanup при падении (CONCERNS deleg_84b58ba4)
            unlink($logFile);
        }
    }
}
