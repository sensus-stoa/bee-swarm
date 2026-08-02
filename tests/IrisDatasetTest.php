<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * Доказательство что система находит законы:
 * Iris dataset (Fisher, 1936) — 3 вида ирисов, 4 признака.
 * Каждый вид линейно отделим — пчёлы должны найти закономерности.
 */
class IrisDatasetTest extends TestCase
{
    /**
     * Iris setosa: petal_length ≤ 2.0 → setosa (vs остальные)
     * Это простой линейный порог — пчёлы должны найти.
     */
    public function testIrisSetosaSeparation(): void
    {
        // Iris dataset (первые 2 признака: sepal_length, sepal_width)
        // target = 1 для setosa, 0 для versicolor+virginica
        $X = [
            [5.1, 3.5], [4.9, 3.0], [4.7, 3.2], [4.6, 3.1], [5.0, 3.6],
            [5.4, 3.9], [4.6, 3.4], [5.0, 3.4], [4.4, 2.9], [4.9, 3.1],
            [7.0, 3.2], [6.4, 3.2], [6.9, 3.1], [5.5, 2.3], [6.5, 2.8],
            [5.7, 2.8], [6.3, 3.3], [4.9, 2.4], [6.6, 2.9], [5.2, 2.7],
        ];
        $y = [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $X, $y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'min', 'max', 'abs', 'neg']),
            0.15,
            ['sepal_length', 'sepal_width'],
        );

        $this->assertNotEmpty($results, 'Iris setosa must separate from others');
        $found = false;
        foreach ($results as $r) {
            if ($r['cv'] <= 0.2) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Must find law with CV≤0.2 on Iris');
    }

    /**
     * На чистом шуме законов НЕ находит.
     */
    public function testPureNoiseYieldsNoLaws(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 20; $i++) {
            $X[] = [(float) mt_rand(0, 100) / 10, (float) mt_rand(0, 100) / 10];
            $y[] = (float) mt_rand(0, 1);
        }

        $engine = new DiscoveryEngine();
        $results = $engine->discover($X, $y, Grammar::baseOpNames(), 0.01);

        $this->assertEmpty($results, 'Pure noise must yield no laws');
    }
}
