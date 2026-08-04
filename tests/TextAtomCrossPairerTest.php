<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\TextAtomCrossPairer;

/**
 * Story E1-FIX Phase 3: Cross-pair wire.
 *
 * TextAtomCrossPairer превращает одиночные значения текстовых атомов
 * в X/y пары для CV→0.
 *
 * S2.7: crossPair() returns \Generator — tests wrap in iterator_to_array().
 */
class TextAtomCrossPairerTest extends TestCase
{
    /**
     * Cross-pair из двух текстовых атомов → задача с 2-column данными.
     */
    public function testCrossPairCreatesXyPairs(): void
    {
        $atoms = [
            'preg_match(GI)' => [7.2, 6.8, 7.5],
            'preg_match(DQ)' => [6.0, 5.5, 6.2],
        ];

        $tasks = iterator_to_array(
            TextAtomCrossPairer::crossPair($atoms, 'text_pairs'),
            false
        );

        $this->assertNotEmpty($tasks, 'Must create cross-pair tasks');
        // Должны быть задачи GI→DQ и DQ→GI
        $names = array_column($tasks, 'name');
        $this->assertContains('txt_pair_preg_match(GI)_to_preg_match(DQ)', $names);
        $this->assertContains('txt_pair_preg_match(DQ)_to_preg_match(GI)', $names);

        // Проверить формат: [feature, target]
        foreach ($tasks as $task) {
            $this->assertArrayHasKey('data', $task);
            $this->assertNotEmpty($task['data']);
            $this->assertCount(2, $task['data'][0], 'Must be [feature, target] pairs');
        }
    }

    /**
     * Меньше 2 атомов → пустой результат.
     */
    public function testCrossPairRequiresTwoAtoms(): void
    {
        $tasks = iterator_to_array(
            TextAtomCrossPairer::crossPair(
                ['preg_match(GI)' => [7.2, 6.8]],
                'text_pairs'
            ),
            false
        );

        $this->assertEmpty($tasks, 'Need ≥2 atoms for cross-pairing');
    }

    /**
     * Меньше 3 точек → пустой результат.
     */
    public function testCrossPairRequiresMinRows(): void
    {
        $atoms = [
            'preg_match(GI)' => [7.2],
            'preg_match(DQ)' => [6.0],
        ];

        $tasks = iterator_to_array(
            TextAtomCrossPairer::crossPair($atoms, 'text_pairs'),
            false
        );

        $this->assertEmpty($tasks, 'Need ≥3 aligned rows');
    }

    /**
     * Cross-pair задачи имеют domain из параметра.
     */
    public function testCrossPairPreservesDomain(): void
    {
        $atoms = [
            'preg_match(GI)' => [7.2, 6.8, 7.5],
            'preg_match(DQ)' => [6.0, 5.5, 6.2],
        ];

        $tasks = iterator_to_array(
            TextAtomCrossPairer::crossPair($atoms, 'obsidian_metrics'),
            false
        );

        foreach ($tasks as $task) {
            $this->assertSame('obsidian_metrics', $task['domain']);
        }
    }
}
