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
     * A19.3: относительный exact-допуск (EXP-036 SCALE-INVARIANCE).
     */
    public const EXACT_TOL_REL = 0.0001;

    /**
     * A19.3 (BUG-EXP040-EXACT-FLOOR): нижний этаж exact-допуска как доля
     * масштаба датасета. 1e-8·max|y| = 10⁶-кратный запас над float-шумом
     * вычисления формулы (~1e-14·maxAbs). Абсолютный floor 1.0 (→ 1e-4 ABS
     * при любом |y|<1) убит: на y~1e-9 он делал «exact» любую предикцию
     * меньше 1e-4 (константа x8 ≡ G, EXP-040).
     */
    public const EXACT_FLOOR_FRAC = 1e-8;

    /**
     * A19.3: масштаб датасета для exact-допуска — max|y| (null/INF не входят).
     */
    public static function exactScaleMaxAbs(array $y): float
    {
        $maxAbs = 0.0;
        foreach ($y as $v) {
            if ($v === null || ! is_finite($v)) {
                continue;
            }
            $a = abs($v);
            if ($a > $maxAbs) {
                $maxAbs = $a;
            }
        }

        return $maxAbs;
    }

    /**
     * A19.3: exact-допуск пары (pred, y_i) при масштабе датасета $maxAbs.
     * tol = EXACT_TOL_REL · min(max(|y_i|, EXACT_FLOOR_FRAC·maxAbs), Y_MAX_EXACT).
     * Верхний кап Y_MAX-AMND (24.09) сохранён.
     */
    public static function isExactPair(float $pred, float $y, float $maxAbs): bool
    {
        if (! is_finite($pred)) {
            return false;
        }
        $tol = self::EXACT_TOL_REL * min(max(abs($y), self::EXACT_FLOOR_FRAC * $maxAbs), self::Y_MAX_EXACT);

        return abs($pred - $y) <= $tol;
    }

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

        // Exact match — eps ОТНОСИТЕЛЬНЫЙ + scale-aware (L1 unify + A19.3,
        // BUG-EXP040-EXACT-FLOOR): нижний этаж допуска привязан к масштабу
        // ДАТАСЕТА (max|y|), не к 1.0 — abs-floor 1e-4 на y~1e-9 делал
        // «exact» любую мелкую предикцию (константа G).
        $exactMaxAbs = self::exactScaleMaxAbs($y);
        for ($i = 0; $i < $n; $i++) {
            if (! self::isExactPair((float) $vec[$i], (float) $y[$i], $exactMaxAbs)) {
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
