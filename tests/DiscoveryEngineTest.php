<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * Story D14 Phase 3: DiscoveryEngine — извлечение doDiscoverTick из Hive.
 */
class DiscoveryEngineTest extends TestCase
{
    /**
     * DiscoveryEngine находит закон ADD (x0+x1=y) на синтетических данных.
     *
     * Predicted: FAIL — класс DiscoveryEngine не существует.
     */
    public function testDiscoversAddLaw(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [2, 5], [4, 1], [6, 3], [8, 7], [10, 0]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10];

        $engine = new DiscoveryEngine();
        $grammarOps = array_merge(Grammar::baseOpNames(), ['add', 'mul', 'sub', 'div']);
        $result = $engine->discover($X, $y, $grammarOps, 0.01);

        $this->assertNotNull($result, 'Must discover ADD law');
        $this->assertNotEmpty($result[0], 'Must return at least one discovery');
    }

    /**
     * На шумных данных возвращает пустой массив.
     */
    public function testNoDiscoveryOnNoise(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 20; $i++) {
            $X[] = [(float) mt_rand(0, 100), (float) mt_rand(0, 100)];
            $y[] = (float) mt_rand(0, 100);
        }

        $engine = new DiscoveryEngine();
        $result = $engine->discover($X, $y, Grammar::baseOpNames(), 0.001);

        $this->assertEmpty($result[0], 'No discoveries on pure noise');
    }
}
