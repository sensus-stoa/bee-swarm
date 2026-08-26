<?php
declare(strict_types=1);

/**
 * EXP-029: КУЛЬТУРНЫЙ БЕНЧМАРК (предрегистрация 25.08.2026).
 *
 * Фаза A: простая задача → Search::find находит закон → записывается
 *         в grammar_ops как B-атом (source='birth') — РОВНО как улей
 *         рождает атомы из реальных задач (ЭКСП-015/022).
 * Фаза B: 12 задач × 20 seeds с bornBinary из БД (культурный контекст!).
 *
 * Механизм культуры УЖЕ в проде (Search::find читает bornBinary,
 * BINARY-B_CAP=3) — бенчмарк только имитирует «прошлый опыт роя».
 *
 * Запуск: SWARM_DB_PATH=/tmp/culture.db php scripts/culture_benchmark.php
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

// ═══ ФАЗА A: рождение атомов из «прошлых задач» ═══
function phaseA(): void
{
    echo "=== ФАЗА A: рождение B-атомов ===\n";
    $db = Database::get();

    // Задача 1: y = x0 − x1 (находит (x0−x1))
    $X = [[5.0, 2.0], [9.0, 3.0], [7.0, 4.0], [10.0, 1.0], [6.0, 5.0]];
    $y = [3.0, 6.0, 3.0, 9.0, 1.0];
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $res = Search::find($X, $y, $g, 2);
    if ($res[0]) {
        // Тривиальные/тавтологии не рожают (как в Hive::birthOperator)
        if (! str_contains($res[2], 'R') && strlen($res[2]) >= 5) {
            \BeeSwarm\Core\Grammar::staticAdd('B1', 'birth', $res[2], 'foraged_culture');
            echo "  B1 = {$res[2]} (из y = x0−x1)\n";
        }
    }

    // Задача 2: y = x0 × x1 (находит (x0×x1))
    $X2 = [[2.0, 3.0], [4.0, 5.0], [6.0, 2.0], [3.0, 7.0], [8.0, 1.0]];
    $y2 = [6.0, 20.0, 12.0, 21.0, 8.0];
    $res2 = Search::find($X2, $y2, $g, 2);
    if ($res2[0]) {
        if (! str_contains($res2[2], 'R') && strlen($res2[2]) >= 5) {
            \BeeSwarm\Core\Grammar::staticAdd('B2', 'birth', $res2[2], 'foraged_culture');
            echo "  B2 = {$res2[2]} (из y = x0 × x1)\n";
        }
    }

    $cnt = (int) $db->query("SELECT COUNT(*) FROM grammar_ops WHERE source='birth'")->fetchColumn();
    echo "  B-атомов в БД: {$cnt}\n";
}

// ═══ Общие утилиты (как в bee_benchmark_v3) ═══
function loadCsv2(string $path): array
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

function loadWine2(string $path): array
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

function loadMpg2(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $parts = preg_split('/\s+/', trim($line), 9);
        if (count($parts) < 8) {
            continue;
        }
        try {
            $hp = (float) $parts[3];
        } catch (\Throwable) {
            continue;
        }
        if ($hp === 0.0 || $parts[3] === '?') {
            continue;
        }
        $y[] = (float) $parts[0];
        $X[] = [(float) $parts[2], $hp, (float) $parts[4]];
    }
    return [$X, $y];
}

function frozenSplit2(array $X, array $y, int $seed = 42): array
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

function cvRatio2(array $pred, array $y): float
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

function median2(array $a): float
{
    sort($a);
    $n = count($a);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
}

function percentile2(array $a, int $p): float
{
    sort($a);
    $n = count($a);
    $idx = (int) ceil($p / 100 * $n) - 1;
    return $a[max(0, min($n - 1, $idx))];
}

function findLaw2(array $X, array $y, int $depth = 3, float $budgetSec = 30.0): array
{
    $g = new Grammar();
    $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
    $start = microtime(true);
    $res = Search::find($X, $y, $g, $depth, null, 0.0, 0.15, $budgetSec);
    $elapsed = microtime(true) - $start;
    if ($res === null) {
        return ['found' => false, 'cv' => 9.99, 'formula' => 'NONE', 'time_s' => round($elapsed, 2)];
    }
    return [
        'found' => $res[0],
        'cv' => $res[1],
        'formula' => $res[2],
        'cv_test' => $res[3],
        'class' => $res[4],
        'time_s' => round($elapsed, 2),
    ];
}

// ═══ ФАЗА B: 12 задач × 20 seeds (культурный контекст!) ═══
function phaseB(): void
{
    $tasks = [
        'wine' => ['file' => 'wine.data', 'kind' => 'wine'],
        'auto-mpg' => ['file' => 'auto-mpg.data', 'kind' => 'mpg'],
        'feynman_gravity' => ['file' => 'data/feynman_gravity.csv', 'kind' => 'csv'],
        'feynman_kinetic' => ['file' => 'data/feynman_kinetic_energy.csv', 'kind' => 'csv'],
        'feynman_dot' => ['file' => 'data/feynman_dot_product.csv', 'kind' => 'csv'],
        'feynman_heat' => ['file' => 'data/feynman_heat_conduction.csv', 'kind' => 'csv'],
        'feynman_relmass' => ['file' => 'data/feynman_relativistic_mass.csv', 'kind' => 'csv'],
        'feynman_kinetic_noise5' => ['file' => 'data/feynman_kinetic_energy_noise5.csv', 'kind' => 'csv'],
        'feynman_coulomb_noise15' => ['file' => 'data/feynman_coulomb_noise15.csv', 'kind' => 'csv'],
        'concrete' => ['file' => 'data/concrete_strength.csv', 'kind' => 'csv'],
        'airfoil' => ['file' => 'data/airfoil_selfnoise.csv', 'kind' => 'csv'],
        'energy' => ['file' => 'data/energy_efficiency.csv', 'kind' => 'csv'],
    ];

    echo "\n=== ФАЗА B: 12 задач × 20 seeds (с B-атомами!) ===\n";
    foreach ($tasks as $name => $t) {
        $file = __DIR__ . '/../' . $t['file'];
        if (! file_exists($file)) {
            echo "  SKIP {$name}\n";
            continue;
        }
        [$X, $y] = match ($t['kind']) {
            'wine' => loadWine2($file),
            'mpg' => loadMpg2($file),
            default => loadCsv2($file),
        };

        $cvs = [];
        for ($s = 1; $s <= 20; $s++) {
            [$Xtr, $ytr, $Xte, $yte] = frozenSplit2($X, $y, $s);
            $found = findLaw2($Xtr, $ytr);
            if (! $found['found'] || $found['cv'] >= 9.0) {
                $cvs[] = 9.99;
                continue;
            }
            $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($found['formula'], $Xtr);
            $predTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($found['formula'], $Xte, $stats);
            $cvTe = ($predTe !== null && count($predTe) === count($yte))
                ? cvRatio2($predTe, $yte) : 9.99;
            $cvs[] = $cvTe;
        }
        $accepted = array_filter($cvs, fn ($c) => $c <= 0.10);
        echo "  {$name}: med=" . round(median2($cvs), 4)
            . " q05=" . round(percentile2($cvs, 5), 4)
            . " q95=" . round(percentile2($cvs, 95), 4)
            . "  success=" . count($accepted) . "/20\n";
    }
}

Database::setPath(getenv('SWARM_DB_PATH') ?: tempnam(sys_get_temp_dir(), 'cult_') . '.db');
phaseA();
phaseB();
echo "\nDONE\n";
