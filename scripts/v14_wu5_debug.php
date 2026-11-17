<?php

declare(strict_types=1);

/** WU-5 дебаг: почему живой путь в прогоне не находит dot на depth 3. */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\DiscoveryEngine;
use BeeSwarm\Infra\Database;

putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SEARCH_DEPTH=3');
putenv('SEARCH_DEPTH_MAX=3');
putenv('FORAGER_SOURCES=:');
Database::setPath(':memory:');
Database::get();

$raw = file(__DIR__ . '/../data/feynman_dot_product.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$all = [];
foreach ($raw as $line) {
    $all[] = array_map('floatval', str_getcsv($line));
}

// Репликация драйвера seed=2: mt_srand(2*1000+7), 60 строк
mt_srand(2007);
$tasks = [];
for ($k = 0; $k < 8; $k++) {
    $slice = [];
    for ($i = 0; $i < 60; $i++) {
        $slice[] = $all[mt_rand(0, count($all) - 1)];
    }
    $tasks[] = $slice;
}

// Репликация doTick: cap-30 array_rand + col_labels как в драйвере (x0..x6)
$data = $tasks[0];
$keys = array_rand($data, 30);
$data = array_map(fn ($k) => $data[$k], $keys);
$data = array_values($data);
$X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
$y = array_map(fn ($r) => (float) $r[count($r) - 1], $data);

$ops = array_merge(Grammar::baseOpNames(), []); // как в doDiscoverTick: bee grammar пустая

// 1) engine с cvTrainMax=0.15 (как после calibrate)
$engine = new DiscoveryEngine();
[$cands, $bestCv, $searchCv, $diag, $lastF] = $engine->discover($X, $y, $ops, 0.15, array_map(fn ($i) => "x{$i}", range(0, 6)), 0.2, null, 30);
echo "engine colLabels: cands=", count($cands), " diag={$diag} cv={$bestCv} lastF=", ($lastF ?? 'null'), PHP_EOL;

// 2) прямая Search::find depth 3 c colLabels (как engine делает)
$g = Grammar::fromOps($ops);
$res = Search::find($X, $y, $g, 3, array_map(fn ($i) => "x{$i}", range(0, 6)), 0.2, 0.15, 0.0);
echo "find colLabels d3: ", $res[0] ? 'YES' : 'no', " cv={$res[1]} {$res[2]}", PHP_EOL;

// 3) БЕЗ colLabels (null)
$res2 = Search::find($X, $y, $g, 3, null, 0.2, 0.15, 0.0);
echo "find null d3: ", $res2[0] ? 'YES' : 'no', " cv={$res2[1]} {$res2[2]}", PHP_EOL;
