<?php

declare(strict_types=1);

/**
 * WU-5 дебаг-3: полный прогон одного тика с трассировкой env ВНУТРИ doDiscoverTick
 * через debug_backtrace-free патч: логируем getenv при вызове движка.
 * Идентичен драйверу поимённо ( те же putenv, тот же порядок), НО с проверкой.
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Infra\Database;

putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SEARCH_DEPTH=3');
putenv('SEARCH_DEPTH_MAX=3');
putenv('NO_BASE_TASKS=1');
putenv('FORAGER_SOURCES=:');
putenv('ESCROW_GRACE_TASKS=0');
putenv('SWARM_DB_PATH=:memory:');
Database::setPath(':memory:');
Database::get();

$raw = file(__DIR__ . '/../data/feynman_dot_product.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$all = [];
foreach ($raw as $line) {
    $all[] = array_map('floatval', str_getcsv($line));
}

mt_srand(2007);
$tasks = [];
for ($k = 0; $k < 8; $k++) {
    $slice = [];
    for ($i = 0; $i < 60; $i++) {
        $slice[] = $all[mt_rand(0, count($all) - 1)];
    }
    $tasks[] = [
        'name' => "foraged_csv_feynman_dot_v{$k}_s2->c6",
        'data' => $slice,
        'domain' => 'feynman_dot',
        'content' => "seed 2 variant {$k}",
        'source_path' => 'data/feynman_dot_product.csv',
        'col_labels' => array_map(fn ($i) => "x{$i}", range(0, 6)),
    ];
}

$hive = new BeeSwarm\Hive\Hive(maxTicks: 0, logFile: '/tmp/probe12.log');
$hive->run();
$p = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'foragedTasksGlobal');
$p->setAccessible(true);
$p->setValue($hive, $tasks);

// Инжекция routedBee+taskRouter (как probe6, который НАШЁЛ)
$beesP = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'bees');
$beesP->setAccessible(true);
$bees = $beesP->getValue($hive);
$rbP = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'routedBee');
$rbP->setAccessible(true);
$rbP->setValue($hive, $bees[0]);
$trP = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'taskRouter');
$trP->setAccessible(true);
$trP->setValue($hive, new BeeSwarm\Hive\TaskRouter($bees, 10));

$doTick = new ReflectionMethod(BeeSwarm\Hive\Hive::class, 'doTick');
$doTick->setAccessible(true);
$t = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'tick');
$t->setAccessible(true);
$t->setValue($hive, 1);
$doTick->invoke($hive);
echo "DEPTH=", getenv('SEARCH_DEPTH'), " TICK DONE\n";
foreach (array_slice(file('/tmp/probe12.log'), -6) as $l) {
    echo $l;
}
