<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;

/**
 * Story E1-FIX Phase 4b: Task Priority by Column Count
 *
 * filterInsufficient() должен сортировать задачи: узкие (мало колонок) → первые.
 */
class TaskPriorityByColumnCountTest extends TestCase
{
    /**
     * RED: Задачи сортируются по nFeat ASC.
     * Узкие (2 колонки) → раньше широких (5 колонок).
     *
     * Predicted: Assertion failure — порядок не отсортирован.
     */
    public function testTasksSortedByColumnCount(): void
    {
        $hive = new Hive(maxTicks: 0);

        // Задачи с разным числом колонок в случайном порядке
        $tasks = [
            [
                'name' => 'wide_task',
                'data' => array_fill(0, 30, [1.0, 2.0, 3.0, 4.0, 5.0, 6.0]), // 6 cols → 5 feat, tMin=25
                'domain' => 'test',
            ],
            [
                'name' => 'narrow_task',
                'data' => array_fill(0, 15, [1.0, 2.0]), // 2 cols → 1 feat
                'domain' => 'test',
            ],
            [
                'name' => 'medium_task',
                'data' => array_fill(0, 20, [1.0, 2.0, 3.0, 4.0]), // 4 cols → 3 feat
                'domain' => 'test',
            ],
            [
                'name' => 'text_task',
                // без data — semantic/text
                'domain' => 'test',
            ],
        ];

        // Вызываем приватный метод через рефлексию
        $ref = new \ReflectionMethod(Hive::class, 'filterInsufficient');
        $filtered = $ref->invoke($hive, $tasks);

        $this->assertCount(4, $filtered, 'All tasks should pass (sufficient data)');

        // Проверяем порядок: nFeat ASC
        $featCounts = array_map(function (array $t): int {
            return isset($t['data'][0]) && is_array($t['data'][0])
                ? count($t['data'][0]) - 1
                : 999;
        }, $filtered);

        // Узкие первые
        $this->assertEquals(1, $featCounts[0], 'First task must be narrow (1 feature)');
        $this->assertEquals(3, $featCounts[1], 'Second task must be medium (3 features)');
        $this->assertEquals(5, $featCounts[2], 'Third task must be wide (5 features)');
        $this->assertEquals(999, $featCounts[3], 'Last task must be text (999)');
    }
}
