#!/usr/bin/env php
<?php
declare(strict_types=1);

/** verify_0_8.php — Overlap Awareness (§1.8) */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();

$total = $engine->query("SELECT COUNT(*) as cnt FROM overlap_log")[0]['cnt'];
$pairs = $engine->query(
    "SELECT bee_a, bee_b, COUNT(*) as shared FROM overlap_log GROUP BY bee_a, bee_b HAVING shared >= 10"
);

echo "Total overlap records: {$total}\n";
echo "Measured pairs (≥10 shared): " . count($pairs) . "\n";

$pass = (int) $total > 0;
echo $pass ? "PASS: Overlap logging active\n" : "FAIL: No overlap records\n";
exit($pass ? 0 : 1);
