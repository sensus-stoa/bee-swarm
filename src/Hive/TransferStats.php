<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * STRATEGY-TRANSFER prereg rev.5 §4/§10: comparison-phase statistics.
 * Primary test = exact McNemar on paired slots; Wilson CI = descriptive.
 * Pure functions, no I/O.
 */
final class TransferStats
{
    /**
     * Exact two-sided McNemar: discordant pairs n01 (arm1 fail/arm2 pass)
     * and n10 (arm1 pass/arm2 fail). p = 2 * P(Bin(n, 0.5) <= k),
     * k = min(n01, n10), capped at 1.
     */
    public static function exactMcNemar(int $n01, int $n10): float
    {
        $n = $n01 + $n10;
        if ($n === 0) {
            return 1.0;
        }
        $k = min($n01, $n10);
        $p = 0.0;
        for ($i = 0; $i <= $k; $i++) {
            $p += self::binomPmf($n, $i);
        }
        $twoSided = 2.0 * $p;

        return min(1.0, $twoSided);
    }

    /** Binomial pmf P(X=i), n trials p=0.5, log-space for stability. */
    private static function binomPmf(int $n, int $i): float
    {
        $logCoeff = self::logFact($n) - self::logFact($i) - self::logFact($n - $i);

        return exp($logCoeff - $n * log(2.0));
    }

    private static function logFact(int $k): float
    {
        if ($k < 2) {
            return 0.0;
        }
        $s = 0.0;
        for ($j = 2; $j <= $k; $j++) {
            $s += log((float) $j);
        }

        return $s;
    }

    /**
     * Wilson score LOWER bound for a binomial proportion (descriptive CI).
     */
    public static function wilsonLower(int $successes, int $n, float $z = 1.96): float
    {
        if ($n === 0) {
            return 0.0;
        }
        $ph = $successes / $n;
        $z2 = $z * $z;
        $denom = 1.0 + $z2 / $n;
        $center = $ph + $z2 / (2.0 * $n);
        $margin = $z * sqrt($ph * (1.0 - $ph) / $n + $z2 / (4.0 * $n * $n));

        return max(0.0, ($center - $margin) / $denom);
    }
}
