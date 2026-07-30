#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_1.php — Held-Out Validation (§1.1)
 *
 * Протокол требует: count(OVERFIT)=0 И count(DISCOVERY)>0.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();

// Все законы должны иметь held-out CV
$laws = $engine->query("SELECT name, formula, cv FROM laws");
$discoveries = 0;
$withoutCV = 0;

foreach ($laws as $law) {
    if ($law['cv'] !== null) {
        $discoveries++;
    } else {
        $withoutCV++;
    }
}

// Проверить OVERFIT в логе
$logFile = $argv[1] ?? null;
$overfitCount = 0;
if ($logFile && file_exists($logFile)) {
    $log = file_get_contents($logFile);
    $overfitCount = substr_count($log, 'OVERFIT');
}

echo "Discoveries with CV: {$discoveries}\n";
echo "Laws without CV: {$withoutCV}\n";
echo "OVERFIT events: {$overfitCount}\n";

// §1.1: count(OVERFIT)=0 И count(DISCOVERY)>0
$pass = ($withoutCV === 0) && ($overfitCount === 0) && ($discoveries > 0);

if (! $pass) {
    $reasons = [];
    if ($withoutCV > 0) $reasons[] = "{$withoutCV} laws without CV";
    if ($overfitCount > 0) $reasons[] = "{$overfitCount} OVERFIT events";
    if ($discoveries === 0) $reasons[] = "no discoveries";
    echo "FAIL: " . implode(', ', $reasons) . "\n";
    exit(1);
}

echo "PASS: All discoveries have held-out CV, 0 OVERFIT\n";
exit(0);
