<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * LAW-CLASS (08.08): pred-векторный класс закона.
 * Численно эквивалентные формулы на данных (одинаковый предсказанный
 * вектор) — ОДИН класс. Награда только за первый представитель класса:
 * сотни приближений одного закона перестают быть «бесконечной едой».
 */
final class LawClassifier
{
    /**
     * Квантование: относительная точность 1e-4 (масштаб-инвариантно).
     */
    private const QUANT = 1e-4;

    public static function hash(string $formula, array $X, array $y): string
    {
        $pred = ExpressionEvaluator::evaluateFormula($formula, $X);
        if ($pred === null || count($pred) !== count($y)) {
            return '';
        }

        $key = [];
        foreach ($pred as $i => $v) {
            if (! is_finite((float) $v)) {
                return '';
            }
            $scale = max(abs((float) $y[$i]), 1e-9);
            $q = (int) round((float) $v / ($scale * self::QUANT));
            $key[] = $q;
        }

        return md5(implode(',', $key));
    }
}
