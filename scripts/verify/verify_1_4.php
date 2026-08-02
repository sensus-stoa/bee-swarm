#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_1_4.php — Конкурентное распределение задач (§2.4)
 *
 * Проверяет: распределение не равномерно (разные пчёлы получают разное число задач).
 * Упрощённый χ²-аналог: считаем ROUTE на пчелу, проверяем что не все равны.
 */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_1_4.php <agenda.log>\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$routes = [];

foreach ($lines as $line) {
    // ROUTE: task -> bee#0
    if (preg_match('/ROUTE:\s+task\s+->\s+bee#(\d+)/', $line, $m)) {
        $beeIdx = (int)$m[1];
        $routes[$beeIdx] = ($routes[$beeIdx] ?? 0) + 1;
    }
}

ksort($routes);
echo "Routes per bee:\n";
foreach ($routes as $bee => $count) {
    echo "  bee#{$bee}: {$count}\n";
}

$totalRoutes = array_sum($routes);
$beeCount = count($routes);

if ($beeCount < 2) {
    echo "PENDING: Need ≥2 bees for routing distribution check\n";
    exit(0);
}

if ($totalRoutes < 10) {
    echo "PENDING: Need ≥10 routes for meaningful check (have {$totalRoutes})\n";
    exit(0);
}

// Проверка: не все пчёлы получили одинаковое число задач
$uniqueCounts = array_unique(array_values($routes));
if (count($uniqueCounts) === 1) {
    // Все получили одинаково — распределение равномерное → FAIL
    echo "FAIL: All bees received same number of tasks ({$uniqueCounts[0]}) — uniform distribution\n";
    exit(1);
}

echo "PASS: Non-uniform task distribution\n";
exit(0);
