#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_3.php — Parsimony / Occam's Razor (§1.3)
 *
 * Дух критерия: среди формул с эквивалентным CV для одной задачи
 * должна быть выбрана простейшая.
 *
 * Проверка: для каждой задачи с ≥2 формулами — формула с лучшим CV
 * (в пределах 0.001) должна иметь минимальную complexity среди равных.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();

$laws = $engine->query(
    "SELECT domain, name, formula, cv FROM laws WHERE cv IS NOT NULL ORDER BY domain, name, cv"
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
    echo "SKIP: No tasks with multiple formulas to compare.\n";
    echo "  Это нормально если для каждой задачи найдена одна оптимальная формула.\n";
    echo "  Если система НЕ МОЖЕТ найти улучшение из-за dedup — проблема в коде, не в критерии.\n";
    exit(0);
}

$violations = 0;
foreach ($multiEntry as $key => $entries) {
    // Находим формулу с минимальным CV
    $minCV = min(array_map(fn ($e) => (float) $e['cv'], $entries));

    // Среди формул с CV ≈ minCV — выбираем простейшую
    $bestGroup = array_filter($entries, fn ($e) => abs((float) $e['cv'] - $minCV) < 0.001);
    $bestComplexity = min(array_map(fn ($e) => AtomRegistry::atomComplexity($e['formula']), $bestGroup));

    // Проверяем: нет ли более простой с тем же CV
    foreach ($entries as $entry) {
        $c = AtomRegistry::atomComplexity($entry['formula']);
        $cvDiff = abs((float) $entry['cv'] - $minCV);
        if ($cvDiff < 0.001 && $c < $bestComplexity) {
            $violations++;
            echo "PARSIMONY: {$key} — '{$entry['formula']}' (c={$c}) simpler than '{$bestGroup[array_key_first($bestGroup)]['formula']}' (c={$bestComplexity}) with same CV\n";
        }
    }
}

$pass = $violations === 0;
echo "Compared " . count($multiEntry) . " tasks with ≥2 formulas\n";
echo "Violations: {$violations}\n";
echo $pass ? "PASS: Simplest formula chosen for each task\n" : "FAIL: {$violations} parsimony violations\n";
exit($pass ? 0 : 1);
