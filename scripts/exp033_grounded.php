<?php
declare(strict_types=1);

/**
 * EXP-033: Residual-Guided Grounded Graph — 4 режима сравнения.
 *
 * A. Random symbolic search (baseline)
 * B. Operator graph only (spreading activation, EXP-032 контроль)
 * C. Residual-guided only (one-step gain, без graph prior)
 * D. Residual + operator graph (graph сужает, residual выбирает переменные)
 *
 * Только heat. 20 seeds. Frozen splits. Budget 30s/seed.
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\ExpressionEvaluator;

const SEEDS = 20;
const BUDGET = 30.0;
const TOP_K_ATOMS = 20;
const BEAM_K = 20;
const ALPHA = 0.6;
const HOPS = 2;

// ── Реальный граф из 529 законов ──
$GRAPH = [
    '−' => ['/' => 0.478, '+' => 0.087, 'Rrange' => 0.087],
    '/' => ['Rmin' => 0.182, 'R+' => 0.182, '+' => 0.182, 'Rmax' => 0.159, '−' => 0.068, 'max' => 0.068],
    'R+' => ['+' => 0.889, '−' => 0.111],
    'R×' => ['/' => 1.0],
    '+' => ['/' => 0.273, 'R+' => 0.182, '×' => 0.091, 'max' => 0.091],
    'Rmax' => ['−' => 0.5, '+' => 0.5],
    '×' => ['/' => 0.2, '×' => 0.1, '+' => 0.1, '−' => 0.1, 'max' => 0.1, 'sq' => 0.1, 'min' => 0.1],
    'min' => ['+' => 0.125, '×' => 0.125, '−' => 0.125, '/' => 0.125, 'max' => 0.125, 'sq' => 0.125],
    'max' => ['+' => 0.2, '/' => 0.2, '×' => 0.1, 'min' => 0.1, 'sq' => 0.1],
    'sq' => ['max' => 0.143, '/' => 0.143, '+' => 0.143, '−' => 0.143, '×' => 0.143, 'min' => 0.143],
    'Rmin' => ['−' => 0.5, '+' => 0.5],
    'add' => ['max' => 0.2, '+' => 0.2, '−' => 0.1, 'sq' => 0.1, '×' => 0.1, 'min' => 0.1, '/' => 0.1],
    'mul' => ['+' => 0.125, '−' => 0.125, 'max' => 0.125, 'sq' => 0.125, 'add' => 0.125, '/' => 0.125, '×' => 0.125, 'min' => 0.125],
];

// ── Утилиты ──
function loadHeat(string $path): array {
    $X = []; $y = [];
    foreach (file($path) as $line) {
        $p = array_map('floatval', explode(',', trim($line)));
        if (count($p) < 6) continue;
        $X[] = array_slice($p, 0, 5);
        $y[] = $p[5];
    }
    return [$X, $y];
}

function split(array $X, array $y, int $seed): array {
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
        array_map(fn($i) => $X[$i], $tr),
        array_map(fn($i) => $y[$i], $tr),
        array_map(fn($i) => $X[$i], $te),
        array_map(fn($i) => $y[$i], $te),
    ];
}

function cvShift(array $pred, array $y): float {
    $eps = 1e-9;
    $shift = min(min($pred), min($y)) - 1.0;
    $ratio = [];
    foreach ($pred as $i => $p) {
        $den = abs($y[$i] - $shift) + $eps;
        if ($den < $eps) return INF;
        $ratio[] = abs(($p - $shift) / ($y[$i] - $shift));
    }
    $m = array_sum($ratio) / count($ratio);
    if (abs($m) < $eps) return INF;
    $var = 0.0;
    foreach ($ratio as $r) $var += ($r - $m) ** 2;
    return sqrt($var / count($ratio)) / abs($m);
}

function medianA(array $a): float {
    sort($a); $n = count($a); $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
}

function percentileA(array $a, int $p): float {
    sort($a); $n = count($a);
    $idx = (int) ceil($p / 100 * $n) - 1;
    return $a[max(0, min($n - 1, $idx))];
}

// ── Генерация B-атомов (все пары × 4 ops + inverse) ──
function genBAtoms(array $Xtr): array {
    $nFeat = count($Xtr[0]);
    $n = count($Xtr);
    $atoms = [];
    for ($i = 0; $i < $nFeat; $i++) {
        for ($j = $i + 1; $j < $nFeat; $j++) {
            foreach (['−', '×', '/', '+'] as $op) {
                $ci = array_column($Xtr, $i);
                $cj = array_column($Xtr, $j);
                $vec = [];
                $ok = true;
                for ($k = 0; $k < $n; $k++) {
                    $v = match ($op) {
                        '−' => $ci[$k] - $cj[$k],
                        '×' => $ci[$k] * $cj[$k],
                        '/' => (abs($cj[$k]) > 1e-12) ? $ci[$k] / $cj[$k] : NAN,
                        '+' => $ci[$k] + $cj[$k],
                    };
                    if (!is_finite($v)) { $ok = false; break; }
                    $vec[] = $v;
                }
                if ($ok) $atoms[] = ['op' => $op, 'i' => $i, 'j' => $j, 'vec' => $vec];
            }
        }
    }
    // inverse: 1/xi
    for ($i = 0; $i < $nFeat; $i++) {
        $ci = array_column($Xtr, $i);
        $vec = [];
        $ok = true;
        for ($k = 0; $k < $n; $k++) {
            if (abs($ci[$k]) < 1e-12) { $ok = false; break; }
            $vec[] = 1.0 / $ci[$k];
        }
        if ($ok) $atoms[] = ['op' => 'inv', 'i' => $i, 'j' => -1, 'vec' => $vec];
    }
    return $atoms;
}

// ── Cheap screening: ΔLoss для каждого B-атома ──
function screenAtoms(array $atoms, array $ePred, array $y): array {
    $cvE = cvShift($ePred, $y);
    foreach ($atoms as &$a) {
        $bestDelta = -INF;
        foreach (['×', '/', '+', '−'] as $op) {
            $pred = [];
            $ok = true;
            foreach ($ePred as $k => $ev) {
                $zv = $a['vec'][$k];
                $v = match ($op) {
                    '×' => $ev * $zv,
                    '/' => (abs($zv) > 1e-12) ? $ev / $zv : NAN,
                    '+' => $ev + $zv,
                    '−' => $ev - $zv,
                };
                if (!is_finite($v)) { $ok = false; break; }
                $pred[] = $v;
            }
            if (!$ok) continue;
            $cv = cvShift($pred, $y);
            if ($cv < $cvE && ($cvE - $cv) > $bestDelta) {
                $bestDelta = $cvE - $cv;
            }
        }
        $a['delta'] = is_finite($bestDelta) ? $bestDelta : -INF;
    }
    usort($atoms, fn($a, $b) => $b['delta'] <=> $a['delta']);
    return $atoms;
}

// ── Graph prior: после op, какие ops наиболее вероятны? ──
function graphPrior(string $lastOp, array $graph): array {
    if (!isset($graph[$lastOp])) return ['×' => 0.5, '/' => 0.3, '+' => 0.1, '−' => 0.1];
    return $graph[$lastOp];
}

// ═══ 4 РЕЖИМА ═══

function modeA(array $Xtr, array $ytr, array $Xte, array $yte): float {
    // Random symbolic search (baseline)
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $res = Search::find($Xtr, $ytr, $g, 3, null, 0.0, 0.15, BUDGET);
    if (!$res[0] || $res[1] >= 9.0) return 9.99;
    $st = ExpressionEvaluator::collectStats($res[2], $Xtr);
    $pTe = ExpressionEvaluator::evaluateFormula($res[2], $Xte, $st);
    return ($pTe !== null && count($pTe) === count($yte)) ? cvShift($pTe, $yte) : 9.99;
}

function modeB(array $Xtr, array $ytr, array $Xte, array $yte, array $graph): float {
    // Operator graph only (EXP-032 контроль)
    $seedOps = ['−', '×', '/'];
    $activated = [];
    foreach ($seedOps as $op) $activated[$op] = 1.0;
    for ($h = 0; $h < HOPS; $h++) {
        $new = $activated;
        foreach ($activated as $op => $act) {
            if (!isset($graph[$op])) continue;
            foreach ($graph[$op] as $nb => $w) {
                $new[$nb] = max($new[$nb] ?? 0.0, $act * ALPHA * $w);
            }
        }
        $activated = $new;
    }
    // Генерация B-атомов только из активированных ops
    $atoms = genBAtoms($Xtr);
    $filtered = [];
    foreach ($atoms as $a) {
        if (isset($activated[$a['op']]) && $activated[$a['op']] >= 0.1) {
            $filtered[] = $a;
        }
    }
    if (empty($filtered)) return 9.99;
    // Top-K по random score (graph не даёт task-specific ranking!)
    $filtered = array_slice($filtered, 0, TOP_K_ATOMS);
    return expandAndSearch($filtered, $Xtr, $ytr, $Xte, $yte);
}

function modeC(array $Xtr, array $ytr, array $Xte, array $yte): float {
    // Residual-guided only (без graph prior)
    $atoms = genBAtoms($Xtr);
    $meanY = array_fill(0, count($ytr), array_sum($ytr) / count($ytr));
    $screened = screenAtoms($atoms, $meanY, $ytr);
    $top = array_slice($screened, 0, TOP_K_ATOMS);
    return expandAndSearch($top, $Xtr, $ytr, $Xte, $yte);
}

function modeD(array $Xtr, array $ytr, array $Xte, array $yte, array $graph): float {
    // Residual + operator graph (D = C + graph prior)
    $atoms = genBAtoms($Xtr);
    $meanY = array_fill(0, count($ytr), array_sum($ytr) / count($ytr));
    $screened = screenAtoms($atoms, $meanY, $ytr);

    // Graph prior boost: если op атома имеет высокий prior → boost score
    foreach ($screened as &$a) {
        $prior = graphPrior($a['op'], $graph);
        $boost = 0.0;
        foreach ($prior as $nextOp => $w) {
            $boost = max($boost, $w);
        }
        $a['score'] = $a['delta'] * (1.0 + 0.5 * $boost); // prior boost 50%
    }
    usort($screened, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = array_slice($screened, 0, TOP_K_ATOMS);
    return expandAndSearch($top, $Xtr, $ytr, $Xte, $yte);
}

function expandAndSearch(array $atoms, array $Xtr, array $ytr, array $Xte, array $yte): float {
    if (empty($atoms)) return 9.99;
    // Расширяем X: добавляем top atoms как колонки
    $nFeat = count($Xtr[0]);
    $XtrExt = [];
    foreach ($Xtr as $k => $row) {
        $r = $row;
        foreach ($atoms as $a) { $r[] = $a['vec'][$k]; }
        $XtrExt[] = $r;
    }
    $XteExt = [];
    foreach ($Xte as $k => $row) {
        $r = $row;
        foreach ($atoms as $a) {
            $ci = array_column($Xte, $a['i']);
            $cj = array_column($Xte, $a['j']);
            $v = match ($a['op']) {
                '−' => $ci[$k] - $cj[$k],
                '×' => $ci[$k] * $cj[$k],
                '/' => (abs($cj[$k]) > 1e-12) ? $ci[$k] / $cj[$k] : 0.0,
                '+' => $ci[$k] + $cj[$k],
                'inv' => (abs($ci[$k]) > 1e-12) ? 1.0 / $ci[$k] : 0.0,
                default => 0.0,
            };
            $r[] = $v;
        }
        $XteExt[] = $r;
    }
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $res = Search::find($XtrExt, $ytr, $g, 3, null, 0.0, 0.15, BUDGET);
    if (!$res[0] || $res[1] >= 9.0) return 9.99;
    $st = ExpressionEvaluator::collectStats($res[2], $XtrExt);
    $pTe = ExpressionEvaluator::evaluateFormula($res[2], $XteExt, $st);
    return ($pTe !== null && count($pTe) === count($yte)) ? cvShift($pTe, $yte) : 9.99;
}

// ═══ MAIN ═══
$base = __DIR__ . '/..';
[$Xall, $yall] = loadHeat($base . '/data/feynman_heat_conduction.csv');

echo "=== EXP-033: Residual-Guided Grounded Graph (heat) ===\n";
echo "4 modes: A(random) B(graph-only) C(residual-only) D(residual+graph)\n";
echo "seeds=" . SEEDS . " budget=" . BUDGET . "s K=" . TOP_K_ATOMS . "\n\n";

$results = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
for ($s = 1; $s <= SEEDS; $s++) {
    [$Xtr, $ytr, $Xte, $yte] = split($Xall, $yall, $s);

    $cvA = modeA($Xtr, $ytr, $Xte, $yte);
    $cvB = modeB($Xtr, $ytr, $Xte, $yte, $GRAPH);
    $cvC = modeC($Xtr, $ytr, $Xte, $yte);
    $cvD = modeD($Xtr, $ytr, $Xte, $yte, $GRAPH);

    $results['A'][] = $cvA;
    $results['B'][] = $cvB;
    $results['C'][] = $cvC;
    $results['D'][] = $cvD;

    $markA = $cvA <= 0.10 ? '✓' : '✗';
    $markB = $cvB <= 0.10 ? '✓' : '✗';
    $markC = $cvC <= 0.10 ? '✓' : '✗';
    $markD = $cvD <= 0.10 ? '✓' : '✗';
    echo "  seed {$s}: A={$markA}(" . round($cvA, 3) . ") B={$markB}(" . round($cvB, 3)
        . ") C={$markC}(" . round($cvC, 3) . ") D={$markD}(" . round($cvD, 3) . ")\n";
}

echo "\n=== ИТОГ EXP-033 ===\n";
foreach (['A', 'B', 'C', 'D'] as $mode) {
    $cvs = $results[$mode];
    $acc = count(array_filter($cvs, fn($c) => $c <= 0.10));
    echo "  Mode {$mode}: success={$acc}/" . SEEDS
        . " median=" . round(medianA($cvs), 4)
        . " q05=" . round(percentileA($cvs, 5), 4)
        . " q95=" . round(percentileA($cvs, 95), 4) . "\n";
}
echo "\nDONE\n";
