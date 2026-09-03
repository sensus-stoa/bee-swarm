<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;

/**
 * Contradiction detector (протокол §2.5.3, диссипативный контур).
 *
 * Критерий: две пчелы нашли РАЗНЫЕ формулы для одной задачи, обе
 * CV ≤ epsExact (exact-класс) → противоречие. D_diff — подмножество
 * строк, где |f_A(x) − f_B(x)| > deltaDiff.
 *
 * Роль в контуре: observation-only. Не блокирует discovery, не удаляет
 * законы — только эмитит событие (потребитель: resolution-задачи §2.5.3,
 * атом-penalty §2.5.6).
 *
 * Один эвалюатор: формулы считаются через ExpressionEvaluator (единственный
 * вычислитель выражений в системе; Grammar/AtomRegistry асимметрия — питфолл).
 */
final class ContradictionDetector
{
    public function __construct(
        private readonly float $epsExact,
        private readonly float $deltaDiff,
        private readonly float $deltaRel = 0.0,
    ) {
    }

    /**
     * @param array<int,array<int,float>> $task строки задачи [f0, f1, ..., y] — числовые индексы (контракт ExpressionEvaluator)
     * @param array<int,array{formula:string, cv:float}> $candidates
     * @return array{diff_rows: array<int,array<int,float>>, candidates: array}|null
     *         null = противоречия нет
     */
    public function detect(array $task, array $candidates): ?array
    {
        // exact-класс: только кандидаты с cv ≤ epsExact
        $exact = array_values(array_filter(
            $candidates,
            fn (array $c): bool => $c['cv'] <= $this->epsExact
        ));
        if (count($exact) < 2) {
            return null;
        }

        // пары exact-кандидатов; нормализованные формы считаются ОДИН раз (premortem #4: O(N²)·parse → O(N))
        $norms = array_map(
            fn (array $c): string => ExpressionNormalizer::normalize($c['formula']),
            $exact
        );

        $n = count($exact);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $exact[$i];
                $b = $exact[$j];
                if ($norms[$i] === $norms[$j]) {
                    continue; // коммутативные близнецы — одна формула
                }
                $va = $this->evaluate($a['formula'], $task);
                $vb = $this->evaluate($b['formula'], $task);
                if ($va === null || $vb === null) {
                    continue; // невычислимая формула — не противоречие, её судьбу решает гейт
                }
                $diffRows = $this->diffRows($task, $va, $vb, max(abs($va[$i] ?? 0), abs($vb[$j] ?? 0)));
                if ($diffRows === []) {
                    continue; // дивергенция < δ на всех строках — эквивалентны
                }
                return [
                    'diff_rows' => $diffRows,
                    'candidates' => [
                        ['formula' => $a['formula'], 'norm' => $norms[$i], 'cv' => $a['cv']],
                        ['formula' => $b['formula'], 'norm' => $norms[$j], 'cv' => $b['cv']],
                    ],
                    'task_fingerprint' => md5(json_encode(array_slice($task[0] ?? [], 0, -1))),
                ];
            }
        }

        return null;
    }

    /** Коммутативные близнецы не дают противоречия: (x0×x1) и (x1×x0) — одна формула. */
    private function sameFormula(string $fa, string $fb): bool
    {
        return ExpressionNormalizer::normalize($fa) === ExpressionNormalizer::normalize($fb);
    }

    /**
     * Значения формулы по строкам задачи. Колонки — именованные (x0, x1, y...),
     * ExpressionEvaluator принимает rows с числовыми индексами [f0, f1, ..., y].
     *
     * @param array<int,array<int,float>> $task
     * @return array<int,float>|null null = формула невычислима/NaN/INF
     */
    private function evaluate(string $formula, array $task): ?array
    {
        $vec = ExpressionEvaluator::evaluateFormula($formula, $task);
        if ($vec === null) {
            return null;
        }
        foreach ($vec as $v) {
            if (! is_finite($v)) {
                return null; // NaN/INF — питфолл exact-match (05.08)
            }
        }
        return $vec;
    }

    /**
     * D_diff: строки, где |f_A − f_B| > max(δ_abs, δ_rel × масштаб).
     * Смешанный порог: абсолютный для малых величин, относительный —
     * иначе на данных масштаба 10^4 любая пара даёт шторм событий,
     * на масштабе 10^-3 — вечная тишина (premortem #2).
     *
     * @param array<int,array<int,float>> $task
     * @param array<int,float> $va
     * @param array<int,float> $vb
     * @param float $scale max(|f_A|,|f_B|) на строке — для относительной части порога
     * @return array<int,array<int,float>>
     */
    private function diffRows(array $task, array $va, array $vb, float $scale): array
    {
        $threshold = $this->deltaDiff + $this->deltaRel * $scale;
        $rows = [];
        $nVec = min(count($va), count($vb), count($task));
        for ($i = 0; $i < $nVec; $i++) {
            if (abs($va[$i] - $vb[$i]) > $threshold) {
                $rows[] = $task[$i];
            }
        }
        return $rows;
    }
}
