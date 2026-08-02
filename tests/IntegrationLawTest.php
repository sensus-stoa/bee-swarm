<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * Интеграционные тесты: система находит законы.
 */
class IntegrationLawTest extends TestCase
{
    /**
     * ADD: x0+x1=y. Простейший закон.
     */
    public function testFindsAddLaw(): void
    {
        $X = [[1,2],[3,4],[5,6],[7,8],[9,10],[2,5],[4,1],[6,3],[8,7],[10,0]];
        $y = [3,7,11,15,19,7,5,9,15,10];

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $X, $y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul']),
            0.01,
            ['x0', 'x1'],
        );

        $this->assertNotEmpty($results, 'Must find ADD law');
        $atoms = array_column($results, 'atom');
        $this->assertContains('(x0+x1)', $atoms, 'ADD law formula must be in results');
    }

    /**
     * MUL: x0×2=y. Умножение на константу.
     */
    public function testFindsMulLaw(): void
    {
        $X = [[1],[2],[3],[4],[5],[6],[7],[8],[9],[10]];
        $y = [2,4,6,8,10,12,14,16,18,20];

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $X, $y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'sq']),
            0.01,
            ['x0'],
        );

        $this->assertNotEmpty($results, 'Must find MUL law');
    }

    /**
     * На чистом шуме — пусто.
     */
    public function testNoiseYieldsNothing(): void
    {
        $X = $y = [];
        for ($i = 0; $i < 20; $i++) {
            $X[] = [(float) mt_rand(0, 100) / 10, (float) mt_rand(0, 100) / 10];
            $y[] = (float) mt_rand(0, 10);
        }

        $engine = new DiscoveryEngine();
        $results = $engine->discover($X, $y, Grammar::baseOpNames(), 0.001);

        $this->assertEmpty($results, 'Pure noise → no laws');
    }
}
