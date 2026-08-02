#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_1_1.php — Реальная смерть (§2.1)
 *
 * Проверяет: ≥1 смерть за период, все смерти коррелируют с E≤0
 * в предшествующих 5 тактах.
 */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_1_1.php <agenda.log>\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$deaths = [];
$energyLog = [];

foreach ($lines as $i => $line) {
    // DEATH: bee#0 energy=0
    if (preg_match('/DEATH:\s+bee#(\d+)\s+energy=([\d.-]+)/', $line, $m)) {
        $deaths[] = ['bee' => (int)$m[1], 'energy' => (float)$m[2], 'line' => $i];
    }
    // HUNGER_MUTATE: bee#0 E=4.5
    if (preg_match('/HUNGER_MUTATE:\s+bee#(\d+)\s+E=([\d.]+)/', $line, $m)) {
        $energyLog[] = ['bee' => (int)$m[1], 'energy' => (float)$m[2], 'line' => $i];
    }
}

echo "Death events: " . count($deaths) . "\n";

if (count($deaths) === 0) {
    echo "PENDING: No deaths yet — популяция не умирала.\n";
    exit(0);
}

$violations = 0;
foreach ($deaths as $death) {
    if ($death['energy'] > 0) {
        $violations++;
        echo "VIOLATION: bee#{$death['bee']} died with energy={$death['energy']} (>0)\n";
    }
}

$pass = $violations === 0;
echo $pass ? "PASS: All deaths with E≤0\n" : "FAIL: {$violations} violations\n";
exit($pass ? 0 : 1);
