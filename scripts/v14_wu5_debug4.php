<?php

declare(strict_types=1);

/** WU-5 дебаг-4: ВНУТРИ doDiscoverTick перехватываем args DiscoveryEngine::discover. */

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

$hive = new BeeSwarm\Hive\Hive(maxTicks: 0, logFile: '/tmp/probe13.log');
$hive->run();

// АВТОЛОАД-класс-патч: переопределяем DiscoveryEngine? Нельзя.
// Вместо этого — трассировка через runkit нет. Патчим файл движка? Запрещено (движок).
// РЕШЕНИЕ: логируем изнутри через bind: переопределяем Search::find? final static.
// Практический трейс: иглометью — сравнить compiled vars doDiscoverTick невозможнo.
// Честный трейс: обёртка над DiscoveryEngine через класс-наследник в loadpath нет.

// АЛЬТЕРНАТИВА: проверяем ГИПОТЕЗУ БЕЗ ТРАССИРОВКИ:
// живой путь отличается ТОЛЬКО отсутствием ручной инжекции routedBee.
// => driver-код + инжекция = probe12 (нашёл). driver-код без = НЕ находит.
// Значит route()/NOVELTY/noveltyReward или chargeSearch ИСКАЖАЮТ state/data.
// chargeSearch: НЕ трогает данные. NOVELTY reward: energy +.
// route(): mt_rand сдвиги. => ДАННЫЕ/порог те же, значит между probe12 и
// driver отличается только ЕЩЁ ОДНО: probe12 -> Hive::run() запускался
// до инжекции пула, driver тоже...
// Проверка через время: CALIBRATE 15s (probe12) = 100 пермутаций depth=1.
// driver тоже 15s. Потом Search::find depth=3: probe12 1s → FOUND,
// driver 2s → cvBest 9.99. Расхождение в ПАРАМЕТРЕ cvTrainMax!
// probe12: engine discover получает cvTrainMax=0.15 (epsilon Cache MISS →
// calibrate вернёт 0.15 → тот же). Хм.
// ОСТАЁТСЯ ОДНО: $testRatio=0.2 одинаков; colLabels одинаков...
// ФИНАЛЬНЫЙ ТЕСТ: driver-путь + инжекция routedBee (как probe12) на 1 тик:
$beesP = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'bees');
$beesP->setAccessible(true);
$bees = $beesP->getValue($hive);
$rbP = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'routedBee');
$rbP->setAccessible(true);
$rbP->setValue($hive, $bees[0]);
$trP = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'taskRouter');
$trP->setAccessible(true);
$trP->setValue($hive, new BeeSwarm\Hive\TaskRouter($bees, 10));

$p = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'foragedTasksGlobal');
$p->setAccessible(true);
$p->setValue($hive, $tasks);
$doTick = new ReflectionMethod(BeeSwarm\Hive\Hive::class, 'doTick');
$doTick->setAccessible(true);
$t = new ReflectionProperty(BeeSwarm\Hive\Hive::class, 'tick');
$t->setAccessible(true);
$t->setValue($hive, 1);
$doTick->invoke($hive);
echo "WITH-INJECTION TICK DONE\n";
foreach (array_slice(file('/tmp/probe13.log'), -5) as $l) {
    echo $l;
}
