<?php
declare(strict_types=1);
// V0.17 WU-2b триаж: воспроизвести clean1-провал в одном процессе:
// BD-фикстура (сид 777, 20 тиков) → затем certify-поиск syntheticData.
// Гипотеза: грамматика, выросшая от BD (13 ops vs 11), меняет поиск certify.
putenv('SWARM_DB_PATH=:memory:');
putenv('SEARCH_DEPTH_MAX=2');
putenv('SEARCH_BEAM_K=10');
putenv('FORAGER_SOURCES=');
require __DIR__ . '/../vendor/autoload.php';

// --- Шаг 1: BD-прогон (копия фикстуры, сид 777) ---
mt_srand(777);
$logFile = tempnam(sys_get_temp_dir(), 'bd_triage_');
$hive = new BeeSwarm\Hive\Hive(
    plateau: new BeeSwarm\Infra\PlateauDetector(50, plateauSleepUs: 0),
    maxTicks: 20,
    logFile: $logFile,
);
$hive->run();
unlink($logFile);
$db = BeeSwarm\Infra\Database::get();
echo "laws after BD: " . $db->query('SELECT COUNT(*) c FROM laws')->fetchColumn() . "\n";
echo "grammar_ops: " . $db->query("SELECT COUNT(*) c FROM grammar_ops")->fetchColumn() . "\n";
$g = new BeeSwarm\Core\Grammar();
echo "ops in new Grammar: " . count($g->all()) . "\n";

// --- Шаг 2: certify-поиск (копия syntheticData, beam=0) ---
putenv('SEARCH_BEAM_K=0');
mt_srand(777);
$X = []; $y = [];
for ($i = 0; $i < 200; $i++) {
    $x0 = mt_rand() / mt_getrandmax() * 10;
    $x1 = mt_rand() / mt_getrandmax() * 10;
    $x2 = mt_rand() / mt_getrandmax() * 10;
    $X[] = [$x0, $x1, $x2];
    $y[] = $x0 * $x1 + $x0 * $x2;
}
$res = BeeSwarm\Core\Search::find($X, $y, new BeeSwarm\Core\Grammar(), 2, null, 0.0, 0.15, 10.0);
printf("search after BD: found=%s cv=%s formula=%s\n",
    var_export($res[0], true), $res[1] ?? '?', $res[2] ?? '?');

// --- Шаг 3: тот же поиск в ЧИСТОЙ БД (соль-эквивалент) ---
putenv('SWARM_DB_PATH=:memory:');
mt_srand(777);
$X = []; $y = [];
for ($i = 0; $i < 200; $i++) {
    $x0 = mt_rand() / mt_getrandmax() * 10;
    $x1 = mt_rand() / mt_getrandmax() * 10;
    $x2 = mt_rand() / mt_getrandmax() * 10;
    $X[] = [$x0, $x1, $x2];
    $y[] = $x0 * $x1 + $x0 * $x2;
}
$g2 = new BeeSwarm\Core\Grammar();
echo "ops in fresh Grammar: " . count($g2->all()) . "\n";
$res2 = BeeSwarm\Core\Search::find($X, $y, $g2, 2, null, 0.0, 0.15, 10.0);
printf("search fresh: found=%s cv=%s formula=%s\n",
    var_export($res2[0], true), $res2[1] ?? '?', $res2[2] ?? '?');
echo "TRIAGE DONE\n";
