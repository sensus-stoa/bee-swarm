#!/usr/bin/env php
<?php
declare(strict_types=1);

/** verify_0_5.php — Plateau Honesty (§1.5) */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_0_5.php <agenda.log>\n";
    exit(0);
}

$log = file_get_contents($logFile);
preg_match_all('/🏔️ PLATEAU/', $log, $plateauEntries);
$count = count($plateauEntries[0]);

$pass = $count > 0;
echo "PLATEAU events: {$count}\n";
echo $pass ? "PASS: Plateau detection active\n" : "FAIL: No plateau events\n";
exit($pass ? 0 : 1);
