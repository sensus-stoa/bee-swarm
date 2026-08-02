<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\OverlapTracker;

/**
 * Story V0.8 Phase 4: Algebraic reduction in answer comparison.
 *
 * «идентично после алгебраической редукции» (§1.8)
 * x0+0 ≡ x0, x0+x1 ≡ x1+x0 (коммутативность для +, ×, min, max)
 */
class AlgebraicReductionTest extends TestCase
{
    /**
     * x0+0 редуцируется в x0.
     *
     * Predicted: FAIL — reduceAnswer не существует.
     */
    public function testReduceAddZero(): void
    {
        $this->assertSame('x0', OverlapTracker::reduceAnswer('add(x0,0)'));
        $this->assertSame('x0', OverlapTracker::reduceAnswer('add(x0,x0_0)'));
        // x0_0 канонизируется в 0
        $this->assertSame('0', OverlapTracker::reduceAnswer('x0_0'));
        $this->assertSame('0', OverlapTracker::reduceAnswer('add(0,0)'));
        $this->assertSame('0', OverlapTracker::reduceAnswer('add(x0_0,x0_0)'));
    }

    /**
     * Коммутативность: x0+x1 ≡ x1+x0.
     */
    public function testReduceCommutativeAdd(): void
    {
        $a = OverlapTracker::reduceAnswer('add(x0,x1)');
        $b = OverlapTracker::reduceAnswer('add(x1,x0)');
        $this->assertSame($a, $b);
    }

    /**
     * Коммутативность для ×, min, max.
     */
    public function testReduceCommutativeMulMinMax(): void
    {
        $this->assertSame(
            OverlapTracker::reduceAnswer('mul(x0,x1)'),
            OverlapTracker::reduceAnswer('mul(x1,x0)')
        );
        $this->assertSame(
            OverlapTracker::reduceAnswer('min(x0,x1)'),
            OverlapTracker::reduceAnswer('min(x1,x0)')
        );
        $this->assertSame(
            OverlapTracker::reduceAnswer('max(x0,x1)'),
            OverlapTracker::reduceAnswer('max(x1,x0)')
        );
    }

    /**
     * x0×1 → x0.
     */
    public function testReduceMulOne(): void
    {
        $this->assertSame('x0', OverlapTracker::reduceAnswer('mul(x0,1)'));
    }

    /**
     * Неизменяемые формулы.
     */
    public function testReducePassesUnchanged(): void
    {
        $this->assertSame('sub(x0,x1)', OverlapTracker::reduceAnswer('sub(x0,x1)'));
        $this->assertSame('div(x0,2)', OverlapTracker::reduceAnswer('div(x0,2)'));
    }
}
