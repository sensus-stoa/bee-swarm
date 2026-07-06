<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * Story 05: Compression Superiority (HONEST_CRITERIA §1.7)
 */
class CompressionTest extends TestCase
{
    /** isBetterThanBaseline() — проверка сжатия */
    public function test_method_exists(): void
    {
        $this->assertTrue(
            method_exists(AtomRegistry::class, 'isBetterThanBaseline'),
            'AtomRegistry must implement isBetterThanBaseline()'
        );
    }

    /** CV=0 → cost=complexity + 0 < cost(mean) → true */
    public function test_exact_cv_zero_beats_baseline(): void
    {
        // CV=0 на среднем: cost = 1 + log₂(1) = 1
        // baseline = 1 + log₂(1) = 1 → равенство → false (не строго лучше)
        // Но для CV_atom=0 на данных с разбросом: cvMean > 0 → costMean > 1 → true
        $this->assertTrue(
            AtomRegistry::isBetterThanBaseline(0.0, 'add', 0.5),
            'CV=0 law must beat baseline with CV_mean=0.5'
        );
    }

    /** CV=CV_mean → rejected (эквивалентно baseline) */
    public function test_equal_cv_rejected(): void
    {
        // Атом с тем же CV что у mean → НЕ лучше
        $this->assertFalse(
            AtomRegistry::isBetterThanBaseline(0.1, 'K', 0.1),
            'Atom equivalent to mean must be rejected (§1.7)'
        );
    }

    /** CV хуже mean → rejected */
    public function test_worse_cv_rejected(): void
    {
        $this->assertFalse(
            AtomRegistry::isBetterThanBaseline(0.3, 'add', 0.1),
            'Atom with CV worse than mean must be rejected'
        );
    }
}
