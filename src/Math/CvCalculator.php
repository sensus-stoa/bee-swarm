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
     * Y_MAX-AMND (24.09): потолок абсолютной компоненты допуска.
     * Имя unified с Search::Y_MAX_EXACT (criterion-audit YMAX#2: одна
     * формула — одно имя, grep-аудит); значение 1e6 в обоих классах.
     */
    private const Y_MAX_EXACT = 1000000.0;

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

        // Exact match — eps ОТНОСИТЕЛЬНЫЙ, единый с Search::cv (L1 unify,
        // EXP-036 SCALE-INVARIANCE): abs-eps 1e-4 отвергал точный закон
        // 10·f(x) с остатком ≤1e-3 (K3 kill-кейс) → два источника истины
        // (Search принимал, LawValidator/retrospectiveValidate отвергали).
        // 1e-4·max(1,|y_i|) инвариантен к масштабу y.
        for ($i = 0; $i < $n; $i++) {
            if (abs($vec[$i] - $y[$i]) > 0.0001 * max(1.0, min(abs($y[$i]), self::Y_MAX_EXACT))) {
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
