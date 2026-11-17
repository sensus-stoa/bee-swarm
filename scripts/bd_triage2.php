<?php
declare(strict_types=1);
// V0.17 WU-2b триаж-2: beam-гипотеза. beam=0 → Search::find на
// syntheticData(777) должен быть GREEN (соло-тест подтверждает).
// Проверяю: beam=0 в probe, и время поиска.
putenv('SWARM_DB_PATH=:memory:');
putenv('SEARCH_DEPTH_MAX=2');
putenv('FORAGER_SOURCES=');
require __DIR__ . '/../vendor/autoload.php';

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
$t0 = microtime(true);
$res = BeeSwarm\Core\Search::find($X, $y, new BeeSwarm\Core\Grammar(), 2, null, 0.0, 0.15, 10.0);
printf("beam=0 fresh: found=%s cv=%s formula=%s elapsed=%.2fs\n",
    var_export($res[0], true), $res[1] ?? '?', $res[2] ?? '?', microtime(true) - $t0);
echo "TRIAGE2 DONE\n";
