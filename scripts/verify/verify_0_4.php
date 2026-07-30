#!/usr/bin/env php
<?php
declare(strict_types=1);

/** verify_0_4.php — Non-Triviality (§1.4) */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;
use BeeSwarm\Core\AtomRegistry;

$engine = new QueryEngine();
$laws = $engine->query("SELECT formula FROM laws");

$trivial = 0;
// Identity: x0, x1, ..., K1, K2, ...
foreach ($laws as $law) {
    $f = $law['formula'];
    if (preg_match('/^x\d+$/', $f) || preg_match('/^K\d+$/', $f)) {
        $trivial++;
        echo "TRIVIAL: {$f}\n";
    }
}

$pass = $trivial === 0;
echo $pass ? "PASS: No trivial laws\n" : "FAIL: {$trivial} trivial laws\n";
exit($pass ? 0 : 1);
