<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionTree;

/**
 * Story D9: ExpressionTree::nodeCount()
 */
class NodeCountTest extends TestCase
{
    public function testSimpleAtomOneNode(): void
    {
        $tree = new ExpressionTree([
            'op' => 'add',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->assertSame(1, $tree->nodeCount());
    }

    public function testUnaryOneNode(): void
    {
        $tree = new ExpressionTree([
            'op' => 'sq',
            'arg' => 'a',
        ]);
        $this->assertSame(1, $tree->nodeCount());
    }

    public function testNestedTreeCountsCorrectly(): void
    {
        // add(min(a,b), sq(c)) → 3 nodes (add, min, sq)
        $tree = new ExpressionTree([
            'op' => '+',
            'left' => [
                'op' => 'min',
                'left' => 'a',
                'right' => 'b',
            ],
            'right' => [
                'op' => 'sq',
                'arg' => 'c',
            ],
        ]);
        $this->assertSame(3, $tree->nodeCount());
    }

    public function testLeafReturnsOne(): void
    {
        $tree = new ExpressionTree('a');
        $this->assertSame(1, $tree->nodeCount());
    }
}
