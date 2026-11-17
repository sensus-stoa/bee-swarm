<?php

declare(strict_types=1);

/** WU-5 CCPP дебаг: почему V-задачи resample рефалят закон ((AT/RminAT)−RmaxAT). */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SWARM_DB_PATH=:memory:');
Database::setPath(':memory:');
Database::get();

$raw = file(__DIR__ . '/../data/CCPP_data.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(array_shift($raw));
$all = [];
foreach ($raw as $line) {
    $v = array_map('floatval', str_getcsv($line));
    if (count($v) === 5) {
        $all[] = $v;
    }
}

// 5 фич, как в прогоне (WU5_MAX_FEAT=5: AT V AP RH + PE)
mt_srand(1007);
$slice = [];
for ($i = 0; $i < 60; $i++) {
    $slice[] = $all[mt_rand(0, count($all) - 1)];
}
$data = array_map(fn ($r) => array_slice($r, 0, 5), $slice);
$X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
$y = array_map(fn ($r) => (float) $r[4], $data);
$labels = array_slice($header, 0, 4);

$g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), []));
$res = Search::find($X, $y, $g, 3, $labels, 0.2, 0.15, 0.0);
echo 'slice find: ', $res[0] ? 'YES' : 'no', ' cv=', $res[1], ' ', $res[2], PHP_EOL;

if ($res[0] || $res[2] !== 'none') {
    $canon = \BeeSwarm\Core\ExpressionNormalizer::normalize((string) $res[2]);
    echo 'canon: ', $canon, PHP_EOL;
    echo 'shape: ', LawShape::of($canon), PHP_EOL;
    echo 'target shape: ', LawShape::of('(AT/RminAT)−RmaxAT'), PHP_EOL;
}
