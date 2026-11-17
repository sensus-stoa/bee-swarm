<?php

declare(strict_types=1);

/** WU-5 дебаг-2: прогон doTick как в драйвере, но с трассировкой depth. */

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

$hive = new BeeSwarm\Hive\Hive(maxTicks: 0, logFile: '/tmp/probe9.log');
$hive->run();
$p = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'foragedTasksGlobal');
$p->setAccessible(true);
$p->setValue($hive, $tasks);

// Трассировка: что фактически читает DiscoveryEngine из env в момент вызова?
$engine = new ReflectionMethod(BeeSwarm\Hive\DiscoveryEngine::class, 'discover');
$engine->setAccessible(true);
// Перехват: оборачиваем getenv — проверим значение в момент doTick
$doTick = new ReflectionMethod(BeeSwarm\Hive\Hive::class, 'doTick');
$doTick->setAccessible(true);
$tickProp = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'tick');
$tickProp->setAccessible(true);
$tickProp->setValue($hive, 1);

echo "BEFORE doTick: SEARCH_DEPTH=", getenv('SEARCH_DEPTH') ?: 'LOST', PHP_EOL;
$doTick->invoke($hive);
echo "AFTER doTick: SEARCH_DEPTH=", getenv('SEARCH_DEPTH') ?: 'LOST', PHP_EOL;
echo "Log tail:\n";
$log = file('/tmp/probe9.log');
foreach (array_slice($log, -12) as $l) {
    echo $l;
}
