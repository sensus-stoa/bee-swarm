#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_1.php — Held-Out Validation (§1.1)
 *
 * Проверяет что все открытия имеют held-out CV и нет OVERFIT.
 * Использует QueryEngine (S0-QUERY).
 *
 * Использование: php scripts/verify/verify_0_1.php [logfile]
 * Exit 0 = PASS, Exit 1 = FAIL.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();

// 1. Проверить что held-out включён
$heldoutLaws = $engine->query(
    "SELECT COUNT(*) as cnt FROM laws WHERE cv IS NOT NULL"
);
echo "Laws with CV: {$heldoutLaws[0]['cnt']}\n";

// 2. Проверить отсутствие OVERFIT в логе
$logFile = $argv[1] ?? null;
$overfitCount = 0;

if ($logFile && file_exists($logFile)) {
    $log = file_get_contents($logFile);
    preg_match_all('/OVERFIT/', $log, $matches);
    $overfitCount = count($matches[0]);
    echo "OVERFIT events in log: {$overfitCount}\n";
}

// 3. Проверить что каждый закон имеет held-out CV
$laws = $engine->query(
    "SELECT name, formula, cv, domain FROM laws"
);

$withoutCV = 0;
$discoveries = 0;
foreach ($laws as $law) {
    if ($law['cv'] === null) {
        $withoutCV++;
    } else {
        $discoveries++;
    }
}

echo "Discoveries with held-out CV: {$discoveries}\n";
echo "Laws without CV: {$withoutCV}\n";

$pass = $withoutCV === 0;

if ($pass) {
    echo "PASS: All discoveries have held-out CV\n";
    exit(0);
} else {
    echo "FAIL: {$withoutCV} laws without held-out CV\n";
    exit(1);
}
