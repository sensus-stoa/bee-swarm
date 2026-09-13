<?php

declare(strict_types=1);

/**
 * WU-5 probe: что находит Search::find на срезах доменов (до включения экономики).
 * Сложность задачи проверяется php-пробой ДО включения (урок capability-growth 06.09).
 *
 * Запуск: php scripts/v14_wu5_probe.php data/feynman_kinetic_energy.csv 30
 * Аргументы: <csv> [rows]
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

$csvPath = $argv[1] ?? '';
$nRows = (int) ($argv[2] ?? 30);

$raw = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$first = str_getcsv($raw[0]);
$isHeader = ! is_numeric(trim($first[0]));
$header = $isHeader ? str_getcsv(array_shift($raw)) : array_map(fn ($i) => "x{$i}", range(0, count($first) - 1));
$rows = [];
foreach (array_slice($raw, 0, $nRows) as $line) {
    $vals = array_map('floatval', str_getcsv($line));
    if (count($vals) === count($header)) {
        $rows[] = $vals;
    }
}
$X = array_map(static fn (array $r): array => array_slice($r, 0, -1), $rows);
$y = array_map(static fn (array $r): float => (float) $r[count($r) - 1], $rows);

$g = new Grammar();
$g->restrictTo(array_keys(Grammar::BASE_OPS));

$t0 = microtime(true);
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');
putenv('SWARM_DB_PATH=:memory:');
$depth = (int) ($argv[3] ?? 2);
$res = Search::find($X, $y, $g, $depth, $header, 0.0, 0.15, 90.0);
$dt = microtime(true) - $t0;

printf("%s rows=%d: found=%s cv=%.5f formula=%s (%.1fs)\n",
    basename($csvPath), count($rows),
    $res[0] ? 'YES' : 'no', (float) ($res[1] ?? -1), (string) ($res[2] ?? '-'), $dt);
