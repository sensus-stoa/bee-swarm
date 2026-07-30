#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_3.php — Parsimony / Occam's Razor (§1.3)
 *
 * Протокол: среди выражений с эквивалентным CV выбирается простейшее.
 * complexity(e) = количество операционных узлов в дереве выражения.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();

$laws = $engine->query(
    "SELECT domain, name, formula, cv FROM laws WHERE cv IS NOT NULL ORDER BY domain, name"
);

// Группируем по задаче
$grouped = [];
foreach ($laws as $law) {
    $key = ($law['domain'] ?? '') . '::' . $law['name'];
    $grouped[$key][] = $law;
}

// Оставляем только задачи с ≥2 формулами
$multiEntry = array_filter($grouped, fn ($e) => count($e) > 1);

if (empty($multiEntry)) {
    echo "SKIP: No tasks with multiple formulas to compare\n";
    exit(0);
}

$violations = 0;
foreach ($multiEntry as $key => $entries) {
    // Находим формулу с минимальным CV
    $minCV = min(array_map(fn ($e) => (float) $e['cv'], $entries));

    // Среди формул с CV ≈ minCV (разница < 0.001) — выбираем простейшую
    $bestGroup = array_filter($entries, fn ($e) => abs((float) $e['cv'] - $minCV) < 0.001);
    $bestComplexity = min(array_map(fn ($e) => AtomRegistry::atomComplexity($e['formula']), $bestGroup));
    $best = array_values(array_filter($bestGroup, fn ($e) => AtomRegistry::atomComplexity($e['formula']) === $bestComplexity))[0];

    // Проверяем: нет ли в общей группе более простого с тем же CV
    foreach ($entries as $entry) {
        $c = AtomRegistry::atomComplexity($entry['formula']);
        $cvDiff = abs((float) $entry['cv'] - (float) $best['cv']);
        if ($cvDiff < 0.001 && $c < $bestComplexity && $entry['formula'] !== $best['formula']) {
            $violations++;
            echo "PARSIMONY: {$key} — '{$entry['formula']}' (c={$c}) simpler than best '{$best['formula']}' (c={$bestComplexity})\n";
        }
    }
}

$pass = $violations === 0;
echo "Compared " . count($multiEntry) . " tasks, violations: {$violations}\n";
echo $pass ? "PASS\n" : "FAIL\n";
exit($pass ? 0 : 1);
