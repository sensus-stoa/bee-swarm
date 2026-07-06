<?php

declare(strict_types=1);

namespace BeeSwarm\Math;

/**
 * CvCalculator — вычисление coefficient of variation.
 * Вынесено из AtomRegistry (SOLID S).
 */
class CvCalculator
{
    /**
     * CV = σ(ratios) / |mean(ratios)|, где ratio[i] = vec[i] / y[i].
     * CV=0 означает точное совпадение с точностью до константного множителя.
     */
    public static function compute(array $vec, array $y): float
    {
        $n = count($vec);
        if ($n < 2) {
            return 9.99;
        }

        // Exact match
        for ($i = 0; $i < $n; $i++) {
            if (abs($vec[$i] - $y[$i]) > 0.0001) {
                break;
            }
            if ($i === $n - 1) {
                return 0.0;
            }
        }

        $ratios = [];
        for ($i = 0; $i < $n; $i++) {
            $denom = $y[$i] + 1e-8;
            if (abs($denom) < 1e-10) {
                return 9.99;
            }
            $ratios[] = $vec[$i] / $denom;
        }

        $mean = array_sum($ratios) / $n;
        if (abs($mean) < 1e-8) {
            return 9.99;
        }

        $variance = 0.0;
        foreach ($ratios as $r) {
            $variance += ($r - $mean) ** 2;
        }
        return sqrt($variance / $n) / abs($mean);
    }
}
