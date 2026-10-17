<?php

declare(strict_types=1);

/**
 * GENOME-002: замороженный сравнительный прогон (манифест MANIFEST.md).
 * Один прогон = один артефакт: checkpoint_raw.json + stdout-сводка.
 *
 * Запуск: php scripts/genome002/benchmark.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/genome001/corpus_lib.php';
require __DIR__ . '/evolve_lib.php';

use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Infra\Database;

putenv('SWARM_DB_PATH=:memory:');
Database::reset();
ExpressionEvaluator::resetCaches();

$ATTEMPTS = 500;
$T0 = hrtime(true);
$raw = ['meta' => [
    'manifest' => 'Benchmarks/genome002/MANIFEST.md',
    'php' => PHP_VERSION,
    'attempts_per_arm_per_replica' => $ATTEMPTS,
    'started' => date('c'),
]];

$targets = GenomeEvolveLib::targetLaws();
$replicas = [];
$ramBefore = memory_get_peak_usage(true);

foreach ($targets as $i => $t) {
    $targetAst = WordCodec::parseDefinition($t['formula'], []);
    $train = GenomeEvolveLib::trainFamily($targetAst, 20261020 + 300 + $i);
    $dict = GenomeBenchmarkLib::extractDictionary($train);
    $dictAlphaKeys = [];
    foreach ($dict as $e) {
        $dictAlphaKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::decodeDefinition($e, $dict))] = $e['name'];
    }
    GenomeEvolveLib::$dictAlphaKeys = $dictAlphaKeys;
    $pool = array_map(static fn (array $e): array => ['ast' => WordCodec::decodeDefinition($e, $dict), 'slots' => []], $dict);

    $task = GenomeEvolveLib::makeTask($t['formula'], 20261020 + 1000 + $i);
    $parent = null;
    $parentRmseAll = null;
    for ($s = 0; $s < 20; $s++) {
        $cand = GenomeEvolveLib::damageParent($task['ast'], 20261020 + 2000 + $i * 97 + $s);
        $r = GenomeEvolveLib::rmse($cand['ast'], array_merge($task['train'], $task['held']), array_merge($task['y_train'], $task['y_held']));
        if ($r !== null) {
            $parent = $cand;
            $parentRmseAll = $r;
            break;
        }
    }
    if ($parent === null) {
        throw new RuntimeException("replica {$i}: мёртвые родители за 20 пересевов");
    }
    $targetRmseHeld = GenomeEvolveLib::rmse($task['ast'], $task['held'], $task['y_held']);
    $parentRmseHeld = GenomeEvolveLib::rmse($parent['ast'], $task['held'], $task['y_held']);
    $parentGenome = WordCodec::encode($parent['ast'], $dict);

    $arms = [];
    foreach (['A', 'B', 'C'] as $arm) {
        $arms[$arm] = GenomeEvolveLib::runReplicate($arm, $task, $parent['ast'], $pool, $dict, $ATTEMPTS, 20261020 + 5000 + $i);
    }

    $replicas[$t['id']] = [
        'target' => $t,
        'damage' => $parent['damage'],
        'parent_rmse_held' => round($parentRmseHeld, 5),
        'target_rmse_held' => round($targetRmseHeld, 5),
        'dict_words' => count($dict),
        'dict_bytes' => strlen(WordCodec::serializeBundle($dict)),
        'parent_genome_bytes' => strlen(json_encode($parentGenome, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        'arms' => $arms,
    ];
    echo $t['id'] . ': dict=' . count($dict) . ' parent_held=' . round($parentRmseHeld, 3)
        . ' useful: A=' . $arms['A']['useful_new'] . ' B=' . $arms['B']['useful_new'] . ' C=' . $arms['C']['useful_new'] . "\n";
}

// ── Агрегаты и вердикт (манифест §1) ────────────────────────────────────
$tot = ['A' => 0, 'B' => 0, 'C' => 0];
$winsB = ['A' => 0, 'C' => 0];
$reuseB = 0;
$improvedAny = 0;
$evalCalls = ['A' => 0, 'B' => 0, 'C' => 0];
$wall = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
$refUseful = 0;
$refAttempts = 0;
foreach ($replicas as $r) {
    foreach (['A', 'B', 'C'] as $arm) {
        $a = $r['arms'][$arm];
        $tot[$arm] += $a['useful_new'];
        $evalCalls[$arm] += $a['eval_calls'];
        $wall[$arm] += $a['wall_ms'];
        $improvedAny += $a['useful_new'] + $a['buckets']['reuse_shape'] + $a['buckets']['improved_not_novel'];
        if ($arm === 'B') {
            $reuseB += $a['buckets']['reuse_shape'];
            $refUseful += $a['ref_useful'];
            $refAttempts += $a['ref_attempts'];
        }
    }
    $winsB['A'] += ($r['arms']['B']['useful_new'] > $r['arms']['A']['useful_new']) ? 1 : 0;
    $winsB['C'] += ($r['arms']['B']['useful_new'] > $r['arms']['C']['useful_new']) ? 1 : 0;
}
$ramPeak = memory_get_peak_usage(true);

$verdict = 'FAIL';
if ($tot['A'] + $tot['B'] + $tot['C'] === 0 && $improvedAny === 0) {
    $verdict = 'INCONCLUSIVE (все руки — ноль полезных потомков, req 10)';
} elseif ($tot['B'] > $tot['A'] && $tot['B'] > $tot['C'] && $winsB['A'] >= 5 && $winsB['C'] >= 5) {
    $verdict = 'PASS';
} elseif ($tot['B'] > 0 && $reuseB > 0 && $reuseB >= ($tot['B'] + $reuseB) / 2) {
    $verdict = 'FAIL (преимущество B = повторное использование готовых формул, req 8)';
} else {
    $verdict = 'FAIL (преимущество B не подтверждено: B=' . $tot['B'] . ' A=' . $tot['A'] . ' C=' . $tot['C']
        . '; победы реплик B>A: ' . $winsB['A'] . '/7, B>C: ' . $winsB['C'] . '/7)';
}

$raw['replicas'] = $replicas;
$raw['totals'] = [
    'useful_new' => $tot,
    'improved_any' => $improvedAny,
    'wins_B_vs_A' => $winsB['A'],
    'wins_B_vs_C' => $winsB['C'],
    'reuse_shape_B' => $reuseB,
    'ref_attempts_B' => $refAttempts,
    'ref_useful_B' => $refUseful,
    'eval_calls' => $evalCalls,
    'wall_ms' => $wall,
    'ram_peak_mb' => round($ramPeak / 1048576, 1),
    'wall_total_s' => round((hrtime(true) - $T0) / 1e9, 2),
];
$raw['verdict'] = $verdict;
$raw['cost_per_useful'] = [
    'A' => $tot['A'] > 0 ? round($evalCalls['A'] / $tot['A'], 0) : null,
    'B' => $tot['B'] > 0 ? round($evalCalls['B'] / $tot['B'], 0) : null,
    'C' => $tot['C'] > 0 ? round($evalCalls['C'] / $tot['C'], 0) : null,
];

$outDir = dirname(__DIR__, 2) . '/Benchmarks/genome002';
file_put_contents($outDir . '/checkpoint_raw.json', json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "\n=== GENOME-002 SUMMARY ===\n";
echo 'useful_new: A=' . $tot['A'] . ' B=' . $tot['B'] . ' C=' . $tot['C'] . " (of 7×{$ATTEMPTS} attempts each)\n";
echo 'wins: B>A ' . $winsB['A'] . '/7, B>C ' . $winsB['C'] . "/7\n";
echo 'B reuse_shape=' . $reuseB . ', ref_useful=' . $refUseful . '/' . $refAttempts . " ref-attempts\n";
echo 'eval_calls: A=' . $evalCalls['A'] . ' B=' . $evalCalls['B'] . ' C=' . $evalCalls['C'] . "\n";
echo 'cost eval/useful: ' . json_encode($raw['cost_per_useful']) . "\n";
echo 'VERDICT: ' . $verdict . "\n";
echo 'raw: Benchmarks/genome002/checkpoint_raw.json; wall=' . round((hrtime(true) - $T0) / 1e9, 1) . "s\n";
