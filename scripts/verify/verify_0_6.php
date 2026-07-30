#!/usr/bin/env php
<?php
declare(strict_types=1);

/** verify_0_6.php — Deduplication (§1.6) */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();
$dupes = $engine->query(
    "SELECT domain, name, formula, COUNT(*) as cnt FROM laws GROUP BY domain, name, formula HAVING cnt > 1"
);

$pass = empty($dupes);
if ($pass) {
    echo "PASS: No duplicate laws\n";
} else {
    echo "FAIL: " . count($dupes) . " duplicate entries:\n";
    foreach ($dupes as $d) {
        echo "  {$d['domain']}::{$d['name']}::{$d['formula']} x{$d['cnt']}\n";
    }
}
exit($pass ? 0 : 1);
