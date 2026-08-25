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
function frozenSplit(array $X, array $y): array
{
    mt_srand(42);
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
    $g = new \BeeSwarm\Core\Grammar();
    $start = microtime(true);
    $res = Search::find($X, $y, $g, $depth);
    $elapsed = microtime(true) - $start;
    if ($res === null) {
        return ['formula' => 'NONE', 'cv' => 9.99, 'time_s' => round($elapsed, 2)];
    }
    $res['time_s'] = round($elapsed, 2);
    return $res;
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

    // Оценка на HOLDOUT (замороженный split!)
    $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($found['formula'], $Xtr);
    $cvTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($found['formula'], $Xte, $stats);
    echo "  CV_holdout: " . round((float) $cvTe, 4) . "\n";
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
        if ($found !== null) {
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

runWine();
runMpg();
runMpgNull();
echo "\nDONE\n";
