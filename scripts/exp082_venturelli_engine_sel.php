<?php
declare(strict_types=1);

/**
 * B5.12: движок на ОТБОРАННЫХ B5.10-метаболитах (не произвольных) +
 * residual-цель + floor-чувствительность. Вопрос: дотягивает ли cv
 * до 0.15 когда поиск сужен до уже подтверждённых фич.
 *
 * Параллельно (pcntl): per-вид процесс. best_cv_seen печать.
 */

require __DIR__ . '/exp070_shared.php';
require __DIR__ . '/../vendor/autoload.php';
error_reporting(E_ALL & ~E_WARNING);

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

Database::setPath(':memory:');

$VEN = __DIR__ . '/../Benchmarks/venturelli';

// --- EV4 ---
$rows = openpyxl_load($VEN . '/ev4_metabolites.csv');
$header = array_map(fn ($v) => trim(str_replace(["'", "\r"], '', $v)), $rows[0]);
$speciesEV4 = array_slice($header, 1);
$metab = [];
foreach (array_slice($rows, 1) as $r) {
    $name = trim(str_replace(["'", "\r"], '', $r[0]));
    if ($name === '') { continue; }
    foreach ($speciesEV4 as $c => $sp) {
        $v = trim((string) ($r[$c + 1] ?? ''));
        if (is_numeric($v)) { $metab[$name][$sp] = (float) $v; }
    }
}

// --- отбор B5.10 (воспроизводим гейт: corr residual, null 200, |r|<=0.95, z>=4)
// на train-композициях; для скорости кэшируем результат в файл
$SEL_CACHE = '/tmp/e080_selection.json';
if (file_exists($SEL_CACHE)) {
    $selected = json_decode(file_get_contents($SEL_CACHE), true);
} else {
    // ... (упрощённо: возьмём полный гейт из B5.10 — тут повтор)
    $params = [];
    $xml = file_get_contents('/tmp/ven_s006/twelveSpeciesT3.xml');
    preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
    foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

    $raw = file($VEN . '/ev3_communities.csv');
    $communities = [];
    $cur = null;
    foreach ($raw as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        if (preg_match('/^([A-Z]{2}-|NONE)/', $line)) { $cur = rtrim($line); $communities[$cur] = []; continue; }
        if ($cur !== null && preg_match('/^[\d\s.eE+-]+$/', $line)) {
            $communities[$cur][] = array_map('floatval', preg_split('/\s+/', trim($line)));
        }
    }
    $ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];
    $testComms = ['NONE', 'BU-', 'FP-', 'DP-'];
    $selected = [];
    foreach ($ev3order as $sp) {
        $rr = [];
        foreach ($communities as $label => $mat) {
            if (in_array($label, $testComms, true)) { continue; }
            for ($t = 0; $t < 6; $t++) {
                $xRow = [];
                foreach ($ev3order as $c => $sp2) { $xRow[$sp2] = $mat[$t][$c]; }
                $xi = $xRow[$sp]; $xin = $mat[$t + 1][array_search($sp, $ev3order, true)] ?? null;
                if ($xin === null || $xi < 0.01) { continue; }
                // residual M0 (их T3 в one-step как приближение M0)
                $s = $params['u' . $sp];
                foreach ($ev3order as $j) { $s += $params['a' . $sp . $j] * $xRow[$j]; }
                $rr[] = ['res' => ($xin - $xi) / $xi - $s, 'xj' => $xRow];
            }
        }
        $nR = count($rr);
        if ($nR < 15) { continue; }
        $resArr = array_column($rr, 'res');
        $mMean = array_sum($resArr) / $nR;
        $sdRes = sqrt(array_sum(array_map(fn ($v) => ($v - $mMean) ** 2, $resArr)) / $nR);
        if ($sdRes < 1e-9) { continue; }
        // per-вид corr с m_f, где m_f = sum_j x_f(j)*x_j
        $cands = [];
        foreach ($metab as $mn => $bySp) {
            $mArr = [];
            foreach ($rr as $r) {
                $pool = 0.0;
                foreach ($r['xj'] as $j => $xj) { if ($j !== $sp) { $pool += ($bySp[$j] ?? 0.0) * $xj; } }
                $mArr[] = $pool;
            }
            $mm = array_sum($mArr) / $nR;
            $sdM = sqrt(array_sum(array_map(fn ($v) => ($v - $mm) ** 2, $mArr)) / $nR);
            if ($sdM < 1e-9) { continue; }
            $cov = 0.0;
            for ($i = 0; $i < $nR; $i++) { $cov += ($resArr[$i] - $mMean) * ($mArr[$i] - $mm); }
            $rC = $cov / ($sdRes * $sdM * $nR);
            if (!is_finite($rC) || abs($rC) > 0.95) { continue; }
            $sh = $resArr;
            $nulls = [];
            for ($p = 0; $p < 200; $p++) {
                shuffle($sh);
                $cov2 = 0.0;
                for ($i = 0; $i < $nR; $i++) { $cov2 += ($sh[$i] - $mMean) * ($mArr[$i] - $mm); }
                $nulls[] = $cov2 / ($sdRes * $sdM * $nR);
            }
            sort($nulls);
            $z = 0;
            foreach ($nulls as $nv) { if (abs($nv) <= abs($rC)) { $z++; } }
            if ($z >= 195) { $cands[$mn] = abs($rC); }
        }
        arsort($cands);
        $selected[$sp] = array_slice(array_keys($cands), 0, 5, true);
    }
    file_put_contents($SEL_CACHE, json_encode($selected));
}

