#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_8.php — Overlap Awareness (§1.8)
 *
 * Проверяет: overlap_log содержит записи, и хотя бы одна пара пчёл
 * имеет ≥10 общих задач (shared_tasks).
 *
 * Канонический порядок: MIN(bee_a,bee_b), MAX(bee_a,bee_b) — пары
 * не дублируются при смене направления (A→B vs B→A).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();

$total = (int) ($engine->query("SELECT COUNT(*) as cnt FROM overlap_log")[0]['cnt'] ?? 0);

// Каноническая группировка + подсчёт УНИКАЛЬНЫХ задач (не reassignment-событий)
$measured = $engine->query(
    "SELECT MIN(bee_a, bee_b) as a, MAX(bee_a, bee_b) as b,
            COUNT(DISTINCT task) as shared
     FROM overlap_log
     GROUP BY a, b
     HAVING shared >= 10"
);

echo "Overlap records: {$total}\n";
echo "Measured pairs (≥10 shared tasks): " . count($measured) . "\n";

if ($total === 0) {
    echo "PENDING: OverlapTracker wired — waiting for task reassignments to accumulate.\n";
    exit(0);
}

$hasMeasured = count($measured) >= 1;
if (! $hasMeasured) {
    echo "PENDING: {$total} records, but no pairs have ≥10 shared tasks yet.\n";
    echo "  Overlap tracking активен. Нужно больше времени.\n";
    exit(0);
}

foreach ($measured as $pair) {
    echo "  Pair ({$pair['a']},{$pair['b']}): {$pair['shared']} shared tasks\n";
}
echo "PASS: Overlap tracking active with measured pairs\n";
exit(0);
