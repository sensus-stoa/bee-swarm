#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_1_5.php — Эволюционная динамика (§2.5)
 *
 * Проверяет: generation tracking активен, diversity > 0.
 */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_1_5.php <agenda.log>\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$gens = [];
$diversities = [];

foreach ($lines as $line) {
    // GEN: 1 pop=4 unique=3 diversity=0.25 avg|G|=12
    if (preg_match('/GEN:\s+(\d+)\s+pop=(\d+)\s+unique=(\d+)\s+diversity=([\d.]+)\s+avg\|G\|=([\d.]+)/', $line, $m)) {
        $gens[] = [
            'gen' => (int)$m[1],
            'pop' => (int)$m[2],
            'unique' => (int)$m[3],
            'diversity' => (float)$m[4],
            'avgSize' => (float)$m[5],
        ];
    }
}

echo "Generations: " . count($gens) . "\n";

if (count($gens) < 2) {
    echo "PENDING: Need ≥2 generations for dynamics check\n";
    exit(0);
}

$violations = 0;

// Проверка: diversity > 0 хотя бы в одном поколении
$maxDiversity = max(array_column($gens, 'diversity'));
if ($maxDiversity <= 0) {
    $violations++;
    echo "VIOLATION: Zero diversity across all generations\n";
}

// Проверка: популяция не выродилась (pop ≥ 2)
$lastGen = end($gens);
if ($lastGen['pop'] < 2) {
    $violations++;
    echo "VIOLATION: Population collapsed to {$lastGen['pop']} at gen {$lastGen['gen']}\n";
}

$pass = $violations === 0;

echo "Max diversity: {$maxDiversity}\n";
echo "Last gen: {$lastGen['gen']} pop={$lastGen['pop']} diversity={$lastGen['diversity']}\n";
echo $pass ? "PASS: Evolutionary dynamics active\n" : "FAIL: {$violations} violations\n";
exit($pass ? 0 : 1);
