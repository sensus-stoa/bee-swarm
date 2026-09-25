<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * V0.15 WU-1: CongruenceDetector — предсказательная эквивалентность на домене.
 *
 * Класс эквивалентности C = {формулы, предсказывающие одно и то же на D
 * в пределах eps}. Второй уровень различения после normalize (синтаксические
 * дубли): (x0+x1)^2 и развёрнутый квадрат — РАЗНЫЕ каноны, но предсказательно
 * неразличимы. Дистрибутивность ломает уникальность минимального алгебраического
 * представителя — детектор операционализирует классы численно.
 *
 * Метрика: max_i |a_i - b_i| / max(1, |a_i|, |b_i|) — max-relative-diff,
 * масштаб-инвариантна (EXP-036). split_point = первая точка, где diff > eps.
 *
 * «Не доказана эквивалентность» ≠ «доказана»: null-возврат evaluator'а
 * (parse-fail, non-finite) или пустой домен = inconclusive, congruent=false.
 */
final class CongruenceDetector
{
    /**
     * Допуск пары формул. НЕ путать с CV-eps (допуск закона к данным):
     * это разные вещи — здесь допуск пары друг к другу на домене.
     */
    public const EPS_DEFAULT = 1e-9;

    /**
     * Численный тест пары формул на домене rows.
     *
     * @param list<list<float>> $rows домен: строки фич x0..xN-1
     * @param float|null        $eps  допуск пары (null = константа класса)
     *
     * @return array{congruent: bool, max_diff: float, split_point: ?array{index: int, a: float, b: float, diff: float}, inconclusive: bool}
     */
    public function test(string $formulaA, string $formulaB, array $rows, ?float $eps = null): array
    {
        $eps ??= self::EPS_DEFAULT;
        $vecA = ExpressionEvaluator::evaluateFormula($formulaA, $rows);
        if ($vecA === null) {
            return $this->inconclusive();
        }
        $vecB = ExpressionEvaluator::evaluateFormula($formulaB, $rows);
        if ($vecB === null) {
            return $this->inconclusive();
        }
        $maxDiff = 0.0;
        $split = null;
        foreach ($vecA as $i => $a) {
            $b = $vecB[$i];
            $diff = abs($a - $b) / max(1.0, abs($a), abs($b));
            $maxDiff = max($maxDiff, $diff);
            if ($split === null && $diff > $eps) {
                $split = [
                    'index' => $i,
                    'a' => $a,
                    'b' => $b,
                    'diff' => $diff,
                ];
            }
        }

        return [
            'congruent' => $maxDiff <= $eps,
            'max_diff' => $maxDiff,
            'split_point' => $split,
            'inconclusive' => false,
        ];
    }

    /**
     * @return array{congruent: bool, max_diff: float, split_point: null, inconclusive: bool}
     */
    private function inconclusive(): array
    {
        return [
            'congruent' => false,
            'max_diff' => INF,
            'split_point' => null,
            'inconclusive' => true,
        ];
    }
}
