<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\TaskRouter;
use BeeSwarm\Hive\Bee;

/**
 * Story S1.4-FRONTIER: Frontier Priority Booster
 *
 * Задачи с best_CV ∈ [0.01, 0.10] получают +0.3 к весу.
 * «Зона ближайшего развития»: почти решённые задачи.
 */
class FrontierPriorityTest extends TestCase
{
    /** Задача с best_CV в frontier-диапазоне получает бонус */
    public function testFrontierTaskGetsBonus(): void
    {
        $bees = [new Bee(['add', 'mul'], 10.0)];
        $router = new TaskRouter($bees, 0);

        $task = ['name' => 'FRONTIER_TEST', 'data' => [[1,2,3], [3,4,7], [5,6,11]],
                 'best_cv' => 0.05];

        $weight = $router->computeWeight($task, $bees[0]);
        // Базовый вес = (wins+1)/(total+1) = 0.5 для новой пчелы
        // + frontier_bonus 0.3
        $this->assertGreaterThan(0.5, $weight, 'Frontier task must get weight boost');
    }

    /** Задача с best_CV вне диапазона не получает бонус */
    public function testNonFrontierTaskNoBonus(): void
    {
        $bees = [new Bee(['add', 'mul'], 10.0)];
        $router = new TaskRouter($bees, 0);

        $frontierTask = ['name' => 'F', 'data' => [[1,2,3]], 'best_cv' => 0.05];
        $normalTask = ['name' => 'N', 'data' => [[1,2,3]], 'best_cv' => 0.5];

        $frontierWeight = $router->computeWeight($frontierTask, $bees[0]);
        $normalWeight = $router->computeWeight($normalTask, $bees[0]);

        // Frontier должен быть строго больше (base + 0.3)
        $this->assertGreaterThan($normalWeight, $frontierWeight, 'Frontier must have higher weight');
    }
}
