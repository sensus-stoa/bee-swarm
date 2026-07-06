<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * Story 06: Non-Triviality (HONEST_CRITERIA §1.4)
 */
class NonTrivialTest extends TestCase
{
    /**
     * isTrivial() должен существовать
     */
    public function testMethodExists(): void
    {
        $this->assertTrue(
            method_exists(AtomRegistry::class, 'isTrivial'),
            'AtomRegistry must implement isTrivial()'
        );
    }

    /**
     * x0 — identity → trivial
     */
    public function testFeatureIsTrivial(): void
    {
        $this->assertTrue(
            AtomRegistry::isTrivial('x0', [[1], [2], [3]], [1, 2, 3]),
            'x0 (identity feature) must be trivial'
        );
    }

    /**
     * K1 — constant → trivial
     */
    public function testConstantIsTrivial(): void
    {
        $this->assertTrue(
            AtomRegistry::isTrivial('K1', [[5], [5], [5]], [5, 5, 5]),
            'K1 (constant) must be trivial'
        );
    }

    /**
     * add — не trivial
     */
    public function testRealAtomNotTrivial(): void
    {
        $this->assertFalse(
            AtomRegistry::isTrivial('add', [[1, 2], [3, 4], [5, 6]], [3, 7, 11]),
            'add must NOT be trivial'
        );
    }

    /**
     * + — alias для add → не trivial (резолвится в add)
     */
    public function testAliasNotTrivial(): void
    {
        $this->assertFalse(
            AtomRegistry::isTrivial('+', [[1, 2], [3, 4], [5, 6]], [3, 7, 11]),
            '+ (alias for add) must NOT be trivial'
        );
    }
}
