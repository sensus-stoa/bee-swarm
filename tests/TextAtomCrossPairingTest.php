<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

/**
 * Story E1-FIX: Text atom cross-pairing
 *
 * Single-column text atom data → X/y pairs for CV→0.
 * Without this, foraged tasks fail tMin=10 (only 1 column = no features).
 *
 * S2.7: crossPair() returns \Generator — tests wrap in iterator_to_array().
 */
class TextAtomCrossPairingTest extends TestCase
{
    /** Одиночные значения метрик превращаются в X/y пары */
    public function testSingleValuesBecomeXY(): void
    {
        // Симулируем данные из одного файла: GI=7.2, DQ=6.0, Sleep=5
        $atoms = [
            'GI' => [7.2, 8.1, 6.5, 7.0],
            'DQ' => [6.0, 5.5, 7.0, 5.8],
            'Sleep' => [5, 6, 4, 5],
        ];

        $tasks = iterator_to_array(
            \BeeSwarm\Core\TextAtomCrossPairer::crossPair($atoms, 'test_metrics'),
            false
        );

        // Должно быть 6 задач (3×2 перестановок)
        $this->assertCount(6, $tasks);

        // Каждая задача должна иметь data с [feature_value, target_value]
        foreach ($tasks as $task) {
            $this->assertArrayHasKey('data', $task);
            $this->assertGreaterThanOrEqual(3, count($task['data']), 'Each task needs ≥3 rows for tMin');
            foreach ($task['data'] as $row) {
                $this->assertCount(2, $row, 'Each row must be [feature, target]');
            }
        }
    }

    /** <3 точек данных → задача не создаётся */
    public function testMinimumThreeRowsRequired(): void
    {
        $atoms = [
            'GI' => [7.2, 8.1],  // только 2 точки
            'DQ' => [6.0, 5.5],
        ];

        $tasks = iterator_to_array(
            \BeeSwarm\Core\TextAtomCrossPairer::crossPair($atoms, 'test'),
            false
        );

        $this->assertEmpty($tasks, 'Less than 3 data points → no tasks');
    }

    /** Таски должны иметь t ≥ tMin=10 для прохождения sufficiency */
    public function testTasksHaveSufficientData(): void
    {
        $atoms = [
            'GI' => array_fill(0, 15, 7.0),
            'DQ' => array_fill(0, 15, 6.0),
        ];

        $tasks = iterator_to_array(
            \BeeSwarm\Core\TextAtomCrossPairer::crossPair($atoms, 'test'),
            false
        );

        $this->assertCount(2, $tasks); // GI→DQ, DQ→GI
        $this->assertGreaterThanOrEqual(10, count($tasks[0]['data']));
    }
}
