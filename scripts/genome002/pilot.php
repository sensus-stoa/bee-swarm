<?php

declare(strict_types=1);

/**
 * GENOME-002 DEV-пилот: работоспособность мутаций и измерителя полезности
 * ДО заморозки (рамка owner). Артефакт: Benchmarks/genome002/DEV_PILOT.json.
 *
 * P1 цели живы на данных; P2 alpha-ключ цели ∉ словам её словаря (req 8 hygiene);
 * P3 родители живы (пересев повреждения); P4 вёдра сходятся, B-refs есть,
 * исключений нет; P5 (наблюдение) улучшения видны.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/genome001/corpus_lib.php';
require __DIR__ . '/evolve_lib.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Infra\Database;

putenv('SWARM_DB_PATH=:memory:');
Database::reset();
ExpressionEvaluator::resetCaches();

$out = [
    'checks' => [],
    'ok' => false,
];
$fail = static function (string $check) use (&$out): void {
    $out['checks'][] = [
        'ok' => false,
        'check' => $check,
    ];
    echo 'Pilot check FAILED: ', $check, "\n";
};

$targets = GenomeEvolveLib::targetLaws();
$attempts = 30;
$replicas = [];
$anyImprove = false;
$anyRef = false;
$exceptions = 0;

foreach (array_slice($targets, 0, 3) as $ri => $t) {
    $targetAst = WordCodec::parseDefinition($t['formula'], []);
    // train-семейство реплики: под-конструкции цели × другие binding'и
    $train = GenomeEvolveLib::trainFamily($targetAst, 20261020 + 300 + $ri);
    $dict = GenomeBenchmarkLib::extractDictionary($train);
    $dictAlphaKeys = [];
    foreach ($dict as $e) {
        $dictAlphaKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::decodeDefinition($e, $dict))] = $e['name'];
    }
    GenomeEvolveLib::$dictAlphaKeys = $dictAlphaKeys;
    $pool = array_map(static fn (array $e): array => [
        'ast' => WordCodec::decodeDefinition($e, $dict),
        'slots' => [],
    ], $dict);
    $alpha = GenomeBenchmarkLib::alphaCanonicalKey($targetAst);
    if (isset($dictAlphaKeys[$alpha])) {
        $fail("P2: {$t['id']} коллидирует со словом {$dictAlphaKeys[$alpha]}");
    }

    try {
        $task = GenomeEvolveLib::makeTask($t['formula'], 20261020 + 1000 + $ri);
    } catch (Throwable $e) {
        $fail("P1: {$t['id']} не жив: {$e->getMessage()}");

        continue;
    }
    // P3: пересев повреждения, пока родитель мёртв
    $parent = null;
    $parentRmse = null;
    for ($s = 0; $s < 20; $s++) {
        $cand = GenomeEvolveLib::damageParent($task['ast'], 20261020 + 2000 + $ri * 97 + $s);
        $r = GenomeEvolveLib::rmse($cand['ast'], array_merge($task['train'], $task['held']), array_merge($task['y_train'], $task['y_held']));
        if ($r !== null) {
            $parent = $cand;
            $parentRmse = $r;
            break;
        }
    }
    if ($parent === null) {
        $fail("P3: {$t['id']} — мёртвые родители за 20 пересевов");

        continue;
    }
    $targetRmse = GenomeEvolveLib::rmse($task['ast'], array_merge($task['train'], $task['held']), array_merge($task['y_train'], $task['y_held']));
    $parentGenome = WordCodec::encode($parent['ast'], $dict);
    $nRefs = 0;
    $countRefs = static function (array $node) use (&$countRefs, &$nRefs): void {
        if (isset($node['atom'])) {
            return;
        }
        if (isset($node['word'])) {
            $nRefs++;
        }
        foreach (['l', 'r'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                $countRefs($node[$k]);
            }
        }
        if (isset($node['args']) && is_array($node['args'])) {
            foreach ($node['args'] as $a) {
                if (is_array($a)) {
                    $countRefs($a);
                }
            }
        }
    };
    $countRefs($parentGenome);
    if ($nRefs > 0) {
        $anyRef = true;
    }
    $replicas[$t['id']] = [
        'damage' => $parent['damage'],
        'parent_rmse' => round($parentRmse, 4),
        'target_rmse' => round($targetRmse, 4),
        'dict_words' => count($dict),
        'parent_genome_refs' => $nRefs,
        'arms' => [],
    ];
    echo $t['id'] . ': damage=' . $parent['damage']['kind'] . '(' . $parent['damage']['detail'] . ')'
        . ' parent_rmse=' . round($parentRmse, 4) . ' target_rmse=' . round($targetRmse, 4)
        . ' dict=' . count($dict) . ' refs=' . $nRefs . "\n";

    foreach (['A', 'B', 'C'] as $arm) {
        try {
            $res = GenomeEvolveLib::runReplicate($arm, $task, $parent['ast'], $pool, $dict, $attempts, 20261020 + 5000 + $ri);
            $replicas[$t['id']]['arms'][$arm] = $res;
            if ($res['useful_new'] > 0 || $res['buckets']['reuse_shape'] > 0) {
                $anyImprove = true;
            }
            $sum = array_sum($res['buckets']);
            if ($sum !== $attempts) {
                $fail("P4: {$t['id']}/{$arm} сумма вёдер {$sum} != {$attempts}");
            }
            echo "  {$arm}: useful_new={$res['useful_new']} buckets=" . json_encode($res['buckets']) . ' by_op=' . json_encode($res['useful_by_op']) . "\n";
        } catch (Throwable $e) {
            $exceptions++;
            $fail("P4: {$t['id']}/{$arm} исключение: {$e->getMessage()}");
        }
    }
}
$out['replicas'] = $replicas;
$out['any_improvement_seen'] = $anyImprove;
$out['any_b_refs'] = $anyRef;
$out['exceptions'] = $exceptions;
$out['ok'] = $exceptions === 0 && $anyRef && count($out['checks']) === 0;
echo 'pilot ok=', var_export($out['ok'], true), ' anyImprove=', var_export($anyImprove, true), "\n";
file_put_contents(dirname(__DIR__, 2) . '/Benchmarks/genome002/DEV_PILOT.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
exit($out['ok'] ? 0 : 1);
