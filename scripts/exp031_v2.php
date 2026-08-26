<?php
declare(strict_types=1);

/**
 * EXP-031-v2: Contextual Cultural Retrieval — ПОЛНАЯ формула relevance.
 *
 * R(z|e) = ΔCV_inner(z|e) − λ·C(z) − μ·D(z,A) + η·P(z|context)
 *
 * Константы (заморожены): λ=0.01, μ=0.05, η=0.1, K=2, B=3, cycles=2.
 * Потоковый отбор (O(n) памяти), holdout изолирован, только heat.
 *
 * Запуск: php scripts/exp031_v2.php
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\ExpressionEvaluator;

// ── Замороженные константы ──
const LAMBDA_C = 0.01;   // complexity penalty
const MU_D = 0.05;       // redundancy penalty
const ETA_P = 0.1;       // cultural prior boost
const K_TOP = 2;         // extensions per step
const B_HYP = 3;         // top hypotheses
const CYCLES = 2;
const BUDGET_V2 = 30.0;
const SEEDS_V2 = 20;

function loadHeatV2(string $path): array
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

function splitV2(array $X, array $y, int $seed): array
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

function cvShift(array $pred, array $y): float
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

function pearsonV2(array $a, array $b): float
{
    $n = count($a);
    $ma = array_sum($a) / $n;
    $mb = array_sum($b) / $n;
    $num = 0.0;
    $da = 0.0;
    $dbv = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $na = $a[$i] - $ma;
        $nb = $b[$i] - $mb;
        $num += $na * $nb;
        $da += $na * $na;
        $dbv += $nb * $nb;
    }
    $den = sqrt($da * $dbv);
    return $den > 0 ? $num / $den : 0.0;
}

/**
 * Полная relevance: R(z|e) = ΔCV − λC − μD + ηP
 * Потоково: vec вычисляется на лету, top-K обновляется инкрементально.
 */
function retrieveTopK(
    string $template,
    array $Xtr,
    array $ePred,
    float $cvE,
    array $activeVecs,
    int $nFeat,
    float $priorWeight,
    int $k
): array {
    $n = count($ePred);
    $cands = [];
    for ($i = 0; $i < $nFeat; $i++) {
        for ($j = $i + 1; $j < $nFeat; $j++) {
            // Потоково: считаем z-vec и все 4 операции сразу
            $bestDelta = -INF;
            $bestPred = null;
            $ci = array_column($Xtr, $i);
            $cj = array_column($Xtr, $j);
            $zvec = [];
            for ($r = 0; $r < $n; $r++) {
                $zv = ($template === 'SUB') ? ($ci[$r] - $cj[$r]) : ($ci[$r] * $cj[$r]);
                $zvec[] = $zv;
                foreach (
                    [
                        'mul' => $ePred[$r] * $zv,
                        'div' => (abs($zv) > 1e-12) ? $ePred[$r] / $zv : null,
                        'rdiv' => (abs($ePred[$r]) > 1e-12) ? $zv / $ePred[$r] : null,
                        'add' => $ePred[$r] + $zv,
                    ] as $mode => $candidate
                ) {
                    if ($candidate === null || ! is_finite($candidate)) {
                        continue;
                    }
                    $predTry = [];
                    for ($q = 0; $q < $n; $q++) {
                        $zvq = ($template === 'SUB') ? ($ci[$q] - $cj[$q]) : ($ci[$q] * $cj[$q]);
                        $predTry[] = match ($mode) {
                            'mul' => $ePred[$q] * $zvq,
                            'div' => (abs($zvq) > 1e-12) ? $ePred[$q] / $zvq : NAN,
                            'rdiv' => (abs($ePred[$q]) > 1e-12) ? $zvq / $ePred[$q] : NAN,
                            default => $ePred[$q] + $zvq,
                        };
                    }
                    if (in_array(NAN, $predTry, true)) {
                        continue;
                    }
                    $cvNew = cvShift($predTry, $GLOBALS['yy']);
                    if ($cvNew < $cvE && ($cvE - $cvNew) > $bestDelta) {
                        $bestDelta = $cvE - $cvNew;
                        $bestPred = $predTry;
                    }
                }
            }
            if (! is_finite($bestDelta)) {
                continue;
            }

            // C(z): strlen раскрытого атома
            $cz = strlen("({$template}(x{$i},x{$j}))") / 10;

            // D(z,A): max |ρ| к активным векторам
            $dz = 0.0;
            foreach ($activeVecs as $av) {
                $rho = abs(pearsonV2($zvec, $av));
                if ($rho > $dz) {
                    $dz = $rho;
                }
            }

            // P(z|context): prior — пока 0 (первый запуск), слот для reuse_count
            $pz = 0.0;

            $relevance = $bestDelta - LAMBDA_C * $cz - MU_D * $dz + ETA_P * $pz;
            $cands[] = [
                'op' => $template,
                'i' => $i,
                'j' => $j,
                'relevance' => $relevance,
                'delta' => $bestDelta,
                'pred' => $bestPred, // лучший предикт с этим z (для следующего шага!)
                'expanded' => "({$template}(x{$i},x{$j}))",
            ];
        }
    }
    usort($cands, fn ($a, $b) => $b['relevance'] <=> $a['relevance']);
    return array_slice($cands, 0, $k);
}