// --- данные для движка: отобранные фичи per-вид, floor-варианты ---
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];
$xml = file_get_contents('/tmp/ven_s006/twelveSpeciesT3.xml');
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }
$raw = file($VEN . '/ev3_communities.csv');
$communities = [];
$cur = null;
foreach ($raw as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (preg_match('/^([A-Z]{2}-|NONE)/', $line)) { $cur = rtrim($line); $communities[$cur] = []; continue; }
    if ($cur !== null && preg_match('/^[\d\s.eE+-]+$/', $line)) {
        $communities[$cur][] = array_map('floatval', preg_split('/\s+/', trim($line)));
    }
}

$buildTask = function (string $sp, float $floor) use ($communities, $ev3order, $selected, $metab, $params): ?array {
    $ms = $selected[$sp] ?? [];
    if (!$ms) { return null; }
    $X = []; $y = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            $xRow = [];
            foreach ($ev3order as $c => $sp2) { $xRow[$sp2] = $mat[$t][$c]; }
            $xi = $xRow[$sp]; $xin = $mat[$t + 1][array_search($sp, $ev3order, true)] ?? null;
            if ($xin === null || $xi < $floor) { continue; }
            $s = $params['u' . $sp];
            foreach ($ev3order as $j) { $s += $params['a' . $sp . $j] * $xRow[$j]; }
            $feats = [$xi];
            foreach ($ms as $mn) {
                $pool = 0.0;
                foreach ($xRow as $j => $xj) { if ($j !== $sp) { $pool += ($metab[$mn][$j] ?? 0.0) * $xj; } }
                $feats[] = $pool;
            }
            $X[] = $feats;
            $y[] = ($xin - $xi) / $xi - $s;
        }
    }
    $labels = array_merge(['x_i'], array_map(fn ($mn) => 'm_' . substr($mn, 0, 10), $ms));
    return ['X' => $X, 'y' => $y, 'labels' => $labels];
};

$TARGETS = ['CH', 'ER', 'BU', 'BH', 'BO'];
if (isset($argv[1])) {
    $sp = $argv[1];
    foreach ([0.01, 0.02, 0.03] as $floor) {
        $task = $buildTask($sp, $floor);
        if (!$task) { echo "$sp: нет отобранных фич\n"; exit(0); }
        if (count($task['y']) < 15) { echo "$sp floor=$floor: мало строк\n"; continue; }
        $g = new Grammar();
        $res = Search::find($task['X'], $task['y'], $g, 2, $task['labels'], 0.3, 0.15, 180.0);
        $bestSeen = Search::$lastBestCvSeen;
        printf("%s floor=%.2f (n=%d, %d фич): found=%s form=%s cv=%s best_seen=%.3f diag=%s\n",
            $sp, $floor, count($task['y']), count($task['labels']) - 1,
            ($res[0] ?? false) ? 'YES' : 'no', $res[2] ?? 'none',
            is_numeric($res[3] ?? null) ? round($res[3], 3) : 'NA', $bestSeen, $res[4] ?? '?');
    }
    exit(0);
}
// родитель: fork
$children = [];
foreach ($TARGETS as $t) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        passthru(PHP_BINARY . ' -d memory_limit=4G ' . escapeshellarg(__FILE__) . ' ' . $t, $code);
        exit($code);
    }
    $children[] = $pid;
}
foreach ($children as $pid) { pcntl_waitpid($pid, $st); }
echo "B5.12 done\n";
