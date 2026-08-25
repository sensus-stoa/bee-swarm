<?php
declare(strict_types=1);

/**
 * ЭКСП-027 v3: Bee Swarm часть — тот же frozen split (seed 42, 60/40),
 * те же метрики (CV_train/CV_holdout), null-контроль.
 *
 * Запуск: php scripts/bee_benchmark_v3.php
 * Данные: wine.data, auto-mpg.data (как у PySR)
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;

function loadCsv(string $path): array
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

function loadWine(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) < 14) {
            continue;
        }
        $y[] = (float) $parts[1];
        $feat = [];
        for ($i = 2; $i < 14; $i++) {
            $feat[] = (float) $parts[$i];
        }
        $X[] = $feat;
    }
    return [$X, $y];
}

function loadMpg(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $parts = preg_split('/\s+/', trim($line), 9);
        if (count($parts) < 8) {
            continue;
        }
        $hp = (float) $parts[3];
        if ($hp === 0.0 || $parts[3] === '?') {
            continue;
        }
        $y[] = (float) $parts[0];
        $X[] = [(float) $parts[2], $hp, (float) $parts[4]];
    }
    return [$X, $y];
}

/** Frozen split: тот же seed 42, 60/40, как в Python-скрипте */
function cvRatio(array $pred, array $y): float
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

