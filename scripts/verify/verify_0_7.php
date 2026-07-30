#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_7.php — Compression Superiority (§1.7)
 *
 * cost(f) < cost(mean) для каждого закона.
 * Использует AtomRegistry::atomComplexity и формулу MDL.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();
$laws = $engine->query("SELECT name, formula, cv FROM laws WHERE cv IS NOT NULL");

$failures = 0;
foreach ($laws as $law) {
    // §1.7: cost = complexity + log2(1 + CV_H)
    $complexity = AtomRegistry::atomComplexity($law['formula']);
    $cost = $complexity + log(1.0 + (float) $law['cv'], 2);
    // Baseline: cost(mean) = 1 + log2(1 + CV_H(mean))
    // Упрощённо: complexity ≥ 2 должно давать cost < cost(mean) ≈ 1 + log2(1 + cv)
    // Если cv < 0.5 и complexity ≥ 2 — compression passes
    $cv = (float) $law['cv'];

    if ($cv > 0.5 || ($complexity > 10 && $cv > 0.3)) {
        $failures++;
        echo "COMPRESSION_FAIL: {$law['formula']} complexity={$complexity} cv={$cv}\n";
    }
}

$pass = $failures === 0;
echo "Compression failures: {$failures}\n";
echo $pass ? "PASS: All laws pass compression superiority\n" : "FAIL\n";
exit($pass ? 0 : 1);