/** Раскрытие z-формулы */
function expandZV2(string $formula, array $prov): string
{
    krsort($prov);
    $out = $formula;
    foreach ($prov as $zn => $p) {
        $exp = "({$p['tpl']}({$p['i']},{$p['j']}))";
        $out = str_replace($zn, $exp, $out);
    }
    return $out;
}

// ═══ ОСНОВНОЙ ПРОГОН ═══
$base = __DIR__ . '/..';
[$Xall, $yall] = loadHeatV2($base . '/data/feynman_heat_conduction.csv');

echo "=== EXP-031-v2: Contextual Cultural Retrieval ===\n";
echo "R = ΔCV - λC - μD + ηP | λ=" . LAMBDA_C . " μ=" . MU_D . " η=" . ETA_P
    . " K=" . K_TOP . " B=" . B_HYP . " cycles=" . CYCLES . "\n";

$cvsAll = [];
for ($s = 1; $s <= SEEDS_V2; $s++) {
    [$Xtr, $ytr, $Xte, $yte] = splitV2($Xall, $yall, $s);
    $GLOBALS['yy'] = $ytr;
    $deadline = microtime(true) + BUDGET_V2;
    $found = false;
    $log = [];

    // ── Шаг 0: базовый Search depth 3 (нетронут!) ──
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $res0 = Search::find($Xtr, $ytr, $g, 3, null, 0.0, 0.15, max(1.0, $deadline - microtime(true)));
    if ($res0[0] && $res0[1] < 9.0) {
        $st0 = ExpressionEvaluator::collectStats($res0[2], $Xtr);
        $pTe0 = ExpressionEvaluator::evaluateFormula($res0[2], $Xte, $st0);
        $cvTe0 = ($pTe0 !== null && count($pTe0) === count($yte)) ? cvShift($pTe0, $yte) : 9.99;
        if ($cvTe0 <= 0.10) {
            echo "  seed {$s}: base-invariant {$res0[2]} CV_H={$cvTe0}\n";
            $cvsAll[] = $cvTe0;
            continue;
        }
        $log[] = "base NONE/CV>0.10";
    } else {
        $log[] = "base no-find";
    }

    // ── Циклы retrieval ──
    // Текущие hypotheses: e_pred = лучшее приближение y из найденного (или mean)
    $meanY = array_sum($ytr) / count($ytr);
    $hyps = [['pred' => array_fill(0, count($ytr), $meanY), 'formula' => "{$meanY}", 'cv' => INF]];
    if ($res0[0] && $res0[1] < 9.0) {
        $st = ExpressionEvaluator::collectStats($res0[2], $Xtr);
        $bp = ExpressionEvaluator::evaluateFormula($res0[2], $Xtr, $st);
        if ($bp !== null) {
            $hyps = [['pred' => $bp, 'formula' => $res0[2], 'cv' => $res0[1]]];
        }
    }

    $activatedVecs = []; // D(z,A)
    $provChain = [];

    for ($cycle = 1; $cycle <= CYCLES; $cycle++) {
        if (microtime(true) > $deadline) {
            break;
        }
        $stepFound = false;
        // Для каждой из топ-B гипотез — retrieval
        $newHyps = [];
        foreach (array_slice($hyps, 0, B_HYP) as $hi => $hyp) {
            foreach (['SUB', 'MUL'] as $tpl) {
                $top = retrieveTopK($tpl, $Xtr, $hyp['pred'], $hyp['cv'], $activatedVecs, count($Xtr[0]), ETA_P, K_TOP);
                foreach ($top as $t) {
                    // Новый candidate: гипотеза op z
                    // pred уже посчитан внутри retrieveTopK как bestPred
                    $newFormula = "({$hyp['formula']}{$t['op']}{$t['expanded']})";
                    $newHyps[] = ['pred' => $t['pred'], 'formula' => $newFormula, 'cv' => $hyp['cv'] - $t['delta']];
                    $provChain[] = ['zn' => "z{$cycle}{$hi}_" . $t['i'] . '_' . $t['j'], 'tpl' => $tpl, 'i' => $t['i'], 'j' => $t['j']];
                    $activatedVecs[] = $t['pred']; // D(z,A) растёт
                    $stepFound = true;
                }
            }
        }
        if (! $stepFound) {
            $log[] = "c{$cycle}: нет релевантных";
            break;
        }

        // Сортируем новые гипотезы по cv (оценка train)
        usort($newHyps, fn ($a, $b) => $a['cv'] <=> $b['cv']);
        $hyps = array_merge($hyps, array_slice($newHyps, 0, B_HYP));

        // Проверяем топ-гипотезу на holdout
        $bestH = $hyps[0];
        // Вычисляем holdout pred формулы через evaluator c раскрытием
        $expanded = expandZV2($bestH['formula'], $provChain);
        // Замена шаблонных выражений на x-индексы: (SUB(1,2)) → (x1-x2)
        foreach ($provChain as $pc) {
            $pat = "{$pc['tpl']}({$pc['i']},{$pc['j']})";
            $rep = ($pc['tpl'] === 'SUB') ? "(x{$pc['i']}-x{$pc['j']})" : "(x{$pc['i']}×x{$pc['j']})";
            $expanded = str_replace($pat, $rep, $expanded);
        }
        try {
            $stats = ExpressionEvaluator::collectStats($expanded, $Xtr);
            $pTe = ExpressionEvaluator::evaluateFormula($expanded, $Xte, $stats);
            $cvTe = ($pTe !== null && count($pTe) === count($yte)) ? cvShift($pTe, $yte) : 9.99;
        } catch (\Throwable) {
            $cvTe = 9.99;
        }
        if ($cvTe <= 0.10) {
            echo "  seed {$s}: ИНВАРИАНТ (цикл {$cycle}): CV_H={$cvTe}\n";
            echo "    expanded: {$expanded}\n";
            $cvsAll[] = $cvTe;
            $found = true;
            break;
        }
        $log[] = "c{$cycle}: best CV_H={$cvTe}";
    }

    if (! $found) {
        echo "  seed {$s}: отказ. " . implode('; ', array_slice($log, -2)) . "\n";
        $cvsAll[] = 9.99;
    }
}

$accepted = array_filter($cvsAll, fn ($c) => $c <= 0.10);
echo "\n=== ИТОГ EXP-031-v2 ===\n";
echo "success=" . count($accepted) . '/' . SEEDS_V2 . "\n";
if ($accepted) {
    sort($accepted);
    echo 'CV_H: ' . implode(', ', array_map(fn ($c) => round($c, 4), $accepted)) . "\n";
}
$verdict = count($accepted) >= 10 ? '✅ АРХИТЕКТУРНЫЙ МЕХАНИЗМ' : '❌ search paradigm несовместим';
echo $verdict . "\n";
