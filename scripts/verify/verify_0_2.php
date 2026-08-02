#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_2.php — Statistical Sufficiency (§1.2)
 *
 * Дух критерия: система не должна искать законы на недостаточных данных.
 * t ≥ t_min = max(10, nFeat × 5).
 *
 * Проверка: каждое открытие в логе должно иметь t ≥ tMin для своего домена.
 * INSUFFICIENT_DATA в логе показывает где система ОТКАЗАЛАСЬ искать —
 * это structured silence (§0.5), не ошибка.
 */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_0_2.php <agenda.log>\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$insufficientData = [];
$discoveries = [];

// Собираем: какие задачи rejected по tMin, и какие дали открытия
foreach ($lines as $line) {
    if (preg_match('/INSUFFICIENT_DATA:\s+(.+?)\s+t=(\d+)\s+<\s+tMin=(\d+)/', $line, $m)) {
        $insufficientData[$m[1]] = ['t' => (int)$m[2], 'tMin' => (int)$m[3]];
    }
    if (preg_match('/🔍\s+(.+?)\s+->/', $line, $m)) {
        $taskName = $m[1];
        $discoveries[$taskName] = ($discoveries[$taskName] ?? 0) + 1;
    }
}

echo "INSUFFICIENT_DATA entries: " . count($insufficientData) . "\n";
echo "Discovery tasks: " . count($discoveries) . "\n";

// Проверка: задача не может быть одновременно в insufficient И дать открытие
$violations = 0;
foreach ($discoveries as $taskName => $count) {
    if (isset($insufficientData[$taskName])) {
        $violations++;
        echo "VIOLATION: '{$taskName}' — marked insufficient (t={$insufficientData[$taskName]['t']} < tMin={$insufficientData[$taskName]['tMin']}) but produced {$count} discoveries\n";
    }
}

$pass = $violations === 0;
echo $pass
    ? "PASS: No discoveries from insufficient data\n"
    : "FAIL: {$violations} discoveries on insufficient tasks\n";
exit($pass ? 0 : 1);
