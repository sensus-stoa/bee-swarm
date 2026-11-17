<?php

declare(strict_types=1);

/** WU-5 Concrete дебаг: живой путь GRAMMAR при том, что прямой find находит (cv=0.074). */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;
use BeeSwarm\Infra\Database;

putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SWARM_DB_PATH=:memory:');
Database::setPath(':memory:');
Database::get();

$raw = file(__DIR__ . '/../data/concrete_strength.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$all = [];
foreach ($raw as $line) {
    $v = array_map('floatval', str_getcsv($line));
    if (count($v) === 9) {
        $all[] = $v;
    }
}

mt_srand(4007);
$tasks = [];
for ($k = 0; $k < 8; $k++) {
    $slice = [];
    for ($i = 0; $i < 100; $i++) {
        $slice[] = $all[mt_rand(0, count($all) - 1)];
    }
    $tasks[] = $slice;
}

// v2 — первая FAILED в прогоне; cap-30 в doTick
$data = $tasks[2];
$keys = array_rand($data, 30);
$data = array_values(array_map(fn ($k) => $data[$k], $keys));
$X = array_map(fn ($r) => array_slice($r, 0, 5), $data);
$y = array_map(fn ($r) => (float) $r[5], $data);

$ops = array_merge(Grammar::baseOpNames(), ['+', '×', '−', '/', 'min', 'max', 'sq']); // базовая пчела
$engine = new DiscoveryEngine();
[$cands, $bestCv, $searchCv, $diag, $lastF] = $engine->discover($X, $y, $ops, 0.0865, array_map(fn ($i) => "x{$i}", range(0, 5)), 0.2, null, 30);
echo "v2 slice: cands=", count($cands), " diag={$diag} bestCv={$bestCv} searchCv={$searchCv} lastF=", ($lastF ?? 'null'), PHP_EOL;