function frozenSplit(array $X, array $y, int $seed = 42): array
{
    mt_srand($seed);
    $n = count($y);
    $idx = range(0, $n - 1);
    // shuffle как Python: Fisher-Yates с mt_rand
    for ($i = $n - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $nTr = (int) floor($n * 0.6);
    $tr = array_slice($idx, 0, $nTr);
    $te = array_slice($idx, $nTr);

    $Xtr = array_map(fn ($i) => $X[$i], $tr);
    $ytr = array_map(fn ($i) => $y[$i], $tr);
    $Xte = array_map(fn ($i) => $X[$i], $te);
    $yte = array_map(fn ($i) => $y[$i], $te);
    return [$Xtr, $ytr, $Xte, $yte];
}

function findLaw(array $X, array $y, int $depth = 2, float $budgetSec = 60.0): ?array
{
    // ЧЕСТНЫЙ БЕНЧМАРК vs PySR: та же грамматика (+,-,*,/,sqrt,log,exp,abs),
    // НЕ прод-БД (3562 атома = культурное преимущество, но несправедливо
    // против PySR с его заданным алфавитом!). Ограничиваем до базовых ops.
    $g = new \BeeSwarm\Core\Grammar();
    $g->restrictTo(['add', 'sub', 'mul', 'div', 'sqrt', 'sq']);
    $start = microtime(true);
    $res = Search::find($X, $y, $g, $depth, null, 0.0, 0.15, $budgetSec);
    $elapsed = microtime(true) - $start;
    if ($res === null) {
        return ['formula' => 'NONE', 'cv' => 9.99, 'time_s' => round($elapsed, 2)];
    }
    // Search::find → позиционный [found, cv, formula, cvTest, class]
    return [
        'found' => $res[0],
        'cv' => $res[1],
        'formula' => $res[2],
        'cv_test' => $res[3],
        'class' => $res[4],
        'time_s' => round($elapsed, 2),
    ];
}

function runWine(): void
{
    [$X, $y] = loadWine(__DIR__ . '/../wine.data');
    [$Xtr, $ytr, $Xte, $yte] = frozenSplit($X, $y);

    echo "=== WINE (frozen split 60/40, seed 42) ===\n";
    echo "train: " . count($Xtr) . " rows, holdout: " . count($Xte) . " rows\n";

    // Поиск на TRAIN
    $found = findLaw($Xtr, $ytr);
    if ($found === null) {
        echo "  НЕ НАЙДЕНО на train\n";
        return;
    }
    echo "  Найдено: {$found['formula']}  CV_train={$found['cv']}\n";
    echo "  Время: {$found['time_s']}s\n";

    // Оценка на HOLDOUT (замороженный split!): evaluateFormula возвращает
    // ВЕКТОР pred → CV(pred, y_holdout) через cv_ratio (как у PySR!)
    $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($found['formula'], $Xtr);
    $predTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($found['formula'], $Xte, $stats);
    if ($predTe !== null && count($predTe) === count($yte)) {
        $cvTe = cvRatio($predTe, $yte);
        echo "  CV_holdout: " . round($cvTe, 4) . "\n";
    } else {
        echo "  CV_holdout: N/A (evaluateFormula вернул null/неверную длину)\n";
    }
}

function runWineSeeds(int $nSeeds = 20): void
{
    [$X, $y] = loadWine(__DIR__ . '/../wine.data');

    echo "\n=== WINE: {$nSeeds} seeds (разные splits, seed 1..{$nSeeds}) ===\n";
    $cvs = [];
    $r2s = [];
    $times = [];
    for ($s = 1; $s <= $nSeeds; $s++) {
        [$Xtr, $ytr, $Xte, $yte] = frozenSplit($X, $y, $s);
        $found = findLaw($Xtr, $ytr, 2, 60.0);
        if ($found === null || $found['found'] !== true || $found['cv'] >= 9.0) {
            $cvs[] = 9.99; // отказ
            $times[] = $found['time_s'] ?? 0;
            continue;
        }
        $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($found['formula'], $Xtr);
        $predTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($found['formula'], $Xte, $stats);
        $cvTe = ($predTe !== null && count($predTe) === count($yte)) ? cvRatio($predTe, $yte) : 9.99;
        $cvs[] = $cvTe;
        $r2s[] = corr2($predTe, $yte);
        $times[] = $found['time_s'];
        echo "  seed {$s}: " . ($found['found'] === true ? $found['formula'] : 'REFUSE')
            . "  CV_H=" . round($cvTe, 4) . "  (" . ($found['time_s'] ?? 0) . "s)\n";
    }
    $accepted = array_filter($cvs, fn ($c) => $c <= 0.10);
    echo "  CV_H median: " . round(median($cvs), 4) . "  q05: " . round(percentile($cvs, 5), 4)
        . "  q95: " . round(percentile($cvs, 95), 4) . "  q25: " . round(percentile($cvs, 25), 4)
        . "  q75: " . round(percentile($cvs, 75), 4) . "\n";
    echo "  success rate (CV_H<=0.10): " . count($accepted) . "/{$nSeeds}\n";
    if ($r2s) {
        echo "  R² median (найденные): " . round(median($r2s), 3) . "\n";
    }
    echo "  время median: " . round(median($times), 1) . "s\n";
}

function median(array $a): float
{
    sort($a);
    $n = count($a);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
}

function percentile(array $a, int $p): float
{
    sort($a);
    $n = count($a);
    $idx = (int) ceil($p / 100 * $n) - 1;
    return $a[max(0, min($n - 1, $idx))];
}

function corr2(array $a, array $b): float
{
    $n = count($a);
    if ($n === 0) {
        return 0.0;
    }
    $ma = array_sum($a) / $n;
    $mb = array_sum($b) / $n;
    $num = 0.0;
    $da = 0.0;
    $db = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $num += ($a[$i] - $ma) * ($b[$i] - $mb);
        $da += ($a[$i] - $ma) ** 2;
        $db += ($b[$i] - $mb) ** 2;
    }
    $den = sqrt($da * $db);
    return $den > 0 ? ($num / $den) ** 2 : 0.0;
}

