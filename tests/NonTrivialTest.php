<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * Story 06 + B2: Non-Triviality (HONEST_CRITERIA §1.4)
 */
class NonTrivialTest extends TestCase
{
    public function testMethodExists(): void
    {
        $this->assertTrue(method_exists(AtomRegistry::class, 'isTrivial'));
    }

    public function testFeatureIsTrivial(): void
    {
        $this->assertTrue(AtomRegistry::isTrivial('x0', [[1],[2],[3]], [1,2,3]));
    }

    public function testConstantIsTrivial(): void
    {
        $this->assertTrue(AtomRegistry::isTrivial('K1', [[5],[5],[5]], [5,5,5]));
    }

    public function testRealAtomNotTrivial(): void
    {
        $this->assertFalse(AtomRegistry::isTrivial('add', [[1,2],[3,4],[5,6]], [3,7,11]));
    }

    public function testAliasNotTrivial(): void
    {
        $this->assertFalse(AtomRegistry::isTrivial('+', [[1,2],[3,4],[5,6]], [3,7,11]));
    }

    /** B2: add(x,0) — алгебраическая редукция → trivial */
    public function testAddX0IsTrivial(): void
    {
        $this->assertTrue(
            AtomRegistry::isTrivial('add(x0,0)', [[1,2],[3,4]], [1,2]),
            'add(x,0) must be trivial (§1.4)'
        );
    }

    /** B2: mul(x,1) — тривиально */
    public function testMulX1IsTrivial(): void
    {
        $this->assertTrue(
            AtomRegistry::isTrivial('mul(x0,1)', [[1],[2]], [1,2]),
            'mul(x,1) must be trivial (§1.4)'
        );
    }
}
