<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use PHPUnit\Framework\TestCase;

/**
 * STRATEGY-TRANSFER prereg rev.5 §4/§10: exact McNemar on paired slots and
 * Wilson CI (descriptive) for the strategy campaign comparison phase.
 */
final class TransferStatsTest extends TestCase
{
    public function testMcNemarNoDiscordantPairs(): void
    {
        $this->assertSame(1.0, TransferStats::exactMcNemar(0, 0));
    }

    public function testMcNemarAllDiscordantOneDirection(): void
    {
        // 5 vs 0: p = 2 * 0.5^5 = 0.0625
        $this->assertEqualsWithDelta(0.0625, TransferStats::exactMcNemar(5, 0), 1e-12);
        // 6 vs 0: p = 2 * 0.5^6 = 0.03125 < 0.05
        $this->assertEqualsWithDelta(0.03125, TransferStats::exactMcNemar(6, 0), 1e-12);
    }

    public function testMcNemarSymmetricDiscordance(): void
    {
        // 3 vs 3: two-sided p = 1.0 (perfectly balanced disagreement)
        $this->assertEqualsWithDelta(1.0, TransferStats::exactMcNemar(3, 3), 1e-12);
    }

    public function testMcNemarSumNeverAboveOne(): void
    {
        $this->assertLessThanOrEqual(1.0, TransferStats::exactMcNemar(1, 2));
    }

    public function testWilsonLowerBoundKnownValues(): void
    {
        // 3/3 successes, z=1.96: lower bound ~0.4385 (prereg quotes ~44%)
        $lb = TransferStats::wilsonLower(3, 3);
        $this->assertGreaterThan(0.43, $lb);
        $this->assertLessThan(0.45, $lb);
        // 0/10 -> 0
        $this->assertEqualsWithDelta(0.0, TransferStats::wilsonLower(0, 10), 1e-12);
        // 1/1 -> ~0.2065
        $lb11 = TransferStats::wilsonLower(1, 1);
        $this->assertGreaterThan(0.20, $lb11);
        $this->assertLessThan(0.21, $lb11);
    }
}
