<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * D14 Wiring: Hive использует DiscoveryEngine вместо монолитного doDiscoverTick.
 */
class HiveEngineWiringTest extends TestCase
{
    /**
     * DiscoveryEngine находит закон на синтетических данных —
     * идентично тому что делал doDiscoverTick.
     */
    public function testDiscoveryEngineFindsAddLaw(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [2, 5], [4, 1], [6, 3], [8, 7], [10, 0]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10];

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $X, $y,
            array_merge(Grammar::baseOpNames(), ['add', 'mul', 'sub', 'div']),
            0.01,
            ['x0', 'x1']
        );
        $candidates = $results[0];

        $this->assertNotEmpty($candidates, 'Must discover ADD');
        $atoms = array_column($candidates, 'atom');
        $found = false;
        foreach ($atoms as $a) {
            if (str_contains($a, 'add') || str_contains($a, '+')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Must find add law');
    }

    /**
     * Engine возвращает пустой массив при tMin insufficient.
     */
    public function testEngineRequiresMinRows(): void
    {
        $X = [[1, 2], [3, 4]];
        $y = [3, 7];

        $engine = new DiscoveryEngine();
        $results = $engine->discover($X, $y, Grammar::baseOpNames(), 0.01);

        $this->assertEmpty($results[0], 'Insufficient data → empty');
    }
}
