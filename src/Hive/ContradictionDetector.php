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

        // пары exact-кандидатов; первая структурная пара с непустым D_diff — событие
        $n = count($exact);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $exact[$i];
                $b = $exact[$j];
                if ($this->sameFormula($a['formula'], $b['formula'])) {
                    continue;
                }
                $va = $this->evaluate($a['formula'], $task);
                $vb = $this->evaluate($b['formula'], $task);
                if ($va === null || $vb === null) {
                    continue; // невычислимая формула — не противоречие, её судьбу решает гейт
                }
                $diffRows = $this->diffRows($task, $va, $vb);
                if ($diffRows === []) {
                    continue; // дивергенция < δ на всех строках — эквивалентны
                }
                return [
                    'diff_rows' => $diffRows,
                    'candidates' => [
                        ['formula' => $a['formula'], 'cv' => $a['cv']],
                        ['formula' => $b['formula'], 'cv' => $b['cv']],
                    ],
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
     * ExpressionEvaluator принимает rows вида array<string,float>.
     *
     * @param array<int,array<string,float>> $task
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
     * D_diff: строки, где |f_A − f_B| > δ.
     *
     * @param array<int,array<int,float>> $task
     * @param array<int,float> $va
     * @param array<int,float> $vb
     * @return array<int,array<int,float>>
     */
    private function diffRows(array $task, array $va, array $vb): array
    {
        $rows = [];
        foreach ($task as $i => $row) {
            if (! isset($va[$i], $vb[$i])) {
                continue;
            }
            if (abs($va[$i] - $vb[$i]) > $this->deltaDiff) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
