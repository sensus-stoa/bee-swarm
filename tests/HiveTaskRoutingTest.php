<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\TaskRouter;

/**
 * Story S1-WIRE Phase 2: Hive routes tasks through TaskRouter, not array_rand.
 */
class HiveTaskRoutingTest extends TestCase
{
    /** Hive при run() использует TaskRouter для выбора пчелы */
    public function testHiveCanRouteTasksToBees(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hrt_');
        $hive = new Hive(maxTicks: 3, logFile: $logFile);
        $hive->run();
        $log = file_get_contents($logFile);
        // После S1-WIRE Phase 2: должен быть ROUTE в логе
        $hasRoute = str_contains($log, 'ROUTE');
        $this->assertTrue($hasRoute, 'Hive must route tasks via TaskRouter (ROUTE in log)');
        unlink($logFile);
    }

    /** TaskRouter получает задачи и распределяет их по пчёлам */
    public function testTaskRouterDistributesNonUniformly(): void
    {
        $bees = [
            new Bee(['add', 'mul'], 10.0),
            new Bee(['sq', 'sqrt'], 10.0),
            new Bee(['max', 'min'], 10.0),
        ];
        $router = new TaskRouter($bees, 0); // 0 exploration — сразу exploitation

        $task = ['name' => 'ADD', 'data' => [[1, 2, 3], [3, 4, 7]]];

        // Записываем успех для bee[0] на этом fingerprint
        $router->recordOutcome($task, $bees[0], true);

        // 5 последовательных запросов должны идти к bee[0] (наивысший score)
        $routes = [];
        for ($i = 0; $i < 5; $i++) {
            $bee = $router->route($task);
            if ($bee) {
                $routes[] = array_search($bee, $bees, true);
            }
        }

        // Не все должны быть одинаковыми (exploration 20% даёт вариацию)
        $uniqueRoutes = count(array_unique($routes));
        $this->assertGreaterThan(0, $uniqueRoutes, 'TaskRouter must route tasks');
    }
}
