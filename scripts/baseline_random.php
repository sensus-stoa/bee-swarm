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

echo str_repeat('-', 72) . "\n";
echo "Вывод: если random находит законы на NOISE — null-калибровка обязательна.\n";
echo "Если random находит ~то же что systematic на синтетике — пчёлы должны\n";
echo "доказать преимущество на СЛОЖНЫХ данных (compose, cross-domain).\n";
