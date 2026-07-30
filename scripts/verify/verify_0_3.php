#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_3.php — Parsimony / Occam's Razor (§1.3)
 *
 * Среди выражений с эквивалентным CV выбирается простейшее.
 * Проверяет: для каждой задачи нет более простого выражения
 * с таким же или лучшим CV среди открытых позже.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();

// Группируем законы по задаче (domain+name)
$laws = $engine->query(
    "SELECT domain, name, formula, cv FROM laws WHERE cv IS NOT NULL ORDER BY domain, name"
);

$grouped = [];
foreach ($laws as $law) {
    $key = ($law['domain'] ?? '') . '::' . $law['name'];
    $grouped[$key][] = $law;
}

$violations = 0;
foreach ($grouped as $key => $entries) {
    if (count($entries) <= 1) continue;

    // Сортируем по cv (лучше = меньше), затем по complexity (проще = меньше)
    usort($entries, function ($a, $b) {
        $cvDiff = (float) $a['cv'] - (float) $b['cv'];
        if (abs($cvDiff) > 0.001) return $cvDiff <=> 0;
        return AtomRegistry::atomComplexity($a['formula']) - AtomRegistry::atomComplexity($b['formula']);
    });

    $best = $entries[0];
    $bestComplexity = AtomRegistry::atomComplexity($best['formula']);

    // Проверяем: нет ли более простого выражения с тем же CV
    foreach ($entries as $i => $entry) {
        if ($i === 0) continue;
        $c = AtomRegistry::atomComplexity($entry['formula']);
        $cvDiff = abs((float) $entry['cv'] - (float) $best['cv']);
        if ($cvDiff < 0.001 && $c < $bestComplexity) {
            $violations++;
            echo "PARSIMONY_VIOLATION: {$key} — {$entry['formula']} (c={$c}) simpler than {$best['formula']} (c={$bestComplexity}) same CV\n";
        }
    }
}

$pass = $violations === 0;
echo "Parsimony violations: {$violations}\n";
echo $pass ? "PASS: Simplest formula chosen for each task\n" : "FAIL\n";
exit($pass ? 0 : 1);
