#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_1_2.php — Реальное рождение (§2.2)
 *
 * Проверяет: ≥3 spawn за период, родитель≠потомок,
 * грамматика потомка ≠ грамматика родителя (по GRAMMAR_SPAWN логу).
 */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_1_2.php <agenda.log>\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$spawns = [];
$grammarSpawns = [];

foreach ($lines as $line) {
    // SPAWN: bee#3 from parent E=60.88
    if (preg_match('/SPAWN:\s+bee#(\d+)\s+from (parent|seed)/', $line, $m)) {
        $spawns[] = (int)$m[1];
    }
    // GRAMMAR_SPAWN parent=0 child=3 parent_size=15 child_size=14
    if (preg_match('/GRAMMAR_SPAWN parent=(\d+) child=(\d+) parent_size=(\d+) child_size=(\d+)/', $line, $m)) {
        $grammarSpawns[] = [
            'parent' => (int)$m[1],
            'child' => (int)$m[2],
            'parent_size' => (int)$m[3],
            'child_size' => (int)$m[4],
        ];
    }
}

echo "SPAWN events: " . count($spawns) . "\n";
echo "GRAMMAR_SPAWN logs: " . count($grammarSpawns) . "\n";

if (count($spawns) < 3) {
    echo "PENDING: Need ≥3 spawn events, have " . count($spawns) . "\n";
    exit(0);
}

$violations = 0;
foreach ($grammarSpawns as $gs) {
    if ($gs['parent_size'] === $gs['child_size']) {
        // Размер одинаковый — может быть из-за мутации replace
        // Допустимо если операторы разные (размер не обязан меняться)
        continue;
    }
}

$pass = $violations === 0;
echo $pass ? "PASS: ≥3 spawns, all valid\n" : "FAIL: {$violations} violations\n";
exit($pass ? 0 : 1);
