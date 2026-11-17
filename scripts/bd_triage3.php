<?php
declare(strict_types=1);
// V0.17 WU-2b триаж-3: изолирую носителя. Три ветки:
// (a) чистый процесс: сколько ops у Grammar, найд ли поиск (базлайн)
// (b) после записи 19 законов BD-домена в БД (БЕЗ прогонов Hive): поиск?
// (c) после полного BD-прогона: поиск?
putenv('SWARM_DB_PATH=:memory:');
putenv('SEARCH_DEPTH_MAX=2');
putenv('SEARCH_BEAM_K=0');
putenv('FORAGER_SOURCES=');
require __DIR__ . '/../vendor/autoload.php';

$branch = $argv[1] ?? 'a';

if ($branch === 'b' || $branch === 'c') {
    mt_srand(777);
    if ($branch === 'c') {
        $logFile = tempnam(sys_get_temp_dir(), 'bd_tri3_');
        $hive = new BeeSwarm\Hive\Hive(
            plateau: new BeeSwarm\Infra\PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 20,
            logFile: $logFile,
        );
        $hive->run();
        unlink($logFile);
        echo "after hive: laws=" . BeeSwarm\Infra\Database::get()->query('SELECT COUNT(*) FROM laws')->fetchColumn() . "\n";
    } else {
        // вставляю 19 законов напрямую (без Hive)
        $db = BeeSwarm\Infra\Database::get();
        for ($i = 1; $i <= 19; $i++) {
            $db->exec("INSERT OR IGNORE INTO laws (formula, domain, class) VALUES ('(Bfk{$i}test)', 'testdom{$i}', 'EMPIRICAL')");
        }
        echo "inserted dummy laws: " . $db->query('SELECT COUNT(*) FROM laws')->fetchColumn() . "\n";
    }
}

$g = new BeeSwarm\Core\Grammar();
echo "ops: " . count($g->all()) . "\n";
mt_srand(777);
$X = []; $y = [];
for ($i = 0; $i < 200; $i++) {
    $x0 = mt_rand() / mt_getrandmax() * 10;
    $x1 = mt_rand() / mt_getrandmax() * 10;
    $x2 = mt_rand() / mt_getrandmax() * 10;
    $X[] = [$x0, $x1, $x2];
    $y[] = $x0 * $x1 + $x0 * $x2;
}
$t0 = microtime(true);
$res = BeeSwarm\Core\Search::find($X, $y, $g, 2, null, 0.0, 0.15, 10.0);
printf("branch=%s: found=%s cv=%s formula=%s elapsed=%.2fs\n",
    $branch, var_export($res[0], true), $res[1] ?? '?', $res[2] ?? '?', microtime(true) - $t0);
