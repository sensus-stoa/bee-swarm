<?php
declare(strict_types=1);

/**
 * S1.6 CAPABILITY BENCHMARK (2.5-бис, 14.08).
 *
 * 20 выразимых задач (калибровочный класс). Два замера улья:
 *   S_1  = число решённых задач к поколению 1;
 *   S_10 = число решённых задач к поколению 10.
 * Критерий: S_10 ≥ S_1 + 1 (способность растёт в эволюции!).
 *
 * Запуск: SWARM_DB_PATH=/tmp/cap_bench.db php scripts/benchmark_capability.php
 * Юнит-тест (CapabilityBenchmarkTest) проверяет минимальную живость;
 * этот скрипт — настоящий критерий §2.5-бис для verify_1_*.
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

// 1. 20 задач (выразимые, как heldout_calibration v1.1)
$tasks = [];
for ($i = 0; $i < 20; $i++) {
    $x = ($i * 7) % 11 + 2;
    if ($i < 10) {
        // ПРОСТЫЕ (depth 1): часть решается сразу
        $kind = $i % 4;
        $y = match ($kind) {
            0 => $x, 1 => 2 * $x, 2 => $x * $x, default => max($x, 2),
        };
        $tasks[] = ['simple', [$x], $y, 1];
    } else {
        // ПОРОГОВЫЕ (depth 2, двухфичевые!): S_1 не решает — рост тут!
        $z = ($i * 5) % 13 + 3;
        $tasks[] = ['deep', [$x, $z], $x * $z, 2];
    }
}

// 2. Два замера: S_1 (свежий улей) и S_10 (10 поколений эволюции)
function measure(array $tasks, Grammar $g): int
{
    $solved = 0;
    foreach ($tasks as [$kind, $X, $y, $depth]) {
        [$found] = Search::find([$X], [$y], $g, $depth);
        if ($found) {
            $solved++;
        }
    }
    return $solved;
}

$g = new Grammar(['add', 'mul', 'sub', 'div', 'max', 'min']);

$s1 = measure($tasks, $g);
echo "S_1  = {$s1}/20\n";

// Поколение 10: моделируем эволюцию — улей 50 тиков (накопление культуры)
$hive = new BeeSwarm\Hive\Hive(
    plateau: new BeeSwarm\Infra\PlateauDetector(50, plateauSleepUs: 0),
    maxTicks: 10,
    logFile: tempnam(sys_get_temp_dir(), 'capbench_')
);
$hive->run();
$s10 = measure($tasks, $g);
echo "S_10 = {$s10}/20\n";

$pass = $s10 >= $s1 + 1;
echo $pass ? "PASS: S_10 >= S_1 + 1\n" : "PENDING/FAIL: S_10 < S_1 + 1\n";
exit($pass ? 0 : 1);
