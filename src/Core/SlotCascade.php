<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * A2 (pysr-rematch PLAN-v2): каскад слотов — SUM-каркасы из ×-пар L1.
 *
 * EXP-036: dot 0/20 — beam по CV убивает частичные суммы (cv≈2.7 при
 * corr 0.6), закон проявляется ТОЛЬКО на финальном узле. Слоты: тройки
 * ×-форм сырых фич перебираются БЕЗ beam (прототип exp039_slots_fastcheck:
 * PASS 0.01s, FPR=0/1365); критерий сборки — cv→0 факта (тот же гейт
 * cvTrainMax, что у всех форм). Слоты только ПРОПОНУЮТ — суд (exact-check,
 * prereg, held-out, null-фильтр) остаётся общим.
 *
 * Ресурс: единственная ручка SLOT_BUDGET (cap попыток сборки, (Е)
 * произвольная по pre-reg partial-solution-slots).
 *
 * Ревью deleg_7e5f6f82/deleg_ebb11a69: перебор каноничен (k от j+1 —
 * каждое множество {i,j,k} проверяется ОДИН раз, имя детерминировано);
 * shift передаётся вызывающим — та же affine-семантика, что у суда.
 */
final class SlotCascade
{
    /**
     * Собрать SUM-каркасы из троек ×-форм. Возвращает [имя_формулы, вектор].
     * Гейт вставки в exprs — cvTrainMax вызывающего (никаких новых порогов).
     *
     * @param array<string, array<float>> $exprs пул выражений (×-пары уже в нём)
     * @param array<float> $y цель
     * @param array<string> $mulKeys имена ×-форм вида (xa×xb)
     * @param callable(array<float>, array<float>): float $cv функция CV
     * @param float $cvMax гейт вставки (cvTrainMax вызывающего)
     * @param float $shift affine-сдвиг цели (тот же, что у суда — иначе
     *   гейт и суд расходятся на офсетных задачах, review п.2)
     * @return array{0: string, 1: array<float>}|null
     */
    public static function assemble(
        array $exprs,
        array $y,
        array $mulKeys,
        callable $cv,
        float $cvMax,
        float $shift = 0.0
    ): ?array {
        $budget = (int) (getenv('SLOT_BUDGET') ?: '3000');
        if ($budget <= 0 || count($mulKeys) < 3) {
            return null;
        }
        $n = count($y);
        $tries = 0;
        $count = count($mulKeys);
        // Канонический перебор (review п.1): k от j+1 — каждое множество
        // {i,j,k} проверяется один раз, бюджет не горит на дублях,
        // имя формы детерминировано порядком индексов.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $vi = $exprs[$mulKeys[$i]];
                $vj = $exprs[$mulKeys[$j]];
                for ($k = $j + 1; $k < $count; $k++) {
                    if (++$tries > $budget) {
                        return null;
                    }
                    $vk = $exprs[$mulKeys[$k]];
                    $sum = [];
                    for ($r = 0; $r < $n; $r++) {
                        $sum[] = $vi[$r] + $vj[$r] + $vk[$r];
                    }
                    if ($cv($sum, $y, $shift) <= $cvMax) {
                        $name = "((({$mulKeys[$i]}+{$mulKeys[$j]})+{$mulKeys[$k]}))";

                        return [$name, $sum];
                    }
                }
            }
        }

        return null;
    }
}
