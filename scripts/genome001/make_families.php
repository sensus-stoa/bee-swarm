<?php

declare(strict_types=1);

/**
 * GENOME-001: генерация артефакта словаря+геномов из РЕАЛЬНЫХ T₁/T₂ (PROBE-1,
 * канон post-MOL-001). Freeze-принцип: формулы зафиксированы ДО измерений —
 * Checkpoint B сравнивает представления на этом бандле.
 *
 * Запись: Benchmarks/genome001/genome001_bundle.json
 * Запуск: php scripts/genome001/make_families.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use BeeSwarm\Core\WordCodec;

const T1_NAME = 'BW5737967f9b4ecb628fd48d7d82502f40';
const T1_DEF = '((x0+x1)/(x0−x1))';
const T2_NAME = 'BW59baeae13abe8cbdfae0c823ff207db2';
const T2_DEF = '((x0−x2)+x0BW5737967f9b4ecb628fd48d7d82502f40x1)';
const LAW_X = '((x0BW5737967f9b4ecb628fd48d7d82502f40x1)+(x0−x2))';
const T1_PLAIN = '((x0+x1)/(x0−x1))'; // та же функция БЕЗ born-op application

$birth = [T1_NAME, T2_NAME];
$entries = WordCodec::buildDictionary([
    T1_NAME => T1_DEF,
    T2_NAME => T2_DEF,
], $birth);

$genomes = [
    'law_x' => WordCodec::encode(WordCodec::parseDefinition(LAW_X, $birth), $entries),
    't1_plain' => WordCodec::encode(WordCodec::parseDefinition(T1_PLAIN), $entries),
    't2_definition' => WordCodec::encode(WordCodec::parseDefinition(T2_DEF, $birth), $entries),
];

mt_srand(4242);
$rows = [];
for ($i = 0; $i < 12; $i++) {
    $rows[] = [mt_rand(10, 30) / 10, mt_rand(40, 60) / 10, mt_rand(30, 70) / 10];
}

$bundle = WordCodec::serializeBundle($entries, $genomes);
$outDir = dirname(__DIR__, 2) . '/Benchmarks/genome001';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/genome001_bundle.json', $bundle);

// ── сводка размеров (черновые, полные метрики — Checkpoint B) ──
$line = static function (string $label, array $ast, array $genome) use ($entries): void {
    printf(
        "%-14s ast_nodes=%3d genome_nodes=%3d  ast_json=%5d B genome_json=%4d B  dict_bytes=%5d B\n",
        $label,
        WordCodec::nodesCount($ast),
        WordCodec::nodesCount($genome),
        strlen(json_encode($ast, JSON_UNESCAPED_UNICODE)),
        strlen(json_encode($genome, JSON_UNESCAPED_UNICODE)),
        strlen(WordCodec::serializeBundle($entries))
    );
};
echo "GENOME-001 bundle artifact (dictionary from REAL T1/T2, definitions frozen)\n";
foreach ($genomes as $name => $g) {
    $src = match ($name) {
        'law_x' => WordCodec::parseDefinition(LAW_X, $birth),
        't1_plain' => WordCodec::parseDefinition(T1_PLAIN),
        't2_definition' => WordCodec::parseDefinition(T2_DEF, $birth),
    };
    $line($name, $src, $g);
}
echo 'written: Benchmarks/genome001/genome001_bundle.json (' . strlen($bundle) . ' bytes, rows=' . count($rows) . " в тестовом envelope, не в бандле)\n";
