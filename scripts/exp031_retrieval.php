<?php
declare(strict_types=1);

/**
 * EXP-031: Top-K Residual Cultural Retrieval (предрегистрирован 26.08).
 *
 * Только feynman_heat. 20 seeds. B-атомы НЕ колонки:
 * итеративный цикл search → residual → rank candidates → top-3 → extend.
 * Max 2 cultural steps, бюджет 30s/seed суммарно.
 *
 * Запуск: php scripts/exp031_retrieval.php
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\ExpressionEvaluator;

const BUDGET = 30.0;
const N_SEEDS = 20;
const TOP_K = 3;

function loadHeat(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $p = array_map('floatval', explode(',', trim($line)));
        if (count($p) < 6) {
            continue;
        }
        $X[] = array_slice($p, 0, 5);
        $y[] = $p[5];
    }
    return [$X, $y];
}

function splitC(array $X, array $y, int $seed): array
{
    mt_srand($seed);
    $n = count($y);
    $idx = range(0, $n - 1);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $nTr = (int) floor($n * 0.6);
    $tr = array_slice($idx, 0, $nTr);
    $te = array_slice($idx, $nTr);
    return [
        array_map(fn ($i) => $X[$i], $tr),
        array_map(fn ($i) => $y[$i], $tr),
        array_map(fn ($i) => $X[$i], $te),
        array_map(fn ($i) => $y[$i], $te),
    ];
}

/** CV с AFFINE-shift (как в cv()) */
function cvR(array $pred, array $y): float
{
    $eps = 1e-9;
    $shift = min(min($pred), min($y)) - 1.0;
    $ratio = [];
    foreach ($pred as $i => $p) {
        $den = abs($y[$i] - $shift) + $eps;
        if ($den < $eps) {
            return INF;
        }
        $ratio[] = abs(($p - $shift) / ($y[$i] - $shift));
    }
    $m = array_sum($ratio) / count($ratio);
    if (abs($m) < $eps) {
        return INF;
    }
    $var = 0.0;
    foreach ($ratio as $r) {
        $var += ($r - $m) ** 2;
    }
    return sqrt($var / count($ratio)) / abs($m);
}

/**
 * Генерация всех B-кандидатов SUB/MUL пар фич на train.
 * Возвращает [['op'=>, 'i'=>, 'j'=>, 'vec'=>], ...]
 */
function genCandidates(array $Xtr): array
{
    $nFeat = count($Xtr[0]);
    $n = count($Xtr);
    $out = [];
    for ($i = 0; $i < $nFeat; $i++) {
        for ($j = $i + 1; $j < $nFeat; $j++) {
            foreach (['SUB', 'MUL'] as $op) {
                $ci = array_column($Xtr, $i);
                $cj = array_column($Xtr, $j);
                $vec = [];
                $ok = true;
                for ($k = 0; $k < $n; $k++) {
                    $v = ($op === 'SUB') ? ($ci[$k] - $cj[$k]) : ($ci[$k] * $cj[$k]);
                    if (! is_finite($v)) {
                        $ok = false;
                        break;
                    }
                    $vec[] = $v;
                }
                if ($ok) {
                    $out[] = ['op' => $op, 'i' => $i, 'j' => $j, 'vec' => $vec];
                }
            }
        }
    }
    return $out;
}

/** Score(z | residual r): min по операторам применённым к r */
function scoreVsResidual(array $z, array $r): float
{
    $best = INF;
    // r/z, z/r, r*z, r+z, r−z
    foreach (['div', 'rdiv', 'mul', 'add', 'sub'] as $mode) {
        $pred = [];
        $ok = true;
        foreach ($r as $i => $rv) {
            $zv = $z['vec'][$i];
            $v = match ($mode) {
                'div' => (abs($zv) > 1e-12) ? $rv / $zv : INF,
                'rdiv' => (abs($rv) > 1e-12) ? $zv / $rv : INF,
                'mul' => $rv * $zv,
                'add' => $rv + $zv,
                'sub' => $rv - $zv,
            };
            if (! is_finite($v)) {
                $ok = false;
                break;
            }
            $pred[] = $v;
        }
        if (! $ok) {
            continue;
        }
        $cv = cvR($pred, $GLOBALS['current_ytr']);
        if ($cv < $best) {
            $best = $cv;
        }
    }
    return $best;
}

// ═══ ОСНОВНОЙ ПРОГОН ═══
$base = __DIR__ . '/..';
[$X, $y] = loadHeat($base . '/data/feynman_heat_conduction.csv');

echo "=== EXP-031: Top-K Residual Cultural Retrieval (heat only) ===\n";
echo "K=" . TOP_K . ", max_steps=2, budget=" . BUDGET . "s/seed, seeds=" . N_SEEDS . "\n";

