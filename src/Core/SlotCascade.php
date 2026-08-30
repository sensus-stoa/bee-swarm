<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * A2/A3 (pysr-rematch PLAN-v2): каскад слотов — SUM-каркасы из ×-пар L1.
 *
 * EXP-036: dot 0/20 — beam по CV убивает частичные суммы (cv≈2.7 при
 * corr 0.6), закон проявляется ТОЛЬКО на финальном узле. Слоты: тройки
 * ×-форм сырых фич перебираются БЕЗ beam (прототип exp039_slots_fastcheck:
 * PASS 0.01s, FPR=0/1365); критерий сборки — cv→0 факта (тот же гейт
 * cvTrainMax, что у всех форм). Слоты только ПРОПОНУЮТ — суд (exact-check,
 * prereg, held-out, null-фильтр) остаётся общим.
 *
 * A3: вход — map имя→вектор (снапшот ×-пар снимается в Search СРАЗУ после
 * pairwise, до beam/заморозок — ниже по потоку rawRaw удаляются из exprs,
 * но материал сборки обязан сохраниться). Перебор расширен на (xa×xb²)
 * слоты → kinetic-класс собрался. Best-by-cv (не first-pass-wins):
 * каскад собирает ВСЕ passer'ы до бюджета и возвращает лучшую по cv —
 * иначе случайная тройка с cv<0.15, встреченная раньше точной, блокирует
 * закон (multiple-testing, воспроизведено на dot после расширения слотов).
 *
 * Масштаб: ratio-CV масштабно-инвариантен (kill-test 2.5) — каркас S=2y
 * имеет cv=0. Точная константа — зона A4 (калибровка c на train-only).
 *
 * Ресурс: SLOT_BUDGET (cap попыток, единственная ручка по pre-reg
 * partial-solution-slots). 30000 покрывает C(51,3)=20825 троек при 6 фичах.
 */
final class SlotCascade
{
    /**
     * Собрать SUM-каркасы из троек ×-форм. Возвращает [имя_формулы, вектор]
     * ЛУЧШЕЙ по cv формы (все passer'ы сравниваются, tie → короче имя).
     * Гейт вставки — cvTrainMax вызывающего (никаких новых порогов).
     *
     * @param array<string, array<float>> $slots снапшот ×-форм (имя → вектор)
     * @param array<float> $y цель
     * @param callable(array<float>, array<float>, float): float $cv функция CV
     * @param float $cvMax гейт вставки (cvTrainMax вызывающего)
     * @param float $shift affine-сдвиг цели (тот же, что у суда — иначе
     *   гейт и суд расходятся на офсетных задачах, review п.2)
     * @return array{0: string, 1: array<float>}|null
     */
    public static function assemble(
        array $slots,
        array $y,
        callable $cv,
        float $cvMax,
        float $shift = 0.0
    ): ?array {
        $budget = (int) (getenv('SLOT_BUDGET') ?: '30000');
        // A3: 30000 покрывает C(51,3)=20825 троек при 6 фичах (raw×raw +
        // raw×sq слоты). Широкие конфиги (>10 фич) — A2.1 structure probe,
        // бюджетом не решаются.
        if ($budget <= 0 || count($slots) < 3) {
            return null;
        }
        $n = count($y);
        $keys = array_keys($slots);
        $tries = 0;
        $count = count($keys);
        $best = null;      // ['cv' => float, 'name' => string, 'vec' => array]
        $bestLen = PHP_INT_MAX;
        // Канонический перебор (review deleg_7e5f6f82 п.1): k от j+1 —
        // каждое множество {i,j,k} проверяется один раз, имя детерминировано.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $vi = $slots[$keys[$i]];
                $vj = $slots[$keys[$j]];
                for ($k = $j + 1; $k < $count; $k++) {
                    if (++$tries > $budget) {
                        if (getenv('SEARCH_DEBUG') === '1') {
                            fwrite(STDERR, '[SD-SLOT] budget exhausted at tries=' . $tries . PHP_EOL);
                        }
                        break 3;
                    }
                    $vk = $slots[$keys[$k]];
                    $sum = [];
                    for ($r = 0; $r < $n; $r++) {
                        $sum[] = $vi[$r] + $vj[$r] + $vk[$r];
                    }
                    $c = $cv($sum, $y, $shift);
                    // Дегенераты (review deleg_ef90bd3f п.1): NaN проходит
                    // '$c > $cvMax' как false и застревает в best навсегда
                    // (все сравнения с NaN ложны) — фильтр явный.
                    if (! is_finite($c) || $c > $cvMax) {
                        continue;
                    }
                    $name = "((({$keys[$i]}+{$keys[$j]})+{$keys[$k]}))";
                    // Best-by-cv (premortem H1): первая passer-тройка не
                    // блокирует точную. Tie → короче (parsimony-симметрия).
                    if ($best === null || $c < $best['cv']
                        || ($c === $best['cv'] && strlen($name) < $bestLen)) {
                        $best = [
                            'cv' => $c,
                            'name' => $name,
                            'vec' => $sum,
                        ];
                        $bestLen = strlen($name);
                    }
                }
            }
        }

        return $best === null ? null : [$best['name'], $best['vec']];
    }
}
