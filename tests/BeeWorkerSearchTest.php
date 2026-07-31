<?php
declare(strict_types=1);


namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\BeeWorker;

/**
 * Story S1-WIRE Phase 1: BeeWorker вызывает реальный Search::find
 */
class BeeWorkerSearchTest extends TestCase
{
    /** handleTask с валидными данными вызывает Search::find и возвращает результат */
    public function testHandleTaskSearchesForLaw(): void
    {
        $bee = new Bee(['add', 'mul', 'sq', 'sqrt', 'max', 'min', 'sub', 'div']);
        $worker = new BeeWorker($bee);

        // Задача: y = x0 + x1 (ADD)
        $task = json_encode([
            'name' => 'ADD',
            'data' => [[1, 2, 3], [3, 4, 7], [5, 6, 11], [7, 8, 15], [9, 10, 19],
                       [2, 5, 7], [4, 1, 5], [6, 3, 9], [8, 7, 15], [10, 0, 10]],
        ]);

        $result = $worker->handleTask($task);

        $this->assertTrue($result['accepted'], 'Task must be accepted');
        $this->assertArrayHasKey('discovery', $result, 'Result must include discovery');
    }

    /** handleTask списывает энергию за поиск (даже если закон найден — chargeSearch до reward) */
    public function testHandleTaskChangesEnergy(): void
    {
        $bee = new Bee(['add']);
        $worker = new BeeWorker($bee);
        $energyBefore = $bee->energy();

        $task = json_encode([
            'name' => 'ADD',
            'data' => [[1, 2, 3], [3, 4, 7], [5, 6, 11]],
        ]);

        $worker->handleTask($task);
        // Энергия изменилась (chargeSearch −0.1, возможно rewardDiscovery +2.0)
        $this->assertNotEquals($energyBefore, $bee->energy(), 'Energy must change after search');
    }
}
