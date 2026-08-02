<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;

/**
 * Регрессионный тест: fromOps не создаёт мёртвые custom_*.
 */
class GrammarFromOpsRegressionTest extends TestCase
{
    public function testNoCustomPrefixInOps(): void
    {
        $ops = Grammar::baseOpNames();
        $g = Grammar::fromOps($ops);
        $ref = new \ReflectionClass($g);
        $prop = $ref->getProperty('ops');
        $allOps = $prop->getValue($g);

        foreach ($ops as $op) {
            $this->assertArrayHasKey($op, $allOps, "Op '$op' missing from fromOps");
            $fn = $allOps[$op]['fn'] ?? '';
            $this->assertStringNotContainsString('custom_', $fn, "Op '$op' fn='$fn' — dead custom_*");
        }
    }

    public function testBaseOpsCompute(): void
    {
        $g = Grammar::fromOps(['+', '×', '−', '/', 'max', 'min']);
        $this->assertSame(5.0, $g->apply(2.0, 3.0, '+'));
        $this->assertSame(20.0, $g->apply(4.0, 5.0, '×'));
        $this->assertSame(7.0, $g->apply(10.0, 3.0, '−'));
        $this->assertSame(2.0, $g->apply(10.0, 5.0, '/'));
        $this->assertSame(5.0, $g->apply(5.0, 3.0, 'max'));
        $this->assertSame(3.0, $g->apply(5.0, 3.0, 'min'));
    }

    public function testSearchFindFindsAdd(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [2, 5], [4, 1], [6, 3], [8, 7], [10, 0]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10];
        $g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), ['add']));
        [$found, $cv, $formula] = \BeeSwarm\Core\Search::find($X, $y, $g, 2, ['x0', 'x1']);
        $this->assertTrue($found, 'Must find ADD');
        $this->assertSame('(x0+x1)', $formula);
    }
}
