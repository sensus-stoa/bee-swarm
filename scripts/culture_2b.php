<?php
declare(strict_types=1);

/**
 * EXP-029 волна 2b: только heat + dot (культура, inline L3L1)
 * + недостающие concrete/airfoil/energy (волна 2a, depth 3).
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

Database::setPath(getenv('SWARM_DB_PATH') ?: tempnam(sys_get_temp_dir(), 'cult_') . '.db');

function loadCsv3(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) < 2) {
            continue;
        }
        $vals = array_map('floatval', $parts);
        $y[] = array_pop($vals);
        $X[] = $vals;
    }
    return [$X, $y];
}

function frozenSplit3(array $X, array $y, int $seed = 42): array
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

function cvRatio3(array $pred, array $y): float
{
    $eps = 1e-9;
    $n = count($y);
    $ratio = [];
    for ($i = 0; $i < $n; $i++) {
        $ratio[] = abs($pred[$i]) / (abs($y[$i]) + $eps);
    }
    $m = array_sum($ratio) / $n;
    if (abs($m) < $eps) {
        return INF;
    }
    $var = 0.0;
    foreach ($ratio as $r) {
        $var += ($r - $m) ** 2;
    }
    return sqrt($var / $n) / abs($m);
}

function median3(array $a): float
{
    sort($a);
    $n = count($a);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
}

function percentile3(array $a, int $p): float
{
    sort($a);
    $n = count($a);
    $idx = (int) ceil($p / 100 * $n) - 1;
    return $a[max(0, min($n - 1, $idx))];
}

function findLaw3(array $X, array $y, int $depth = 3, float $budgetSec = 30.0): array
{
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $start = microtime(true);
    $res = Search::find($X, $y, $g, $depth, null, 0.0, 0.15, $budgetSec);
    $elapsed = microtime(true) - $start;
    if ($res === null) {
        return ['found' => false, 'cv' => 9.99, 'formula' => 'NONE', 'class' => 'NONE', 'time_s' => round($elapsed, 2)];
    }
    return ['found' => $res[0], 'cv' => $res[1], 'formula' => $res[2], 'class' => $res[4], 'time_s' => round($elapsed, 2)];
}

function runTask3(string $name, array $X, array $y, int $depth): void
{
    $cvs = [];
    $classes = [];
    for ($s = 1; $s <= 20; $s++) {
        [$Xtr, $ytr, $Xte, $yte] = frozenSplit3($X, $y, $s);
        $found = findLaw3($Xtr, $ytr, $depth);
        $classes[$found['class']] = ($classes[$found['class']] ?? 0) + 1;
        if (! $found['found'] || $found['cv'] >= 9.0) {
            $cvs[] = 9.99;
            continue;
        }
        $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($found['formula'], $Xtr);
        $predTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($found['formula'], $Xte, $stats);
        $cvTe = ($predTe !== null && count($predTe) === count($yte)) ? cvRatio3($predTe, $yte) : 9.99;
        $cvs[] = $cvTe;
    }
    $accepted = array_filter($cvs, fn ($c) => $c <= 0.10);
    $cls = implode('/', array_map(fn ($k, $v) => "$k:$v", array_keys($classes), $classes));
    echo "  {$name} (depth {$depth}): med=" . round(median3($cvs), 4)
        . " q05=" . round(percentile3($cvs, 5), 4)
        . " q95=" . round(percentile3($cvs, 95), 4)
        . "  success=" . count($accepted) . "/20  classes:{$cls}\n";
}

// Фаза A: атомы
echo "=== ФАЗА A ===\n";
$X = [[5.0, 2.0], [9.0, 3.0], [7.0, 4.0], [10.0, 1.0], [6.0, 5.0]];
$y = [3.0, 6.0, 3.0, 9.0, 1.0];
$g = new Grammar();
$g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
$res = Search::find($X, $y, $g, 2);
if ($res[0] && ! str_contains($res[2], 'R') && strlen($res[2]) >= 5) {
    \BeeSwarm\Core\Grammar::staticAdd('B1', 'birth', $res[2], 'foraged_culture');
    echo "  B1 = {$res[2]}\n";
}
$X2 = [[2.0, 3.0], [4.0, 5.0], [6.0, 2.0], [3.0, 7.0], [8.0, 1.0]];
$y2 = [6.0, 20.0, 12.0, 21.0, 8.0];
$res2 = Search::find($X2, $y2, $g, 2);
if ($res2[0] && ! str_contains($res2[2], 'R') && strlen($res2[2]) >= 5) {
    \BeeSwarm\Core\Grammar::staticAdd('B2', 'birth', $res2[2], 'foraged_culture');
    echo "  B2 = {$res2[2]}\n";
}

echo "=== ФАЗА B ===\n";
// 2b: heat + dot с depth 4 (inline L3L1 + bKeys впереди!)
// depth 3: heat/dot = 0/20 (4 операнда — граница выразимости depth 3;
// каскад-2 (depth 4) = 14.5GB OOM/segfault на PHP — algorithmic limit!)
[$hX, $hY] = loadCsv3(__DIR__ . '/../data/feynman_heat_conduction.csv');
runTask3('feynman_heat', $hX, $hY, 3);
[$dX, $dY] = loadCsv3(__DIR__ . '/../data/feynman_dot_product.csv');
runTask3('feynman_dot', $dX, $dY, 3);
// Остаток волны 2a: depth 3
[$cX, $cY] = loadCsv3(__DIR__ . '/../data/concrete_strength.csv');
runTask3('concrete', $cX, $cY, 3);
[$aX, $aY] = loadCsv3(__DIR__ . '/../data/airfoil_selfnoise.csv');
runTask3('airfoil', $aX, $aY, 3);
[$eX, $eY] = loadCsv3(__DIR__ . '/../data/energy_efficiency.csv');
runTask3('energy', $eX, $eY, 3);

echo "\nDONE\n";
