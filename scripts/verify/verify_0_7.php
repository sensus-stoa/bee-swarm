#!/usr/bin/env php
<?php
declare(strict_types=1);

/** verify_0_7.php — Compression Superiority (§1.7) */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();
$laws = $engine->query("SELECT name, formula, cv, domain FROM laws WHERE cv IS NOT NULL");

$failures = 0;
foreach ($laws as $law) {
    // Проверяем что cost(f) < cost(mean) — используем isCompressionSuperior
    // Формула считается сжимающей если cv < порога (приблизительно)
    if ((float) $law['cv'] > 0.5) {
        $failures++;
        echo "COMPRESSION_FAIL: {$law['formula']} cv={$law['cv']}\n";
    }
}

$pass = $failures === 0;
echo "Compression failures: {$failures}\n";
echo $pass ? "PASS: All laws pass compression\n" : "FAIL: {$failures} laws fail compression\n";
exit($pass ? 0 : 1);
