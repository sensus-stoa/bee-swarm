#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_all.php — Stage 0 Gate
 *
 * Запускает все verify_0_* скрипты. Stage Gate logic:
 *   verify_0_null FAIL → BLOCKED (не проверяем остальные)
 *
 * Использование: php scripts/verify/verify_all.php [--log=agenda.log]
 * Exit 0 = STAGE 0 PASS, Exit 1 = FAIL
 */

$scriptDir = __DIR__;
$logFile = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--log=')) {
        $logFile = substr($arg, 6);
    }
}

$scripts = [
    'verify_0_null.php' => [],
    'verify_0_1.php' => [],
    'verify_0_2.php' => $logFile ? [$logFile] : [],
    'verify_0_3.php' => [],
    'verify_0_4.php' => [],
    'verify_0_5.php' => $logFile ? [$logFile] : [],
    'verify_0_6.php' => [],
    'verify_0_7.php' => [],
    'verify_0_8.php' => [],
];

$failed = [];
$passed = [];
$blocked = false;

foreach ($scripts as $script => $args) {
    $path = "{$scriptDir}/{$script}";
    if (! file_exists($path)) {
        echo "  {$script}: MISSING\n";
        $failed[] = $script;
        continue;
    }

    $cmd = 'php ' . escapeshellarg($path);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    $short = substr(implode(' ', $output), 0, 80);
    if ($exitCode === 0) {
        echo "  {$script}: PASS\n";
        $passed[] = $script;
    } else {
        echo "  {$script}: FAIL — {$short}\n";
        $failed[] = $script;

        // Stage Gate: null-calibration FAIL → BLOCKED
        if ($script === 'verify_0_null.php') {
            echo "\n⛔ BLOCKED: System null-calibration failed. Stage Gate blocks all checks.\n";
            $blocked = true;
            break;
        }
    }
}

echo "\n=== STAGE 0 RESULT ===\n";
echo "Passed: " . count($passed) . "/" . count($scripts) . "\n";

if (! empty($failed)) {
    echo "Failed: " . implode(', ', $failed) . "\n";
}

if ($blocked) {
    echo "BLOCKED\n";
    exit(1);
}

$allPass = empty($failed);
echo $allPass ? "STAGE 0 PASS\n" : "STAGE 0 FAIL\n";
exit($allPass ? 0 : 1);
