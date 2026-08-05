<?php
declare(strict_types=1);

/**
 * BASELINE: random search vs systematic Search::find
 * Стори: BASELINE-RANDOM-SEARCH
 *
 * Сравниваем на одинаковом бюджете evaluations:
 * - RandomSearch: случайные формулы из грамматики, случайные пары колонок
 * - Search::find: систематический L0-L3 перебор
 *
 * Данные: синтетика (ADD, MUL, XOR) + реальные метрики (all_metrics.md)
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

function makeData(string $kind): array
{
    switch ($kind) {
        case 'ADD': // y = x0 + x1
            $X = []; $y = [];
            for ($i = 0; $i < 30; $i++) {
                $a = mt_rand(-10, 10); $b = mt_rand(-10, 10);
                $X[] = [(float) $a, (float) $b];
                $y[] = (float) ($a + $b);
            }
            return [$X, $y];
        case 'MUL': // y = x0 * x1
            $X = []; $y = [];
            for ($i = 0; $i < 30; $i++) {
                $a = mt_rand(-5, 5); $b = mt_rand(-5, 5);
                $X[] = [(float) $a, (float) $b];
                $y[] = (float) ($a * $b);
            }
            return [$X, $y];
        case 'XOR': // y = (x0-x1)²
            $X = []; $y = [];
            for ($i = 0; $i < 30; $i++) {
                $a = mt_rand(-10, 10); $b = mt_rand(-10, 10);
                $X[] = [(float) $a, (float) $b];
                $y[] = (float) (($a - $b) ** 2);
            }
            return [$X, $y];
        case 'NOISE': // чистый шум — законов нет
            $X = []; $y = [];
            for ($i = 0; $i < 30; $i++) {
                $X[] = [(float) mt_rand(-100, 100), (float) mt_rand(-100, 100)];
                $y[] = (float) mt_rand(-100, 100);
            }
            return [$X, $y];
        default:
            throw new \Exception("Unknown kind: $kind");
    }
}

/**
 * Random search: N случайных формул из грамматики на случайных парах колонок.
 * @return int количество найденных законов (CV < 0.15)
 */
function randomSearch(array $X, array $y, Grammar $g, int $budget): int
{
    $found = 0;
    $n = count($y);
    $nFeat = count($X[0] ?? []);
    $ops = $g->all();

    for ($eval = 0; $eval < $budget; $eval++) {
        // Случайная пара колонок
        $fIdx1 = mt_rand(0, $nFeat - 1);
        $fIdx2 = mt_rand(0, $nFeat - 1);
        $col1 = array_column($X, $fIdx1);
        $col2 = array_column($X, $fIdx2);

        // Случайная операция
        $op = $ops[array_rand($ops)];
        $vec = [];
        $valid = true;
        for ($i = 0; $i < $n; $i++) {
            $r = $g->apply($col1[$i], $col2[$i], $op);
            if ($r === null) { $valid = false; break; }
            $vec[] = $r;
        }
        if (! $valid) continue;

        // CV
        $cv = Search::cv($vec, $y);
        if ($cv < 0.15) {
            $found++;
        }
    }
    return $found;
}

/**
 * Systematic Search::find — возвращает [формула, cv] или null.
 */
function systematicSearch(array $X, array $y, Grammar $g): ?array
{
    [$found, $cv, $formula, $cvTest, $class] = Search::find($X, $y, $g, 2, null, 0.0);
    return $found ? [$formula, $cv] : null;
}

$datasets = ['ADD', 'MUL', 'XOR', 'NOISE'];
$budget = 2000; // evaluations

// Реальные данные: metrics.jsonl (154 строки)
$real = loadRealMetrics('~/ninjacat/Documents/the_lair/ExoCortex/Journal/global/metrics.jsonl');

/**
 * Загрузить метрики: [date, sleep, energy, stress, anxiety, discipline, gi, dq, intact]
 */
function loadRealMetrics(string $path): array
{
    $rows = [];
    foreach (file($path) as $line) {
        $m = json_decode(trim($line), true);
        if (! $m || ! isset($m['sleep']) || ! isset($m['intact'])) continue;
        $rows[] = [
            (float) $m['sleep'], (float) $m['energy'], (float) $m['stress'],
            (float) $m['anxiety'], (float) $m['discipline'], (float) $m['gi'],
            (float) $m['dq'], (float) $m['intact'],
        ];
    }
    // X = первые 7 колонок, y = intact (целевая)
    $X = array_map(fn ($r) => array_slice($r, 0, 7), $rows);
    $y = array_column($rows, 7);
    return [$X, $y];
}

