<?php

/**
 * V0.18 WU-2: калибровочная таблица тики ↔ секунды (боевой профиль).
 *
 * Запуск: php scripts/v018_tick_calibration2.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('NO_BIRTH=1');
putenv('SEARCH_NO_PREREG=1');
putenv('SEARCH_BEAM_K=0');

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

BeeSwarm\Infra\Database::get();

/** Детерминированная noise-фикстура (перебор исчерпывает бюджет). */
function fx(int $nFeat, int $rows, int $seed): array
{
    $X = [];
    $y = [];
    mt_srand($seed);
    for ($i = 0; $i < $rows; $i++) {
        $row = [];
        for ($j = 0; $j < $nFeat; $j++) {
            $row[] = mt_rand() / mt_getrandmax() * 2 ** ($j % 12);
        }
        $X[] = $row;
        $y[] = mt_rand() / mt_getrandmax();
    }

    return [$X, $y];
}

$g = new Grammar();
$g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);

echo "START: beam=0 prod-like rows=30 php=" . PHP_VERSION . "\n";

foreach ([100, 1000, 5000] as $t) {
    for ($rep = 0; $rep < 3; $rep++) {
        [$X, $y] = fx(12, 30, 7);
        $t0 = microtime(true);
        $res = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 0.0, null, $t);
        $s = microtime(true) - $t0;
        printf("d=3 nFeat=12 rows=30 ticks=%5d rep=%d  %7.3fs  %8.0f t/s  diag=%s\n", $t, $rep, $s, $t / max($s, 0.0005), $res[5] ?? '?');
    }
}

// d=2 профиль (более дорогой тик: L0-порции до 32 кандидатов крупнее)
foreach ([1000, 5000, 20000] as $t) {
    for ($rep = 0; $rep < 2; $rep++) {
        [$X, $y] = fx(12, 30, 7);
        $t0 = microtime(true);
        $res = Search::find($X, $y, $g, 2, null, 0.0, 0.15, 0.0, null, $t);
        $s = microtime(true) - $t0;
        printf("d=2 nFeat=12 rows=30 ticks=%5d rep=%d  %7.3fs  %8.0f t/s  diag=%s\n", $t, $rep, $s, $t / max($s, 0.0005), $res[5] ?? '?');
    }
}

echo "DONE\n";
