#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_5.php — Plateau Honesty (§1.5)
 *
 * Проверяет:
 * 1. PLATEAU появляется в логе (система детектит застой)
 * 2. Нет DISCOVERY после PLATEAU без PLATEAU_EXIT
 * 3. consecutive_no_discovery не превышает 2×P без PLATEAU
 */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_0_5.php <agenda.log>\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (! $lines) {
    echo "SKIP: Empty log\n";
    exit(0);
}

$plateauCount = 0;
$inPlateau = false;
$discoveryInPlateau = 0;
$consecutiveNoDiscovery = 0;
$maxConsecutiveNoDiscovery = 0;
$lastPlateauLine = 0;

const P = 50; // §1.5: порог плато

foreach ($lines as $i => $line) {
    // Детектим PLATEAU
    if (str_contains($line, 'PLATEAU') && ! str_contains($line, 'PLATEAU_EXIT')) {
        if (! $inPlateau) {
            $plateauCount++;
            $inPlateau = true;
            $lastPlateauLine = $i;
        }
    }

    // PLATEAU_EXIT
    if (str_contains($line, 'PLATEAU_EXIT') || str_contains($line, 'FORAGER_NEW')) {
        $inPlateau = false;
    }

    // DISCOVERY во время плато — нарушение
    if ($inPlateau && str_contains($line, '🔍')) {
        $discoveryInPlateau++;
    }

    // Считаем consecutive_no_discovery
    if (str_contains($line, '🔍') || str_contains($line, 'DUPLICATE')) {
        $consecutiveNoDiscovery = 0;
    } else {
        $consecutiveNoDiscovery++;
        $maxConsecutiveNoDiscovery = max($maxConsecutiveNoDiscovery, $consecutiveNoDiscovery);
    }
}

echo "PLATEAU entries: {$plateauCount}\n";
echo "Discoveries during PLATEAU: {$discoveryInPlateau}\n";
echo "Max consecutive without discovery: {$maxConsecutiveNoDiscovery}\n";

// Проверки
$plateauExists = $plateauCount > 0;
$noDiscoveryDuringPlateau = $discoveryInPlateau === 0;
$plateauTriggeredOnTime = $maxConsecutiveNoDiscovery <= 2 * P;

$pass = $plateauExists && $noDiscoveryDuringPlateau && $plateauTriggeredOnTime;

if (! $pass) {
    $reasons = [];
    if (! $plateauExists) $reasons[] = "no PLATEAU in log";
    if (! $noDiscoveryDuringPlateau) $reasons[] = "{$discoveryInPlateau} discoveries during PLATEAU";
    if (! $plateauTriggeredOnTime) $reasons[] = "max {$maxConsecutiveNoDiscovery} consecutive without PLATEAU (threshold: " . (2*P) . ")";
    echo "FAIL: " . implode('; ', $reasons) . "\n";
    exit(1);
}

echo "PASS: Plateau detection working correctly\n";
exit(0);
