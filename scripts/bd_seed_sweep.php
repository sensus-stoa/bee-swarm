<?php
declare(strict_types=1);
// V0.17 WU-2b: подбор сида для BD-фикстуры — phpunit-окружение (env как в xml).
// Запуск: vendor/bin/phpunit недоступен для произвольного скрипта с env-force,
// поэтому env-шапка вручную = phpunit.xml (force=true семантика).
putenv('SWARM_DB_PATH=:memory:');
putenv('SEARCH_DEPTH_MAX=2');
putenv('SEARCH_BEAM_K=10');
putenv('FORAGER_SOURCES=');

require __DIR__ . '/../vendor/autoload.php';

foreach ([7, 42, 777, 2024, 31337, 90210] as $seed) {
    \BeeSwarm\Infra\Database::reset(); // свежая БД на сид
    mt_srand($seed);
    $logFile = tempnam(sys_get_temp_dir(), 'bd_seed_');
    $hive = new BeeSwarm\Hive\Hive(
        plateau: new BeeSwarm\Infra\PlateauDetector(50, plateauSleepUs: 0),
        maxTicks: 20,
        logFile: $logFile,
    );
    $hive->run();
    $log = file_get_contents($logFile);
    unlink($logFile);
    preg_match_all('/🔍.*\[(\w+)\]/', $log, $m);
    $domains = array_count_values($m[1]);
    $formulas = count(array_unique($m[0]));
    printf("seed=%d domains=%s unique=%d\n", $seed, json_encode($domains), $formulas);
    mt_srand(); // restore
}
echo "SEED-SWEEP DONE\n";
