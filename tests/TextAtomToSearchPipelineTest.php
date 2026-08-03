<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\TextAtomCrossPairer;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * End-to-end тест: текст-атомы → cross-pair → Search::find.
 *
 * Верифицирует, что текст из .md файлов Обсидиана
 * доходит до Search::find и порождает законы.
 */
class TextAtomToSearchPipelineTest extends TestCase
{
    /**
     * Cross-pair → DiscoveryEngine → Search::find: полный пайплайн.
     *
     * Симулирует 3 текстовых атома из 50 .md файлов,
     * cross-pair создаёт пары, DiscoveryEngine ищет законы.
     */
    public function testCrossPairFlowsToSearchFind(): void
    {
        // Шаг 1: симулируем foraged_txt_* атомы (как если бы Forager
        // просканировал 50 .md файлов и извлёк 3 текстовых паттерна)
        $txtTasks = [
            'foraged_txt_aaa' => [
                'name' => 'foraged_txt_aaa',
                'data' => array_map(fn ($v) => [(float) $v], range(10, 59)),
                'domain' => 'foraged',
            ],
            'foraged_txt_bbb' => [
                'name' => 'foraged_txt_bbb',
                'data' => array_map(fn ($v) => [(float) $v], [
                    20, 20, 20, 20, 20, 21, 21, 21, 21, 21,
                    30, 30, 30, 30, 30, 31, 31, 31, 31, 31,
                    40, 40, 40, 40, 40, 41, 41, 41, 41, 41,
                    50, 50, 50, 50, 50, 51, 51, 51, 51, 51,
                    60, 60, 60, 60, 60, 61, 61, 61, 61, 61,
                ]),
                'domain' => 'foraged',
            ],
            'foraged_txt_ccc' => [
                'name' => 'foraged_txt_ccc',
                'data' => array_map(fn ($v) => [(float) $v], [
                    5, 5, 5, 5, 5, 5, 5, 5, 5, 5,
                    10, 10, 10, 10, 10, 10, 10, 10, 10, 10,
                    15, 15, 15, 15, 15, 15, 15, 15, 15, 15,
                    20, 20, 20, 20, 20, 20, 20, 20, 20, 20,
                    25, 25, 25, 25, 25, 25, 25, 25, 25, 25,
                ]),
                'domain' => 'foraged',
            ],
        ];

        // Шаг 2: Cross-pair — создаёт txt_pair_X_to_Y задачи
        $atoms = [];
        foreach ($txtTasks as $name => $t) {
            foreach ($t['data'] as $row) {
                $atoms[$name][] = (float) $row[0];
            }
        }

        $crossTasks = TextAtomCrossPairer::crossPair($atoms, 'text_pairs');
        $this->assertNotEmpty($crossTasks, 'Cross-pair must create tasks from 3 text atoms');

        // Шаг 3: каждая cross-pair задача → DiscoveryEngine → Search::find
        $engine = new DiscoveryEngine();
        $anyLawFound = false;

        foreach ($crossTasks as $task) {
            $data = $task['data'];
            $n = count($data);
            if ($n < 10) continue;

            $X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
            $y = array_column($data, count($data[0]) - 1);

            $results = $engine->discover(
                $X, $y,
                array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'min', 'max', 'abs', 'neg', 'sq', 'sqrt']),
                0.5,
                ['feature', 'target'],
                0.2
            );

            if (! empty($results)) {
                $anyLawFound = true;
                foreach ($results as $r) {
                    $this->assertArrayHasKey('class', $r);
                    $this->assertContains($r['class'], ['EMPIRICAL', 'IDENTITY', 'NONE']);
                }
            }
        }

        $this->assertTrue($anyLawFound, 'At least one txt_pair task must yield laws through Search::find');
    }

    /**
     * Минимальный тест: TextAtomCrossPairer → Search::find напрямую.
     *
     * Два атома с известной зависимостью (B = A * 2 + шум).
     * Search::find должен найти закон.
     */
    public function testCrossPairProducesDiscoverableLaws(): void
    {
        // Атом A: значения [1, 2, 3, ... 20]
        // Атом B: A * 2 + небольшой шум
        $n = 20;
        $atomA = [];
        $atomB = [];
        for ($i = 0; $i < $n; $i++) {
            $a = (float) ($i + 1);
            $atomA[] = $a;
            $atomB[] = $a * 2.0 + (sin($i * 0.3) * 0.1); // почти точная зависимость
        }

        $crossTasks = TextAtomCrossPairer::crossPair([
            'txt_word_count' => $atomA,
            'txt_char_count' => $atomB,
        ], 'text_pairs');

        $this->assertNotEmpty($crossTasks);

        // Берём первую cross-pair задачу
        $task = $crossTasks[0];
        $data = $task['data'];
        $X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
        $y = array_column($data, count($data[0]) - 1);

        $g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'sq', 'sqrt']));
        $result = Search::find($X, $y, $g, 2, ['feature', 'target'], 0.2);

        $this->assertCount(5, $result);
        $this->assertTrue($result[0], 'Search::find must discover law from cross-paired text atoms');
        $this->assertLessThan(0.5, $result[1], 'cv_train must be reasonable');
        $this->assertNotEmpty($result[2], 'formula must not be empty');
        $this->assertContains($result[4], ['EMPIRICAL', 'IDENTITY']);
    }
}
