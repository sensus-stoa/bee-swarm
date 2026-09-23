<?php

/**
 * V0.18 WU-2: калибровочная таблица тики ↔ секунды (TICK_BUDGET).
 *
 * Анти-подгонка (буква стори): скрипт = ОТЧЁТ о соотношении на ЭТОЙ машине,
 * НЕ источник «правильного» числа. Боевой тиковый default выводится из
 * 300s-эквивалента и фиксируется ДО боевых прогонов (в WU-4 документе).
 *
 * Env-шапка обязательна (питфолл 31.08): SWARM_DB_PATH=:memory:,
 * FORAGER_SOURCES=: — иначе скрипт молча ходит в прод-БД.
 *
 * Запуск: php scripts/v018_tick_calibration.php > Benchmarks-вывод
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('NO_BIRTH=1');
putenv('SEARCH_NO_PREREG=1');
putenv('SEARCH_BEAM_K=0'); // прод beam=off; калибровка = прод-режим (премортем INC-2)

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

BeeSwarm\Infra\Database::get();

const LOG = '/tmp/v018_calibration.log';

/**
 * Synthetic noise-фикстура (детерминированная): тик-расход измеряем на
 * ИСЧЕРПЫВАЮЩЕМ переборе (верный способ мерить цену тика — задача не
 * решается, бюджет съедается целиком).
 */
function noiseFixture(int $nFeat, int $rows): array
{
    $X = [];
    $y = [];
    mt_srand(42);
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

function csvFixture(string $path, int $targetCol): array
{
    $X = [];
    $y = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cells = explode(',', $line);
        $y[] = (float) $cells[$targetCol];
        unset($cells[$targetCol]);
        $X[] = array_map('floatval', array_values($cells));
    }

    return [$X, $y];
}

/** Прогон одного (fixture, depth, budgetTicks). Возвращает [sec, found, diag, ticks?]. */
function probe(array $X, array $y, int $depth, int $budgetTicks): array
{
    $g = new Grammar();
    $g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);
    $t0 = microtime(true);
    $res = Search::find($X, $y, $g, $depth, null, 0.0, 0.15, 0.0, null, $budgetTicks);
    $sec = microtime(true) - $t0;

    return [$sec, (bool) $res[0], (string) ($res[5] ?? '?')];
}

$logFp = fopen(LOG, 'w');
$START = [
    'env SWARM_DB_PATH=' . (getenv('SWARM_DB_PATH') ?: 'LOST'),
    'FORAGER_SOURCES=' . (getenv('FORAGER_SOURCES') ?: 'LOST'),
    'NO_BIRTH=' . (getenv('NO_BIRTH') ?: 'LOST'),
    'SEARCH_BEAM_K=' . (getenv('SEARCH_BEAM_K') ?: 'LOST'),
    'php=' . PHP_VERSION,
];
$line = 'START: ' . implode(' ', $START) . PHP_EOL;
fwrite($logFp, $line);
echo $line;

// Матрица: {fixture, depth, ticks[]}
$cases = [
    ['noise d=2 nFeat=5', fn (): array => noiseFixture(5, 40), 2],
    ['noise d=3 nFeat=8', fn (): array => noiseFixture(8, 40), 3],
];
$ticksSet = [100, 1000, 5000, 20000];

$rows = [];
foreach ($cases as [$label, $fix, $depth]) {
    [$X, $y] = $fix();
    foreach ($ticksSet as $ticks) {
        [$sec, $found, $diag] = probe($X, $y, $depth, $ticks);
        $rows[] = [$label, $depth, $ticks, $sec, $found, $diag];
        $line = sprintf("%-18s d=%d ticks=%6d  %7.3fs  found=%s diag=%s\n", $label, $depth, $ticks, $sec, $found ? 'Y' : 'n', $diag);
        fwrite($logFp, $line);
        echo $line;
    }
    // 300s-эквивалент: не гоняем 300s реально — экстраполяция от rate
}

// rate: тики/сек по каждой строке (два фазовых профиля: d=2 дешевле/тик)
$line = "\n=== rate (ticks/sec) ===\n";
echo $line;
foreach ($rows as [$label, $depth, $ticks, $sec]) {
    if ($ticks >= 1000) {
        $line = sprintf("%-18s d=%d  %8.0f ticks/sec\n", $label, $depth, $ticks / max($sec, 0.001));
        fwrite($logFp, $line);
        echo $line;
    }
}

$line = "NOTE: полная 300s-эквивалентная точка для БОЕВОЙ фазы (depth 3, прод-грамматика) — WU-4 прогон; здесь rate-профиль.\n";
echo $line;
fwrite($logFp, $line);
fclose($logFp);
