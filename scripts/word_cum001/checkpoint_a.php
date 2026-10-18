<?php

declare(strict_types=1);

/**
 * WORD-CUM-001 CHECKPOINT A: машинный артефакт сценария
 * (дублирует tests/WordCum001Test.php; тест — инвариант, это — снимок данных).
 *
 * Запуск: php scripts/word_cum001/checkpoint_a.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/genome001/corpus_lib.php';
require dirname(__DIR__) . '/genome002/evolve_lib.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\WordCodecException;
use BeeSwarm\Infra\Database;

putenv('SWARM_DB_PATH=:memory:');
Database::reset();
ExpressionEvaluator::resetCaches();

const W1_NAME = 'BW5737967f9b4ecb628fd48d7d82502f40';
const W1_DEF = '((x0+x1)/(x0−x1))';

$dict = WordCodec::buildDictionary([
    W1_NAME => W1_DEF,
], [W1_NAME]);
$parentAst = WordCodec::parseDefinition('(((x0+x1)/(x0−x1))+(x2×x3))');
$parent = WordCodec::encode($parentAst, $dict);

$swap = static function (array $g, array $path, string $atom): array {
    $ref = &$g;
    foreach ($path as $step) {
        $ref = &$ref[$step];
    }
    $ref['atom'] = $atom;

    return $g;
};

$off1 = $swap($parent, ['r', 'l'], 'x5');
$off2 = $swap($off1, ['r', 'r'], 'x1');
$argMut = $swap($parent, ['l', 'args', 0], 'x4');

// C4: re-encode эмпирика
$ast = WordCodec::decode($parent, $dict);
$reEncOutside = WordCodec::encode($swap($ast, ['r', 'l'], 'x5'), $dict);
$reEncInside = WordCodec::encode($swap($ast, ['l', 'l', 'l'], 'x4'), $dict);

$rows = GenomeBenchmarkLib::evalRows();
$eval = static function (array $g) use ($dict, $rows): array {
    return GenomeBenchmarkLib::evalAst(WordCodec::decode($g, $dict), $rows);
};

$out = [
    'scenario' => 'WORD-CUM-001 CHECKPOINT A',
    'dictionary' => [[
        'name' => W1_NAME,
        'definition' => W1_DEF,
    ]],
    'parent' => [
        'genome' => $parent,
        'w1_ref' => $parent['l'],
    ],
    'chain' => [
        'mut1_outside' => [
            'path' => ['r', 'l'],
            'change' => 'x2→x5',
            'genome' => $off1,
            'ref_preserved' => json_encode($off1['l']) === json_encode($parent['l']),
        ],
        'mut2_on_child' => [
            'path' => ['r', 'r'],
            'change' => 'x3→x1',
            'genome' => $off2,
            'both_inherited' => $off2['r']['l']['atom'] === 'x5' && $off2['r']['r']['atom'] === 'x1' && json_encode($off2['l']) === json_encode($parent['l']),
        ],
    ],
    'clean_process' => (static function () use ($dict, $off2, $rows): array {
        $envelope = [
            'db' => tempnam(sys_get_temp_dir(), 'wordcum_art_') . '.sqlite',
            'genome' => 'child',
            'rows' => $rows,
            'bundle' => json_decode(WordCodec::serializeBundle($dict, [
                'child' => $off2,
            ]), true),
        ];
        $envFile = tempnam(sys_get_temp_dir(), 'wordcum_art_env_') . '.json';
        file_put_contents($envFile, json_encode($envelope, JSON_UNESCAPED_UNICODE));
        $out = shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/genome001/decode_execute.php') . ' ' . escapeshellarg($envFile) . ' 2>&1');
        @unlink($envFile);
        @unlink((string) $envelope['db']);
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
        $res = json_decode((string) end($lines), true);
        $local = GenomeBenchmarkLib::evalAst(WordCodec::decode($off2, $dict), $rows);
        $same = false;
        if (is_array($res) && ($res['ok'] ?? false) && count($res['values']) === count($local)) {
            $same = true;
            foreach ($local as $i => $v) {
                $w = $res['values'][$i];
                if (($v === null) !== ($w === null) || ($v !== null && abs($v - $w) > 1e-9 * max(1.0, abs((float) $v)))) {
                    $same = false;
                    break;
                }
            }
        }

        return [
            'ok' => $res['ok'] ?? false,
            'matches_local' => $same,
            'values' => $res['values'] ?? null,
        ];
    })(),
    'reencode_empirics' => [
        'mutation_outside_word' => [
            'ref_restored' => isset($reEncOutside['l']['word']),
            'args_same' => json_encode($reEncOutside['l'] ?? []) === json_encode($parent['l']),
        ],
        'mutation_inside_word' => [
            'ref_lost' => ! isset($reEncInside['l']['word']),
            'content_preserved_raw' => ($reEncInside['l']['l']['l']['atom'] ?? null) === 'x4',
        ],
    ],
    'arg_mutation' => [
        'genome' => $argMut,
        'ref_name_preserved' => ($argMut['l']['word'] ?? null) === W1_NAME,
        'new_arg' => $argMut['l']['args'][0]['atom'] ?? null,
        'values' => $eval($argMut),
    ],
    'refusals' => (static function () use ($dict, $parent): array {
        $r = [];
        try {
            WordCodec::decode([
                'op' => '+',
                'l' => [
                    'word' => W1_NAME,
                    'args' => [[
                        'atom' => 'x0',
                    ]],
                ],
                'r' => [
                    'atom' => 'x1',
                ],
            ], $dict);
            $r['arity'] = 'NO-THROW';
        } catch (WordCodecException $e) {
            $r['arity'] = $e->getMessage();
        }
        try {
            WordCodec::decode([
                'op' => '+',
                'l' => [
                    'word' => 'BWdeadbeefdeadbeefdeadbeefdeadbeef',
                    'args' => [[
                        'atom' => 'x0',
                    ], [
                        'atom' => 'x1',
                    ]],
                ],
                'r' => [
                    'atom' => 'x1',
                ],
            ], $dict);
            $r['missing'] = 'NO-THROW';
        } catch (WordCodecException $e) {
            $r['missing'] = $e->getMessage();
        }
        $broken = $parent;
        array_pop($broken['l']['args']);
        try {
            WordCodec::decode($broken, $dict);
            $r['mutated_broken_ref'] = 'NO-THROW';
        } catch (WordCodecException $e) {
            $r['mutated_broken_ref'] = $e->getMessage();
        }

        return $r;
    })(),
    'values_parent' => $eval($parent),
    'values_off2' => $eval($off2),
];

$outDir = dirname(__DIR__, 2) . '/Benchmarks/word_cum001';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/CHECKPOINT_A.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo 'ref_preserved(mut1)=', var_export($out['chain']['mut1_outside']['ref_preserved'], true);
echo ' both_inherited=', var_export($out['chain']['mut2_on_child']['both_inherited'], true);
echo ' clean_process_ok=', var_export($out['clean_process']['ok'] && $out['clean_process']['matches_local'], true);
echo ' reencode: outside_restored=', var_export($out['reencode_empirics']['mutation_outside_word']['ref_restored'], true);
echo ' inside_lost=', var_export($out['reencode_empirics']['mutation_inside_word']['ref_lost'], true), "\n";
echo "written: Benchmarks/word_cum001/CHECKPOINT_A.json\n";
