<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * Story 05: Compression Superiority (HONEST_CRITERIA §1.7)
 *
 * @group disabled
 */
class CompressionTest extends TestCase
{
    /** isBetterThanBaseline() — проверка сжатия */
    public function test_method_exists(): void
    {
        $this->assertTrue(
            method_exists(AtomRegistry::class, 'isBetterThanBaseline'),
            'AtomRegistry must implement isBetterThanBaseline() for compression check'
        );
    }

    /** Точный закон (vec=y) лучше baseline (vec=mean) */
    public function test_exact_beats_baseline(): void
    {
        $y = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $mean = array_sum($y) / count($y);
        $vecMean = array_fill(0, count($y), $mean);

        // Точный закон: CV=0 → MDL → −∞ → лучше mean
        $this->assertTrue(
            AtomRegistry::isBetterThanBaseline($y, $y, 'exact'),
            'Exact prediction must beat mean baseline'
        );

        // Константа = mean: CV одинаковый → MDL одинаковый → НЕ лучше
        $this->assertFalse(
            AtomRegistry::isBetterThanBaseline($vecMean, $y, 'K'),
            'Constant equal to mean must NOT beat baseline (§1.7)'
        );
    }
}
