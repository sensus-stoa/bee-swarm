<?php
declare(strict_types=1);

/**
 * A19-bisect probe: ОДНА клетка, один seed — изоляция виновника (gate vs
 * tolerance vs pre-existing). Конфиг = bee_benchmark_v3 runTask (depth 3, 30s).
 * Использование: php scripts/probe_cell_one.php <csv-путь> [seed] [depth] [budget]
 */

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');

require dirname(__DIR__) . '/vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

$path = $argv[1] ?? 'data/feynman_heat_conduction.csv';
$seed = (int) ($argv[2] ?? 1);
$depth = (int) ($argv[3] ?? 3);
$budget = (float) ($argv[4] ?? 30.0);

$X = [];
$y = [];
foreach (file(dirname(__DIR__) . '/' . $path) as $line) {
    $parts = explode(',', trim($line));
    if (count($parts) < 2) {
        continue;
    }
    $vals = array_map('floatval', $parts);
    $y[] = array_pop($vals);
    $X[] = $vals;
}

// frozenSplit (bee_benchmark_v3): детерминированный shuffle по seed, 60/40
$n = count($y);
mt_srand($seed);
$idx = range(0, $n - 1);
for ($i = $n - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
}
$split = (int) ($n * 0.6);
$Xtr = $ytr = $Xte = $yte = [];
foreach ($idx as $k => $orig) {
    if ($k < $split) {
        $Xtr[] = $X[$orig];
        $ytr[] = $y[$orig];
    } else {
        $Xte[] = $X[$orig];
        $yte[] = $y[$orig];
    }
}

// Диагностика цели: масштаб/разброс (для чтения baseline-чисел)
$mean = array_sum($ytr) / count($ytr);
$var = 0.0;
foreach ($ytr as $v) {
    $var += ($v - $mean) ** 2;
}
$std = sqrt($var / count($ytr));
echo 'TARGET: n=', $n, ' y_min=', min($y), ' y_max=', max($y), ' mean=', round($mean, 6), ' std=', round($std, 6),
    ' sigma_rel=', round($std / abs($mean), 4), PHP_EOL;

$g = new Grammar();
$g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
$res = Search::find($Xtr, $ytr, $g, $depth, null, 0.0, 0.15, $budget);
if ($res === null) {
    echo "RESULT: null\n";
    exit(0);
}
[$found, $cv, $formula, $cvTest, $class] = $res;
echo 'RESULT: found=', var_export($found, true), ' cv_train=', var_export($cv, true),
    ' cv_test=', var_export($cvTest, true), ' class=', $class, ' formula=', $formula, PHP_EOL;

// Если найдено — holdout как в раннере
if ($found === true && $cv < 9.0) {
    $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($formula, $Xtr);
    $predTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($formula, $Xte, $stats);
    if ($predTe !== null && count($predTe) === count($yte)) {
        // cvRatio (bee_benchmark_v3)
        $ratios = [];
        for ($i = 0; $i < count($yte); $i++) {
            $ratios[] = $predTe[$i] / ($yte[$i] + 1e-8);
        }
        $rm = array_sum($ratios) / count($ratios);
        $rv = 0.0;
        foreach ($ratios as $r) {
            $rv += ($r - $rm) ** 2;
        }
        $cvH = sqrt($rv / count($ratios)) / abs($rm);
        echo 'HOLDOUT: cv_H=', var_export($cvH, true), PHP_EOL;
        // Остатки точности (для tolerance-калибровки)
        $maxAbsRes = 0.0;
        $maxRelRes = 0.0;
        $predTr = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($formula, $Xtr, $stats);
        if ($predTr !== null) {
            foreach ($predTr as $i => $p) {
                $absres = abs($p - $ytr[$i]);
                $maxAbsRes = max($maxAbsRes, $absres);
                if (abs($ytr[$i]) > 0) {
                    $maxRelRes = max($maxRelRes, $absres / abs($ytr[$i]));
                }
            }
        }
        echo 'RESIDUALS(train): max_abs=', $maxAbsRes, ' max_rel=', $maxRelRes, PHP_EOL;
    }
}
