<?php
declare(strict_types=1);

/**
 * EXP-032: Spreading Activation Proof of Concept
 *
 * Минимальный эксперимент: предзаполненный граф полезных переходов
 * → spreading activation от features heat-задачи → top-K атомов
 * → Search depth 3 с этими атомами → heat ≥10/20?
 *
 * Граф предзаполнен СИНТЕТИЧЕСКИ (не из логов улья):
 *   SUB→MUL (.8), MUL→DIV (.7), SUB→DIV (.5)
 * Это proof of concept: если даже синтетический граф помогает —
 * механизм жизнеспособен, и реальный граф из логов будет ещё лучше.
 *
 * Запуск: php scripts/exp032_spreading.php
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\ExpressionEvaluator;

const SEEDS = 20;
const BUDGET = 30.0;
const TOP_K = 4;
const ALPHA = 0.6;  // скорость распространения
const HOPS = 2;     // количество hops

// ── Граф переходов (синтетический, заморожен ДО прогона) ──
// SUB→MUL: часто полезно (разность × что-то)
// MUL→DIV: часто полезно (произведение / что-то)
// SUB→DIV: иногда полезно
$GRAPH = [
    '/' => ['+' => 0.182, '−' => 0.068, 'max' => 0.068, 'Rmax' => 0.159, 'Rmin' => 0.182, 'R+' => 0.182],
    '+' => ['/' => 0.273, '×' => 0.091, 'max' => 0.091, 'R+' => 0.182, 'add' => 0.091],
    '−' => ['+' => 0.087, '/' => 0.478, 'Rrange' => 0.087],
    'R+' => ['+' => 0.889, '−' => 0.111],
    'max' => ['+' => 0.2, '×' => 0.1, 'min' => 0.1, '/' => 0.2, 'sq' => 0.1, 'mul' => 0.1, 'add' => 0.1, '−' => 0.1],
    '×' => ['×' => 0.1, '+' => 0.1, '−' => 0.1, 'max' => 0.1, 'sq' => 0.1, '/' => 0.2, 'min' => 0.1, 'add' => 0.1, 'mul' => 0.1],
    'min' => ['+' => 0.125, '×' => 0.125, '−' => 0.125, '/' => 0.125, 'max' => 0.125, 'sq' => 0.125, 'mul' => 0.125, 'add' => 0.125],
    'Rmax' => ['−' => 0.5, '+' => 0.5],
    'sq' => ['max' => 0.143, '/' => 0.143, '+' => 0.143, '−' => 0.143, 'add' => 0.143, '×' => 0.143, 'min' => 0.143],
    'Rmin' => ['−' => 0.5, '+' => 0.5],
    'add' => ['−' => 0.1, 'sq' => 0.1, '×' => 0.1, 'min' => 0.1, 'mul' => 0.1, 'max' => 0.2, '+' => 0.2, '/' => 0.1],
    'mul' => ['+' => 0.125, '−' => 0.125, 'max' => 0.125, 'sq' => 0.125, 'add' => 0.125, '/' => 0.125, '×' => 0.125, 'min' => 0.125],
    'R×' => ['/' => 1],
];

// ── Fingerprint задачи → начальная активация ──
function taskFingerprint(array $Xtr, array $ytr): array
{
    $n = count($ytr);
    $nFeat = count($Xtr[0]);

    // Pairwise correlations с y
    $corrs = [];
    for ($f = 0; $f < $nFeat; $f++) {
        $col = array_column($Xtr, $f);
        $my = array_sum($ytr) / $n;
        $mc = array_sum($col) / $n;
        $num = $da = $db = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $na = $col[$i] - $mc;
            $nb = $ytr[$i] - $my;
            $num += $na * $nb;
            $da += $na * $na;
            $db += $nb * $nb;
        }
        $den = sqrt($da * $db);
        $corrs[$f] = $den > 0 ? abs($num / $den) : 0.0;
    }

    // Monotonicity: rank correlation (Spearman-like)
    $monoScore = 0.0;
    for ($f = 0; $f < $nFeat; $f++) {
        $col = array_column($Xtr, $f);
        $inc = $dec = 0;
        for ($i = 1; $i < $n; $i++) {
            if ($col[$i] > $col[$i - 1]) $inc++;
            if ($col[$i] < $col[$i - 1]) $dec++;
        }
        $monoScore += abs($inc - $dec) / max(1, $inc + $dec);
    }
    $monoScore /= $nFeat;

    // Ratio structure: есть ли features с высоким corr(1/x, y)?
    $ratioScore = 0.0;
    for ($f = 0; $f < $nFeat; $f++) {
        $col = array_column($Xtr, $f);
        $inv = [];
        foreach ($col as $v) {
            $inv[] = (abs($v) > 1e-9) ? 1.0 / $v : 0.0;
        }
        $my = array_sum($ytr) / $n;
        $mi = array_sum($inv) / $n;
        $num = $da = $db = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $na = $inv[$i] - $mi;
            $nb = $ytr[$i] - $my;
            $num += $na * $nb;
            $da += $na * $na;
            $db += $nb * $nb;
        }
        $den = sqrt($da * $db);
        $ratioScore = max($ratioScore, $den > 0 ? abs($num / $den) : 0.0);
    }

    return [
        'max_corr' => max($corrs),
        'mono' => $monoScore,
        'ratio' => $ratioScore,
        'n_feat' => $nFeat,
    ];
}

// ── Spreading activation ──
function spreadActivation(array $graph, array $seedOps, int $hops, float $alpha): array
{
    // Начальная активация: seed ops = 1.0
    $activation = [];
    foreach ($seedOps as $op) {
        $activation[$op] = 1.0;
    }

    for ($h = 0; $h < $hops; $h++) {
        $newAct = $activation;
        foreach ($activation as $op => $act) {
            if (!isset($graph[$op])) continue;
            foreach ($graph[$op] as $neighbor => $weight) {
                $newAct[$neighbor] = max($newAct[$neighbor] ?? 0.0, $act * $alpha * $weight);
            }
        }
        $activation = $newAct;
    }

    arsort($activation);
    return $activation;
}

// ── Оценка: помогает ли атом текущей гипотезе? ──
function oneStepGain(array $ePred, array $zVec, array $y): float
{
    $bestCv = INF;
    foreach (['×', '/', 'rdiv', '+', '−'] as $mode) {
        $pred = [];
        $ok = true;
        foreach ($ePred as $i => $ev) {
            $zv = $zVec[$i];
            $v = match ($mode) {
                '×' => $ev * $zv,
                '/' => (abs($zv) > 1e-12) ? $ev / $zv : NAN,
                'rdiv' => (abs($ev) > 1e-12) ? $zv / $ev : NAN,
                '+' => $ev + $zv,
                '−' => $ev - $zv,
            };
            if (!is_finite($v)) { $ok = false; break; }
            $pred[] = $v;
        }
        if (!$ok) continue;
        $cv = cvShiftV2($pred, $y);
        if ($cv < $bestCv) $bestCv = $cv;
    }
    return $bestCv;
}

function cvShiftV2(array $pred, array $y): float
{
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

function loadHeatV3(string $path): array
{
    $X = []; $y = [];
    foreach (file($path) as $line) {
        $p = array_map('floatval', explode(',', trim($line)));
        if (count($p) < 6) continue;
        $X[] = array_slice($p, 0, 5);
        $y[] = $p[5];
    }
    return [$X, $y];
}

function splitV3(array $X, array $y, int $seed): array
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
        array_map(fn($i) => $X[$i], $tr),
        array_map(fn($i) => $y[$i], $tr),
        array_map(fn($i) => $X[$i], $te),
        array_map(fn($i) => $y[$i], $te),
    ];
}

// ═══ MAIN ═══
$base = __DIR__ . '/..';
[$Xall, $yall] = loadHeatV3($base . '/data/feynman_heat_conduction.csv');

echo "=== EXP-032: Spreading Activation PoC (heat) ===\n";
echo "graph: 13 nodes from 529 real laws (−→/ .478, R+→+ .889, ×→/ .2, R×→/ 1.0)\n";
echo "hops=" . HOPS . " alpha=" . ALPHA . " K=" . TOP_K . " seeds=" . SEEDS . "\n\n";

// Fingerprint один раз (на полных данных — для seed-ops)
[$fpX, $fpY] = loadHeatV3($base . '/data/feynman_heat_conduction.csv');
$fp = taskFingerprint($fpX, $fpY);
echo "fingerprint: max_corr={$fp['max_corr']} mono={$fp['mono']} ratio={$fp['ratio']}\n";

// Seed ops: MULTIPLICATIVE + RATIO (fingerprint говорит: corr высокий, ratio есть)
$seedOps = ['−', '×', '/'];
$activated = spreadActivation($GRAPH, $seedOps, HOPS, ALPHA);
echo "activated ops: ";
foreach (array_slice($activated, 0, 6, true) as $op => $act) {
    echo "{$op}({$act}) ";
}
echo "\n\n";

// Прогон 20 seeds
$cvsAll = [];
$bestFormulas = [];
for ($s = 1; $s <= SEEDS; $s++) {
    [$Xtr, $ytr, $Xte, $yte] = splitV3($Xall, $yall, $s);
    $nFeat = count($Xtr[0]);

    // Генерация B-кандидатов (только из активированных ops!)
    $cands = [];
    for ($i = 0; $i < $nFeat; $i++) {
        for ($j = $i + 1; $j < $nFeat; $j++) {
            foreach ($activated as $op => $act) {
                if ($act < 0.1) continue; // порог активации
                $ci = array_column($Xtr, $i);
                $cj = array_column($Xtr, $j);
                $vec = [];
                $ok = true;
                foreach ($ci as $k => $v) {
                    $val = match ($op) {
                        'SUB' => $v - $cj[$k],
                        'MUL' => $v * $cj[$k],
                        'DIV' => (abs($cj[$k]) > 1e-12) ? $v / $cj[$k] : NAN,
                        'ADD' => $v + $cj[$k],
                        default => NAN,
                    };
                    if (!is_finite($val)) { $ok = false; break; }
                    $vec[] = $val;
                }
                if (!$ok) continue;
                $cands[] = ['op' => $op, 'i' => $i, 'j' => $j, 'vec' => $vec, 'act' => $act];
            }
        }
    }

    // Score кандидатов: oneStepGain (residual от mean(y))
    $meanY = array_fill(0, count($ytr), array_sum($ytr) / count($ytr));
    foreach ($cands as &$c) {
        $c['gain'] = oneStepGain($meanY, $c['vec'], $ytr);
    }
    usort($cands, fn($a, $b) => $a['gain'] <=> $b['gain']);
    $topCands = array_slice($cands, 0, TOP_K);

    // Расширяем X: добавляем top-K z-колонки
    $nFeatExt = $nFeat;
    $XtrExt = [];
    foreach ($Xtr as $k => $row) {
        $r = $row;
        foreach ($topCands as $tc) {
            $r[] = $tc['vec'][$k];
        }
        $XtrExt[] = $r;
    }
    $XteExt = [];
    foreach ($Xte as $k => $row) {
        $r = $row;
        foreach ($topCands as $tc) {
            $ci = array_column($Xte, $tc['i']);
            $cj = array_column($Xte, $tc['j']);
            $val = match ($tc['op']) {
                '−' => $ci[$k] - $cj[$k],
                '×' => $ci[$k] * $cj[$k],
                '/' => (abs($cj[$k]) > 1e-12) ? $ci[$k] / $cj[$k] : 0.0,
                '+' => $ci[$k] + $cj[$k],
                default => 0.0,
            };
            $r[] = $val;
        }
        $XteExt[] = $r;
    }

    // Search depth 3 на расширенном X
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $res = Search::find($XtrExt, $ytr, $g, 3, null, 0.0, 0.15, BUDGET);

    if (!$res[0] || $res[1] >= 9.0) {
        $cvsAll[] = 9.99;
        echo "  seed {$s}: NONE\n";
        continue;
    }

    // Holdout eval
    $stats = ExpressionEvaluator::collectStats($res[2], $XtrExt);
    $pTe = ExpressionEvaluator::evaluateFormula($res[2], $XteExt, $stats);
    $cvTe = ($pTe !== null && count($pTe) === count($yte)) ? cvShiftV2($pTe, $yte) : 9.99;
    $cvsAll[] = $cvTe;

    if ($cvTe <= 0.10) {
        $bestFormulas[] = $res[2];
        echo "  seed {$s}: ИНВАРИАНТ CV_H={$cvTe} formula={$res[2]}\n";
    } else {
        echo "  seed {$s}: CV_H={$cvTe} (не прошёл порог)\n";
    }
}

$accepted = array_filter($cvsAll, fn($c) => $c <= 0.10);
echo "\n=== ИТОГ EXP-032 ===\n";
echo "success=" . count($accepted) . "/" . SEEDS . "\n";
if ($accepted) {
    sort($accepted);
    echo "CV_H: " . implode(', ', array_map(fn($c) => round($c, 4), $accepted)) . "\n";
    echo "formulas: " . implode(' | ', array_unique($bestFormulas)) . "\n";
}
$verdict = count($accepted) >= 10 ? '✅ МЕХАНИЗМ РАБОТАЕТ' : (count($accepted) >= 5 ? '⚠️ ЧАСТИЧНО' : '❌ НЕ РАБОТАЕТ');
echo $verdict . "\n";
