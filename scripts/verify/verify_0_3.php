#!/usr/bin/env php
<?php
declare(strict_types=1);

/** verify_0_3.php — Parsimony (§1.3) */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();
$laws = $engine->query("SELECT name, formula FROM laws GROUP BY name HAVING COUNT(*) > 1");

$pass = empty($laws);
echo $pass ? "PASS: No duplicate formulas per task\n" : "FAIL: " . count($laws) . " tasks with multiple formulas\n";
exit($pass ? 0 : 1);
