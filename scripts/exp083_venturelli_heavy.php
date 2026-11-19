<?php
declare(strict_types=1);

/**
 * B6.1: Тяжёлый прогон — движок на ВСЕХ 97 метаболитах, per-вид pcntl.
 *
 * Ресурсы: машина 16GB, доступно ~7GB free + 2 cache; 12 ядер, idle.
 * Схема: по одному процессу на вид (12 процессов max, но у 11 видов есть
 * отбор — гоняем 11); memory_limit=900M на ребёнка (11 x 0.9G = 9.9G —
 * влезает в доступное с запасом, ООМ не достигаем).
 * budget 900s/вид. :memory: БД. Если ребёнок ООМ — помечаем, тот вид
 * перегоняется последовательно с блоками по 24 фичи.
 */

require __DIR__ . '/exp070_shared.php';
require __DIR__ . '/../vendor/autoload.php';
error_reporting(E_ALL & ~E_WARNING);

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];

// их T3
$xml = file_get_contents('/tmp/ven_s006/twelveSpeciesT3.xml');
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

// EV4
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
$mtNames = array_keys($metab);

// EV3
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

// --- задача вида: X=[x_i + 97 пулов], y=residual их T3, floor=0.01 ---
$buildTask = function (string $sp) use ($communities, $ev3order, $metab, $mtNames, $params): ?array {
    $X = []; $y = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            $xRow = [];
            foreach ($ev3order as $c => $sp2) { $xRow[$sp2] = $mat[$t][$c]; }
            $xi = $xRow[$sp]; $xin = $mat[$t + 1][array_search($sp, $ev3order, true)] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $s = $params['u' . $sp];
            foreach ($ev3order as $j) { $s += $params['a' . $sp . $j] * $xRow[$j]; }
            $feats = [$xi];
            foreach ($mtNames as $mn) {
                $pool = 0.0;
                foreach ($xRow as $j => $xj) { if ($j !== $sp) { $pool += ($metab[$mn][$j] ?? 0.0) * $xj; } }
                $feats[] = $pool;
            }
            $X[] = $feats;
            $y[] = ($xin - $xi) / $xi - $s;
        }
    }
    return ['X' => $X, 'y' => $y];
};

$runSpecies = function (string $sp, array $featSubset = null) use ($buildTask, $mtNames, $speciesEV4): array {
    $task = $buildTask($sp);
    if (!$task || count($task['y']) < 15) { return ['sp' => $sp, 'err' => 'мало строк']; }
    $X = $task['X']; $y = $task['y'];
    $labels = array_merge(['x_i'], array_map(fn ($i) => 'm' . $i . '_' . substr($mtNames[$i - 1], 0, 14), range(1, count($mtNames))));
    if ($featSubset !== null) {
        $keep = array_merge([0], $featSubset);
        $X = array_map(fn ($row) => array_values(array_intersect_key($row, array_flip($keep))), $X);
        $labels = array_values(array_intersect_key($labels, array_flip($keep)));
    }
    $g = new Grammar();
    $res = Search::find($X, $y, $g, 2, $labels, 0.25, 0.15, 900.0);
    return [
        'sp' => $sp, 'nFeat' => count($labels) - 1, 'n' => count($y),
        'found' => $res[0] ?? false, 'form' => $res[2] ?? 'none',
        'cv' => $res[3] ?? 9.99, 'best_seen' => Search::$lastBestCvSeen,
        'diag' => $res[4] ?? '?',
    ];
};

$TARGETS = $ev3order;
if (isset($argv[1])) {
    // ребёнок: один вид, БЛОКИ по 24 метаболита (97 фич целиком = OOM L1-пула:
    // 97x96/2x11 ops = 52K форм x векторы — 900M мало; 24x23/2x11 = 3K — ок)
    $sp = $argv[1];
    if ($sp === 'PC') { echo "PC: пропущен (мало строк)\n"; exit(0); }
    ini_set('memory_limit', '2G');
    Database::setPath(':memory:');
    $blocks = array_chunk(range(1, count($mtNames)), 24);
    $best = ['cv' => 9.99, 'form' => 'none', 'best_seen' => 9.99, 'found' => false];
    foreach ($blocks as $bi => $blk) {
        $task = $buildTask($sp);
        if (!$task || count($task['y']) < 15) { continue; }
        $keep = array_merge([0], $blk);
        $X = array_map(fn ($row) => array_values(array_intersect_key($row, array_flip($keep))), $task['X']);
        $labels = array_merge(['x_i'], array_map(fn ($i) => 'm' . $i . '_' . substr($mtNames[$i - 1], 0, 14), $blk));
        $g = new Grammar();
        $res = Search::find($X, $task['y'], $g, 2, $labels, 0.25, 0.15, 600.0);
        if (Search::$lastBestCvSeen < $best['best_seen']) {
            $best = ['cv' => $res[3] ?? 9.99, 'form' => $res[2] ?? 'none',
                     'best_seen' => Search::$lastBestCvSeen, 'found' => $res[0] ?? false,
                     'diag' => $res[4] ?? '?', 'block' => $bi];
        }
    }
    file_put_contents('/tmp/b61_' . $sp . '.json', json_encode(array_merge(
        ['sp' => $sp, 'n' => count($task['y'] ?? []), 'nFeat' => count($mtNames)], $best)) . "\n");
    exit(0);
}

// родитель: fork с ограничением одновременности 7 (11 x 0.9G > доступно; 7 x 0.9 = 6.3G — безопасно)
$MAXPAR = 3;
$running = [];
$done = [];
$queue = array_values(array_diff($TARGETS, ['PC']));
$logF = '/tmp/b61_results.jsonl';

$launch = function (string $sp) use (&$running) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        passthru(PHP_BINARY . ' -d memory_limit=900M ' . escapeshellarg(__FILE__) . ' ' . $sp . ' 2>/tmp/b61_' . $sp . '.err', $code);
        exit($code);
    }
    $running[$pid] = $sp;
};

while ($queue || $running) {
    while ($queue && count($running) < $MAXPAR) {
        $sp = array_shift($queue);
        $launch($sp);
        usleep(150000); // разнести старты
    }
    // собрать завершившихся
    $status = 0;
    $pid = pcntl_wait($status, WNOHANG);
    if ($pid > 0) {
        $sp = $running[$pid];
        unset($running[$pid]);
        $done[] = $sp;
    } else {
        usleep(300000);
    }
}

echo "=== B6.1 done ===\n";
foreach (glob('/tmp/b61_*.json') ?: [] as $f) {
    $r = json_decode((string) file_get_contents($f), true);
    if (!$r) { continue; }
    printf("%-3s (n=%3d, %2d фич): found=%s form=%-40.40s cv=%s best=%s diag=%s\n",
        $r['sp'], $r['n'] ?? 0, $r['nFeat'] ?? 0, ($r['found'] ?? false) ? 'YES' : 'no',
        $r['form'] ?? 'none',
        is_numeric($r['cv'] ?? null) ? round($r['cv'], 3) : '?',
        is_numeric($r['best_seen'] ?? null) ? round($r['best_seen'], 3) : '?',
        $r['diag'] ?? '?');
}
