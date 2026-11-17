#!/usr/bin/env php
<?php
/** Билдер hand-геномов Stage 2 (контрфактический тест функциональности). */
require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Hive\RouteGenome;

$ladder = RouteGenome::create('route_d4aa1add', '', 0, ['kind' => 'ALWAYS'], [
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
    ['op' => 'USE_MARKS', 'k' => 5],
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
    ['op' => 'USE_MARKS', 'k' => 5],
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
], 0.10, 5);
$f2x5 = RouteGenome::create('route_f2f2f2f2', '', 0, ['kind' => 'ALWAYS'], [
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
], 0.10, 5);
$short = RouteGenome::create('route_5c0de001', '', 0, ['kind' => 'ALWAYS'], [
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
    ['op' => 'USE_MARKS', 'k' => 5],
    ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
], 0.10, 3);

foreach ([$ladder, $f2x5, $short] as $g) {
    $err = RouteGenome::validate($g);
    if ($err !== []) {
        fwrite(STDERR, 'INVALID: ' . json_encode($err) . PHP_EOL);
        exit(1);
    }
}
file_put_contents(
    $argv[1] ?? '/tmp/hand_genomes.json',
    json_encode([$ladder, $f2x5, $short], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo "hand genomes written: ladder(route_d4aa1add), f2x5(route_f2f2f2f2), short(route_5c0de001)\n";
