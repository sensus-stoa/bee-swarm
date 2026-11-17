<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/exp045/cert_utils.php';

/**
 * EXP-051 (07.11, owner word (B)): cert_x = DUAL-CHANNEL gate (min-logic,
 * mirrors the search-side AFFINE-LAWS min-channel, Search.php:62-66).
 *
 * Channel 1 = canon ratio p/y (std/|mean| of row-wise ratios) — TAIL-DRIVEN
 * under additive noise: EXP-051-B measured 0/15 island certs at sigma>=0.002
 * (rows with |y| ~ 1e-4, e.g. x3*x4^2 guarantees them), the gate rejects the
 * EXACT form. Channel 2 = MAE/mean|y| — tail-free, scale-invariant,
 * proportional semantics preserved. cert_x returns min of the channels
 * present; null only if BOTH channels are inapplicable.
 *
 * Measured anchors (EXP-051-B artifacts, Benchmarks/EXP051_spurious/):
 * island B '(x3×x4^2)' at sigma=0.002: ratio cvs [0.336, 0.324, 0.150] FAIL;
 * island A '(x0×x1)' at sigma=0.01-0.015: ratio max_cv 0.13-0.5 FAIL.
 */
final class CertGateDualChannelTest extends TestCase
{
    /** Zero-crossing target with additive noise: y = x0*x1 + N(0, sigma^2). */
    private function genNoisy(int $seed, int $n, float $sigma, int $noiseSeed): array
    {
        $s = $seed;
        $lcg = function () use (&$s) {
            $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

            return $s / 0x7FFFFFFF;
        };
        $u = function (float $a, float $b) use ($lcg): float {
            return $a + $lcg() * ($b - $a);
        };
        $ns = $noiseSeed + 777;
        $nrand = function () use (&$ns): float {
            $ns = ($ns * 1103515245 + 12345) & 0x7FFFFFFF;

            return $ns / 0x7FFFFFFF;
        };
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
            $X[] = $row;
            $noise = $sigma > 0 ? $sigma * sqrt(-2 * log(max(1e-12, $nrand()))) * cos(2 * M_PI * $nrand()) : 0.0;
            $y[] = $row[0] * $row[1] + $noise;
        }

        return [$X, $y];
    }

    /** Island-B-shaped target: y = x3*x4^2 (min|y| ~ 1e-4 guaranteed). */
    private function genSquareIsland(int $seed, int $n, float $sigma, int $noiseSeed): array
    {
        $s = $seed;
        $lcg = function () use (&$s) {
            $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

            return $s / 0x7FFFFFFF;
        };
        $u = function (float $a, float $b) use ($lcg): float {
            return $a + $lcg() * ($b - $a);
        };
        $ns = $noiseSeed + 777;
        $nrand = function () use (&$ns): float {
            $ns = ($ns * 1103515245 + 12345) & 0x7FFFFFFF;

            return $ns / 0x7FFFFFFF;
        };
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
            $X[] = $row;
            $noise = $sigma > 0 ? $sigma * sqrt(-2 * log(max(1e-12, $nrand()))) * cos(2 * M_PI * $nrand()) : 0.0;
            $y[] = $row[3] * $row[4] * $row[4] + $noise;
        }

        return [$X, $y];
    }

    public function testNoisyZeroCrossingExactFormPassesViaMaeChannel(): void
    {
        // EXP-051-B: the TRUE form fails the raw-ratio gate from sigma~0.002
        // (tail rows); the MAE channel must carry it.
        [$X, $y] = $this->genNoisy(901, 200, 0.01, 901);
        $cv = cert_x('(x0×x1)', $X, $y);
        $this->assertNotNull($cv, 'exact form must evaluate');
        $this->assertLessThanOrEqual(0.10, $cv, 'exact form at sigma=0.01 must certify via the MAE channel');
    }

    public function testSquareIslandStructuralCasePasses(): void
    {
        // x3*x4^2: min|y| ~ 1e-4 in EVERY dataset -> ratio channel dead at
        // ANY sigma>0 (0/15 measured); the MAE channel carries the truth.
        [$X, $y] = $this->genSquareIsland(413, 200, 0.002, 3413);
        $cv = cert_x('(x3×x4²)', $X, $y);
        $this->assertNotNull($cv, 'exact square-island form must evaluate');
        $this->assertLessThanOrEqual(0.10, $cv, 'exact square-island form at sigma=0.002 must certify via the MAE channel');
    }

    public function testExactFormAtZeroSigmaIsStillZero(): void
    {
        [$X, $y] = $this->genNoisy(904, 200, 0.0, 904);
        $cv = cert_x('(x0×x1)', $X, $y);
        $this->assertNotNull($cv);
        $this->assertEqualsWithDelta(0.0, $cv, 1e-12, 'noiseless exact form = exact cert, both channels agree at 0');
    }

    public function testWrongFormStillFailsBothChannels(): void
    {
        // Precision kept: a wrong form on clean data fails BOTH channels
        // (ratio large AND MAE/mean|y| large) -> cert returns a large value,
        // cert_v2's 0.10 threshold rejects it.
        [$X, $y] = $this->genNoisy(903, 200, 0.0, 903); // y = x0*x1, noiseless
        $cv = cert_x('(x0×x2)', $X, $y);
        $this->assertNotNull($cv, 'a wrong but evaluable form is a large cv, not an eval failure');
        $this->assertGreaterThan(0.10, $cv, 'wrong form must not certify under either channel');
    }

    public function testScaleInvarianceOfTheGateValue(): void
    {
        // K3-контракт (SCALE-INVARIANCE): cv(pred·a, y·a) = cv(pred, y) —
        // BOTH channels (ratio AND MAE/mean|y|) are scale-invariant, and the
        // min() of two scale-invariant values is scale-invariant.
        // K2 = 2.0 (grammar constant, ЭКСП-026) scales the FORM with the target.
        [$X, $y] = $this->genNoisy(905, 200, 0.01, 905);
        $y2 = array_map(fn (float $v): float => $v * 2.0, $y);
        $cv1 = cert_x('(x0×x1)', $X, $y);
        $cv2 = cert_x('(x0×x1×K2)', $X, $y2);
        $this->assertNotNull($cv1);
        $this->assertNotNull($cv2);
        $this->assertEqualsWithDelta($cv1, $cv2, 1e-9 * max(1.0, abs($cv1)), 'dual-channel gate must be scale-invariant');
    }
}