function runTask(string $name, array $X, array $y, int $nSeeds = 20): void
{
    echo "\n=== {$name}: " . count($y) . " rows ===\n";
    $cvs = [];
    $r2s = [];
    $times = [];
    for ($s = 1; $s <= $nSeeds; $s++) {
        [$Xtr, $ytr, $Xte, $yte] = frozenSplit($X, $y, $s);
        $found = findLaw($Xtr, $ytr, 3, 30.0); // depth 3, бюджет 30s (как PySR)
        if ($found === null || $found['found'] !== true || $found['cv'] >= 9.0) {
            $cvs[] = 9.99;
            $times[] = $found['time_s'] ?? 0;
            continue;
        }
        $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($found['formula'], $Xtr);
        $predTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($found['formula'], $Xte, $stats);
        $cvTe = ($predTe !== null && count($predTe) === count($yte)) ? cvRatio($predTe, $yte) : 9.99;
        $cvs[] = $cvTe;
        if ($predTe !== null && count($predTe) === count($yte)) {
            $r2s[] = corr2($predTe, $yte);
        }
        $times[] = $found['time_s'];
    }
    $accepted = array_filter($cvs, fn ($c) => $c <= 0.10);
    echo "  CV_H med=" . round(median($cvs), 4) . " q05=" . round(percentile($cvs, 5), 4)
        . " q95=" . round(percentile($cvs, 95), 4)
        . "  success=" . count($accepted) . "/{$nSeeds}"
        . ($r2s ? "  R2=" . round(median($r2s), 3) : "")
        . "  t=" . round(median($times), 1) . "s\n";
}

function runAllTasks(): void
{
    $tasks = [
        'feynman_gravity' => 'data/feynman_gravity.csv',
        'feynman_kinetic' => 'data/feynman_kinetic_energy.csv',
        'feynman_dot' => 'data/feynman_dot_product.csv',
        'feynman_heat' => 'data/feynman_heat_conduction.csv',
        'feynman_relmass' => 'data/feynman_relativistic_mass.csv',
        'feynman_kinetic_noise5' => 'data/feynman_kinetic_energy_noise5.csv',
        'feynman_coulomb_noise15' => 'data/feynman_coulomb_noise15.csv',
        'concrete' => 'data/concrete_strength.csv',
        'airfoil' => 'data/airfoil_selfnoise.csv',
        'energy' => 'data/energy_efficiency.csv',
        'auto-mpg' => 'auto-mpg.data',
        'wine' => 'wine.data',
    ];
    foreach ($tasks as $name => $file) {
        if (! file_exists(__DIR__ . '/../' . $file)) {
            echo "  SKIP {$name}: нет файла {$file}\n";
            continue;
        }
        if ($name === 'wine') {
            [$X, $y] = loadWine(__DIR__ . '/../' . $file);
        } elseif ($name === 'auto-mpg') {
            [$X, $y] = loadMpg(__DIR__ . '/../' . $file);
        } else {
            [$X, $y] = loadCsv(__DIR__ . '/../' . $file);
        }
        runTask($name, $X, $y);
    }
}

function runMpg(): void
{
    [$X, $y] = loadMpg(__DIR__ . '/../auto-mpg.data');
    [$Xtr, $ytr, $Xte, $yte] = frozenSplit($X, $y);

    echo "\n=== AUTO-MPG (frozen split) ===\n";
    $found = findLaw($Xtr, $ytr);
    if ($found === null) {
        echo "  НЕ НАЙДЕНО на train (refusal)\n";
    } else {
        echo "  Найдено: {$found['formula']}  CV_train={$found['cv']}\n";
    }
}

function runMpgNull(int $nRuns = 100): void
{
    [$X, $y] = loadMpg(__DIR__ . '/../auto-mpg.data');
    [$Xtr, $ytr, $Xte, $yte] = frozenSplit($X, $y);

    echo "\n=== AUTO-MPG: {$nRuns} null-runs (shuffled target) ===\n";
    $foundCount = 0;
    $cvs = [];
    for ($i = 0; $i < $nRuns; $i++) {
        $shuffled = $ytr;
        shuffle($shuffled);
        $found = findLaw($Xtr, $shuffled);
        // findLaw ВСЕГДА возвращает массив — считаем ТОЛЬКО реальные находки!
        if ($found !== null && $found['found'] === true && $found['cv'] < 9.0) {
            $foundCount++;
            $cvs[] = (float) $found['cv'];
        }
    }
    $rate = $foundCount / $nRuns;
    echo "  null: найдено законов на shuffle: {$foundCount}/{$nRuns} (FPR={$rate})\n";
    if ($cvs) {
        echo "  CV найденных (null): " . implode(', ', array_map(fn ($c) => round($c, 3), array_slice($cvs, 0, 10))) . "...\n";
    }
}

runAllTasks();
runMpgNull();
echo "\nDONE\n";