$cvsAll = [];
for ($s = 1; $s <= N_SEEDS; $s++) {
    [$Xtr, $ytr, $Xte, $yte] = splitC($X, $y, $s);
    $GLOBALS['current_ytr'] = $ytr;

    $deadline = microtime(true) + BUDGET;
    $stepLog = [];

    // ── Шаг 0: базовый поиск depth 2 ──
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $res0 = Search::find($Xtr, $ytr, $g, 2, null, 0.0, 0.15, max(1.0, $deadline - microtime(true)));
    $bestFormula = null;
    $bestCvTrain = 9.99;

    if ($res0[0] && $res0[1] < 9.0) {
        // Проверка holdout
        $stats0 = ExpressionEvaluator::collectStats($res0[2], $Xtr);
        $pTe = ExpressionEvaluator::evaluateFormula($res0[2], $Xte, $stats0);
        $cvTe = ($pTe !== null && count($pTe) === count($yte)) ? cvR($pTe, $yte) : 9.99;
        if ($cvTe <= 0.10) {
            echo "  seed {$s}: инвариант найден БЕЗ культуры: {$res0[2]} CV_H={$cvTe}\n";
            $cvsAll[] = $cvTe;
            continue;
        }
        $bestFormula = $res0[2];
        $bestCvTrain = $res0[1];
        $stepLog[] = "base={$res0[2]}({$bestCvTrain})";
    }

    // ── Культурные кандидаты ──
    $candidates = genCandidates($Xtr);

    // ── Шаги 1..2: residual retrieval + extension ──
    $usedCandidates = [];
    $currentBestPred = null;
    if ($bestFormula !== null) {
        $st = ExpressionEvaluator::collectStats($bestFormula, $Xtr);
        $currentBestPred = ExpressionEvaluator::evaluateFormula($bestFormula, $Xtr, $st);
    }

    for ($step = 1; $step <= 2; $step++) {
        if (microtime(true) > $deadline) {
            break;
        }
        // residual/ratio относительно лучшего candidate (или y если нет)
        $r = ($currentBestPred !== null) ? $currentBestPred : $ytr;

        // Ранжирование кандидатов vs residual
        $scored = [];
        foreach ($candidates as $ci => $cand) {
            if (in_array($ci, $usedCandidates, true)) {
                continue;
            }
            $sc = scoreVsResidual($cand, $r);
            if (is_finite($sc)) {
                $scored[$ci] = $sc;
            }
        }
        asort($scored);
        $topK = array_slice(array_keys($scored), 0, TOP_K);
        if (! $topK) {
            break;
        }
        $usedCandidates = array_merge($usedCandidates, $topK);

        // Расширение X: добавляем z-колонки top-K
        // ВАЖНО: каждая строка — КОПИЯ исходной + все z-значения сразу
        $nFeat = count($Xtr[0]);
        $provDesc = [];
        $zDescs = [];
        foreach ($topK as $ci) {
            $cand = $candidates[$ci];
            $zDescs[$ci] = "{$cand['op']}(x{$cand['i']},x{$cand['j']})";
        }
        $Xext = [];
        foreach ($Xtr as $row) {
            $r2 = $row;
            foreach ($topK as $ci) {
                $cand = $candidates[$ci];
                $r2[] = ($cand['op'] === 'SUB')
                    ? ($row[$cand['i']] - $row[$cand['j']])
                    : ($row[$cand['i']] * $row[$cand['j']]);
            }
            $Xext[] = $r2;
        }

        // Search depth 3 на расширенном X
        $resN = Search::find($Xext, $ytr, $g, 3, null, 0.0, 0.15, max(1.0, $deadline - microtime(true)));
        if ($resN[0] && $resN[1] < 9.0) {
            // Подстановка z→исходные фичи для holdout-eval
            $formulaZ = str_replace(
                array_map(fn ($i) => 'x' . ($nFeat + $i), array_keys($topK)),
                array_map(fn ($ci) => 'Z' . $ci, $topK),
                $resN[2]
            );
            // Вычисляем holdout pred через z-вектора
            $XteExt = [];
            foreach ($Xte as $k => $row) {
                $r2 = $row;
                foreach ($topK as $zi => $ci) {
                    $cand = $candidates[$ci];
                    $va = $row[$cand['i']];
                    $vb = $row[$cand['j']];
                    $r2[] = ($cand['op'] === 'SUB') ? ($va - $vb) : ($va * $vb);
                }
                $XteExt[] = $r2;
            }
            $statsN = ExpressionEvaluator::collectStats($resN[2], $Xext);
            $pTe = ExpressionEvaluator::evaluateFormula($resN[2], $XteExt, $statsN);
            $cvTe = ($pTe !== null && count($pTe) === count($yte)) ? cvR($pTe, $yte) : 9.99;
            $stepLog[] = "s{$step}: {$resN[2]} → CV_H=" . round($cvTe, 4)
                . " [" . implode(',', array_slice($zDescs, 0, 2, true)) . "]";

            if ($cvTe <= 0.10) {
                echo "  seed {$s}: ИНВАРИАНТ через культуру (шаг {$step}): CV_H={$cvTe}\n";
                echo "    формула(z): {$resN[2]}\n";
                $cvsAll[] = $cvTe;
                continue 2; // следующий seed
            }
            // Обновить best для следующего шага
            if ($cvTe < $bestCvTrain || $currentBestPred === null) {
                $currentBestPred = $pTe ?? $currentBestPred;
            }
        } else {
            $stepLog[] = "s{$step}: поиск без находки (" . $resN[4] . ")";
        }
    }

    if (! isset($cvsAll[count($cvsAll) - 1]) || ! isset($stepLog)) {
        $cvsAll[] = 9.99;
    }
    // Если инвариант не найден — отказ
    if (empty($cvsAll) || end($cvsAll) === 9.99) {
        $cvsAll[] = 9.99;
    }
    echo "  seed {$s}: отказ. " . implode('; ', $stepLog) . "\n";
}

$accepted = array_filter($cvsAll, fn ($c) => $c <= 0.10);
echo "\n=== ИТОГ ===\n";
$vals = array_values($cvsAll);
sort($vals);
$med = $vals[intdiv(count($vals), 2)];
echo "success=" . count($accepted) . "/" . N_SEEDS . "\n";
if ($accepted) {
    echo "CV_H найденных: " . implode(', ', array_map(fn ($c) => round($c, 4), $accepted)) . "\n";
}
echo ($accepted ? count($accepted) >= 8 ? '✅ МЕХАНИЗМ ЖИЗНЕСПОСОБЕН' : '⚠️ частичный' : '❌ conditional retrieval не сработал') . "\n";