/**
 * Wine: 12 features → alcohol (col 1)
 */
function loadWine(string $path): array
{
    $X = []; $y = [];
    if (! file_exists($path)) return [$X, $y];
    foreach (file($path) as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) < 14) continue;
        $y[] = (float) $parts[1];
        $feat = [];
        for ($i = 2; $i < 14; $i++) $feat[] = (float) $parts[$i];
        $X[] = $feat;
    }
    return [$X, $y];
}

/**
 * AutoMpg: displacement, horsepower, weight → mpg
 */
function loadAutoMpg(string $path): array
{
    $X = []; $y = [];
    if (! file_exists($path)) return [$X, $y];
    foreach (file($path) as $line) {
        $parts = preg_split('/\s+/', trim($line), 9);
        if (count($parts) < 8) continue;
        $hp = (float) $parts[3];
        if ($hp === 0.0) continue;
        $y[] = (float) $parts[0];
        $X[] = [(float) $parts[2], $hp, (float) $parts[4]];
    }
    return [$X, $y];
}

echo "BASELINE: random search vs systematic Search::find\n";
echo "Budget: {$budget} evaluations per dataset\n";
echo str_repeat('-', 72) . "\n";
printf("%-8s | %-18s | %-18s | %s\n", "Data", "Random hits", "Systematic", "Verdict");
echo str_repeat('-', 72) . "\n";

foreach ($datasets as $kind) {
    [$X, $y] = makeData($kind);
    // Чистая грамматика (BASE_OPS) — не тащим 5469 ops из прод-БД
    $g = Grammar::fromOps(Grammar::baseOpNames());

    $randomHits = randomSearch($X, $y, $g, $budget);
    $sys = systematicSearch($X, $y, $g);

    $sysStr = $sys ? "{$sys[0]} (CV={$sys[1]})" : "none";
    $verdict = '—';
    if ($kind === 'NOISE') {
        $verdict = $randomHits === 0 ? 'random OK' : 'random FAIL (noise)';
    } else {
        $verdict = $sys ? 'both find' : 'random only!';
    }

    printf("%-8s | %-18s | %-18s | %s\n", $kind, $randomHits, $sysStr, $verdict);
}

// Реальные метрики: 7 колонок → intact
[$realX, $realY] = $real;
$g = Grammar::fromOps(Grammar::baseOpNames());
$realRandom = randomSearch($realX, $realY, $g, $budget);
$realSys = systematicSearch($realX, $realY, $g);
$sysStr = $realSys ? "{$realSys[0]} (CV={$realSys[1]})" : "none";
printf("%-8s | %-18s | %-18s | %s\n", "REAL", $realRandom, $sysStr,
    $realSys ? 'systematic found' : 'none found');

// Wine: 12 features → alcohol (col 1)
[$wineX, $wineY] = loadWine('~/.bee_swarm/wine.data');
$wineRandom = randomSearch($wineX, $wineY, $g, $budget);
$wineSys = systematicSearch($wineX, $wineY, $g);
$sysStr = $wineSys ? "{$wineSys[0]} (CV=" . round($wineSys[1], 4) . ")" : "none";
printf("%-8s | %-18s | %-18s | %s\n", "WINE", $wineRandom, $sysStr,
    $wineSys ? 'systematic found' : 'none found');

// AutoMpg: disp, hp, weight → mpg
[$mpgX, $mpgY] = loadAutoMpg('~/.bee_swarm/auto-mpg.data');
$mpgRandom = randomSearch($mpgX, $mpgY, $g, $budget);
$mpgSys = systematicSearch($mpgX, $mpgY, $g);
$sysStr = $mpgSys ? "{$mpgSys[0]} (CV=" . round($mpgSys[1], 4) . ")" : "none";
printf("%-8s | %-18s | %-18s | %s\n", "MPG", $mpgRandom, $sysStr,
    $mpgSys ? 'systematic found' : 'none found');

echo str_repeat('-', 72) . "\n";
echo "Вывод: если random находит законы на NOISE — null-калибровка обязательна.\n";
echo "Если random находит ~то же что systematic на синтетике — пчёлы должны\n";
echo "доказать преимущество на СЛОЖНЫХ данных (compose, cross-domain).\n";
