<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * Story 06: Non-Triviality (HONEST_CRITERIA §1.4)
 *
 * @group disabled
 */
class NonTrivialTest extends TestCase
{
    /** isTrivial() должен существовать */
    public function test_method_exists(): void
    {
        $this->assertTrue(
            method_exists(AtomRegistry::class, 'isTrivial'),
            'AtomRegistry must implement isTrivial()'
        );
    }

    /** x0 — identity → trivial */
    public function test_feature_is_trivial(): void
    {
        $this->assertTrue(
            AtomRegistry::isTrivial('x0', [[1],[2],[3]], [1,2,3]),
            'x0 (identity feature) must be trivial'
        );
    }

    /** K1 — constant → trivial */
    public function test_constant_is_trivial(): void
    {
        $this->assertTrue(
            AtomRegistry::isTrivial('K1', [[5],[5],[5]], [5,5,5]),
            'K1 (constant) must be trivial'
        );
    }

    /** add — не trivial */
    public function test_real_atom_not_trivial(): void
    {
        $this->assertFalse(
            AtomRegistry::isTrivial('add', [[1,2],[3,4],[5,6]], [3,7,11]),
            'add must NOT be trivial'
        );
    }

    /** + — alias для add → не trivial (резолвится в add) */
    public function test_alias_not_trivial(): void
    {
        $this->assertFalse(
            AtomRegistry::isTrivial('+', [[1,2],[3,4],[5,6]], [3,7,11]),
            '+ (alias for add) must NOT be trivial'
        );
    }
}
