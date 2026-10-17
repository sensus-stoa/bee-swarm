<?php

declare(strict_types=1);

/**
 * GENOME-001 CHECKPOINT B: замороженный compression benchmark.
 *
 * Реализация Benchmarks/genome001/CHECKPOINT_B_MANIFEST.md (freeze до прогона).
 * Один прогон = один артефакт: checkpoint_b_raw.json + CSV + SVG + stdout-сводка.
 * STOP: перезапуск только из-за инфраструктурной ошибки, не для улучшения чисел.
 *
 * Запуск: php scripts/genome001/benchmark.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/corpus_lib.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Infra\Database;

// ── Среда: :memory: БД + реальные атомы T₁/T₂ (окружение REAL-корпуса) ──
putenv('SWARM_DB_PATH=:memory:');
Database::reset();
ExpressionEvaluator::resetCaches();
$db = Database::get();
$stmt = $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')");
$stmt->execute(['BW5737967f9b4ecb628fd48d7d82502f40', '((x0+x1)/(x0−x1))']);
$stmt->execute(['BW59baeae13abe8cbdfae0c823ff207db2', '((x0−x2)+x0BW5737967f9b4ecb628fd48d7d82502f40x1)']);

$T0 = hrtime(true);
$rows = GenomeBenchmarkLib::evalRows();

// ── Генерация корпусов (манифест §1, сиды зафиксированы) ────────────────

function dedup(array $progs): array
{
    $seen = [];
    $out = [];
    foreach ($progs as $p) {
        $key = GenomeBenchmarkLib::alphaCanonicalKey($p['ast']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $p;
    }

    return $out;
}

$regularTrain = dedup(array_map(static function (int $i): array {
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(20261016 + 7919 * $i));

    return GenomeBenchmarkLib::regularProgram(4, $rng);
}, range(1, 16)));
$randomTrain = dedup(array_map(static function (int $i): array {
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(20261017 + 7919 * $i));

    return GenomeBenchmarkLib::randomProgram(14, $rng);
}, range(1, 16)));

$sizeKs = [2, 3, 4, 6, 8, 11, 15];
$regularSize = dedup(array_merge(...array_map(static function (int $k): array {
    $out = [];
    foreach ([0, 1] as $j) {
        $rng = new Random\Randomizer(new Random\Engine\Mt19937(20261015 + 31 * $k + $j));
        $out[] = GenomeBenchmarkLib::regularProgram($k, $rng);
    }

    return $out;
}, $sizeKs)));
$randomSizeTargets = [8, 14, 20, 30, 45, 65, 90];
$randomSize = dedup(array_merge(...array_map(static function (int $t): array {
    $out = [];
    foreach ([0, 1] as $j) {
        $rng = new Random\Randomizer(new Random\Engine\Mt19937(20261016 + 131 * $t + $j));
        $out[] = GenomeBenchmarkLib::randomProgram($t, $rng);
    }

    return $out;
}, $randomSizeTargets)));

$countN = [2, 4, 8, 16, 32, 64];
$regularCount = dedup(array_map(static function (int $i): array {
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(20261015 + 100003 * $i));

    return GenomeBenchmarkLib::regularProgram(4, $rng);
}, range(1, 64)));
$randomCount = dedup(array_map(static function (int $i): array {
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(20261016 + 100003 * $i));

    return GenomeBenchmarkLib::randomProgram(20, $rng);
}, range(1, 64)));

// REAL: сортировка по каноническому ключу, чётный индекс → train (манифест §2)
$realAll = GenomeBenchmarkLib::realCorpus();
$realProgs = [];
foreach ($realAll as $i => $spec) {
    $ast = WordCodec::parseDefinition($spec['formula'], ['BW5737967f9b4ecb628fd48d7d82502f40', 'BW59baeae13abe8cbdfae0c823ff207db2']);
    $p = GenomeBenchmarkLib::pack($ast);
    $p['source'] = $spec['source'];
    $p['family'] = $spec['family'];
    $p['numeric'] = $spec['numeric'];
    $realProgs[] = $p;
}
usort($realProgs, static fn (array $a, array $b): int => strcmp(
    GenomeBenchmarkLib::alphaCanonicalKey($a['ast']),
    GenomeBenchmarkLib::alphaCanonicalKey($b['ast'])
));
$realTrain = [];
$realTest = [];
foreach ($realProgs as $i => $p) {
    if ($i % 2 === 0) {
        $realTrain[] = $p;
    } else {
        $realTest[] = $p;
    }
}

// ── Фазы измерений ──────────────────────────────────────────────────────

$ramBefore = memory_get_peak_usage(true);
$raw = ['meta' => [
    'manifest' => 'CHECKPOINT_B_MANIFEST.md',
    'php' => PHP_VERSION,
    'codec_c' => 'gzencode level 9 (deflate; ext-zstd отсутствует)',
    'wordcodec' => 'CHECKPOINT A (src/Core/WordCodec.php)',
    't2_canon' => 'BW59baeae13abe8cbdfae0c823ff207db2 = ((x0−x2)+x0BW5737967f9b4ecb628fd48d7d82502f40x1), источник tests/MolRoundtripTest.php:30-34 (28013c9)',
    'started' => date('c'),
]];
$semanticErrors = 0;
$numericSkipped = 0;

/**
 * Семантическая проверка одной программы (манифест §5).
 */
function checkSemantics(array $prog, array $entries, array $rows, string $label): void
{
    global $semanticErrors, $numericSkipped;
    $genome = WordCodec::encode($prog['ast'], $entries);
    $decoded = WordCodec::decode($genome, $entries);
    if (! WordCodec::astEqual($decoded, $prog['ast'])) {
        $semanticErrors++;
        echo "  SEMANTIC-FAIL(decode) {$label}\n";
    }
    $expanded = WordCodec::decodeExpanded($genome, $entries);
    if (! WordCodec::astEqual($expanded, $prog['ast'])) {
        $semanticErrors++;
        echo "  SEMANTIC-FAIL(expanded) {$label}\n";
    }
    if (($prog['numeric'] ?? 'full') !== 'full') {
        $numericSkipped++;

        return;
    }
    $orig = GenomeBenchmarkLib::evalAst($prog['ast'], $rows);
    if (! GenomeBenchmarkLib::vectorsEqual($orig, GenomeBenchmarkLib::evalAst($decoded, $rows))) {
        $semanticErrors++;
        echo "  SEMANTIC-FAIL(numeric decoded) {$label}\n";
    }
    if (! GenomeBenchmarkLib::vectorsEqual($orig, GenomeBenchmarkLib::evalAst($expanded, $rows))) {
        $semanticErrors++;
        echo "  SEMANTIC-FAIL(numeric expanded) {$label}\n";
    }
}

/**
 * Один корпус: словарь из train, SIZE-ось, COUNT-ось, reuse.
 */
function runCorpus(string $name, array $train, array $sizeTest, array $countTest, array $sizeKs, array $countN): array
{
    global $rows, $ramBefore;
    $out = [];

    $t = hrtime(true);
    $dict = GenomeBenchmarkLib::extractDictionary($train);
    $out['dict'] = [
        'words' => count($dict),
        'bytes' => strlen(WordCodec::serializeBundle($dict)),
        'def_nodes' => array_map(static fn (array $e): int => WordCodec::nodesCount($e['genome']), $dict),
    ];
    $out['dict']['encode_wall_ms'] = (hrtime(true) - $t) / 1e6;

    // ── SIZE-ось: per-program bytes + семантика ──
    $tEnc = hrtime(true);
    $per = [];
    $genomes = [];
    foreach ($sizeTest as $i => $p) {
        $g = WordCodec::encode($p['ast'], $dict);
        $genomes[] = $g;
        $per[] = [
            'nodes' => $p['nodes'],
            'formula' => $p['formula'],
            'a_bytes' => GenomeBenchmarkLib::astBytes($p['ast']),
            'd_genome_bytes' => strlen(json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }
    $out['size']['encode_wall_ms'] = (hrtime(true) - $tEnc) / 1e6;

    // refs per genome (имена слов в {word}-узлах + born-op op-узлах)
    $refCounts = [];
    $collectRefs = static function (array $node) use (&$collectRefs, &$refCounts): void {
        if (isset($node['atom'])) {
            return;
        }
        if (isset($node['word'])) {
            $refCounts[(string) $node['word']] = ($refCounts[(string) $node['word']] ?? 0) + 1;
        }
        foreach (['l', 'r'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                $collectRefs($node[$k]);
            }
        }
        if (isset($node['args']) && is_array($node['args'])) {
            foreach ($node['args'] as $a) {
                if (is_array($a)) {
                    $collectRefs($a);
                }
            }
        }
    };
    foreach ($genomes as $g) {
        $collectRefs($g);
    }
    arsort($refCounts);
    $out['size']['word_reuse'] = $refCounts;
    $out['size']['word_reuse_summary'] = [
        'distinct_words_used' => count($refCounts),
        'total_refs' => array_sum($refCounts),
        'max_reuse' => $refCounts === [] ? 0 : max($refCounts),
        'dict_words_unused_in_test' => count($dict) - count($refCounts),
    ];

    $tDec = hrtime(true);
    foreach ($genomes as $g) {
        WordCodec::decode($g, $dict);
    }
    $out['size']['decode_wall_ms'] = (hrtime(true) - $tDec) / 1e6;

    $tExec = hrtime(true);
    foreach ($sizeTest as $p) {
        GenomeBenchmarkLib::evalAst($p['ast'], $rows);
    }
    $out['size']['exec_wall_original_ms'] = (hrtime(true) - $tExec) / 1e6;
    $tExec = hrtime(true);
    foreach ($genomes as $i => $g) {
        GenomeBenchmarkLib::evalAst(WordCodec::decode($g, $dict), $rows);
    }
    $out['size']['exec_wall_decoded_ms'] = (hrtime(true) - $tExec) / 1e6;
    $out['size']['ram_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);

    // totals по SIZE-тесту
    $aTotal = array_sum(array_column($per, 'a_bytes'));
    $dTotal = array_sum(array_column($per, 'd_genome_bytes'));
    $t = hrtime(true);
    $dag = GenomeBenchmarkLib::dagBytes($sizeTest);
    $out['size']['b_wall_ms'] = (hrtime(true) - $t) / 1e6;
    $t = hrtime(true);
    $cBytes = GenomeBenchmarkLib::gzipCorpusBytes($sizeTest);
    $out['size']['c_wall_ms'] = (hrtime(true) - $t) / 1e6;
    $out['size']['totals'] = [
        'n_programs' => count($sizeTest),
        'a_total' => $aTotal,
        'b_total' => $dag['bytes'],
        'b_table_nodes' => $dag['table_nodes'],
        'c_total' => $cBytes,
        'd_dict_bytes' => $out['dict']['bytes'],
        'd_genomes_total' => $dTotal,
        'd_total' => $out['dict']['bytes'] + $dTotal,
    ];
    $out['size']['per_program'] = $per;
    $out['size']['ratios'] = [
        'd_vs_a' => round(($out['size']['totals']['d_total']) / max(1, $aTotal), 3),
        'd_vs_b' => round(($out['size']['totals']['d_total']) / max(1, $dag['bytes']), 3),
        'd_vs_c' => round(($out['size']['totals']['d_total']) / max(1, $cBytes), 3),
        'd_genomes_vs_a' => round($dTotal / max(1, $aTotal), 3),
    ];

    // ── COUNT-ось: кумулятивные тоталы + break-even ──
    $prefixes = [];
    $breakEven = ['A' => null, 'B' => null, 'C' => null];
    foreach ($countN as $n) {
        $subset = array_slice($countTest, 0, $n);
        $a = array_sum(array_map(static fn (array $p): int => GenomeBenchmarkLib::astBytes($p['ast']), $subset));
        $dGen = 0;
        foreach ($subset as $p) {
            $dGen += strlen(json_encode(WordCodec::encode($p['ast'], $dict), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $d = $out['dict']['bytes'] + $dGen;
        $b = GenomeBenchmarkLib::dagBytes($subset)['bytes'];
        $c = GenomeBenchmarkLib::gzipCorpusBytes($subset);
        $prefixes[] = ['n' => $n, 'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'd_genomes' => $dGen];
        foreach (['A' => $a, 'B' => $b, 'C' => $c] as $X => $xt) {
            if ($breakEven[$X] === null && $d < $xt) {
                $breakEven[$X] = $n;
            }
        }
    }
    $out['count'] = ['prefixes' => $prefixes, 'break_even' => $breakEven];

    // маржинальная стоимость: эмпирическая производная на последнем удвоении
    $last = $prefixes[count($prefixes) - 1];
    $prev = $prefixes[count($prefixes) - 2];
    $out['count']['marginal_bytes_per_program'] = [
        'a' => round(($last['a'] - $prev['a']) / ($last['n'] - $prev['n']), 1),
        'd' => round(($last['d'] - $prev['d']) / ($last['n'] - $prev['n']), 1),
        'd_genomes_only' => round(($last['d_genomes'] - $prev['d_genomes']) / ($last['n'] - $prev['n']), 1),
    ];

    // ── Семантика: train + size-test + count-test (все программы) ──
    foreach ($train as $i => $p) {
        checkSemantics($p, $dict, $rows, "{$name}/train#{$i}");
    }
    foreach ($sizeTest as $i => $p) {
        checkSemantics($p, $dict, $rows, "{$name}/size#{$i}");
    }
    foreach ($countTest as $i => $p) {
        checkSemantics($p, $dict, $rows, "{$name}/count#{$i}");
    }

    return $out;
}

$ramPhase0 = memory_get_peak_usage(true);
echo "== REGULAR ==\n";
$raw['REGULAR'] = runCorpus('REGULAR', $regularTrain, $regularSize, $regularCount, $sizeKs, $countN);
echo "== RANDOM ==\n";
$raw['RANDOM'] = runCorpus('RANDOM', $randomTrain, $randomSize, $randomCount, $randomSizeTargets, $countN);
echo "== REAL ==\n";
// REAL: одна ось (корпус мал) — size-набор = весь test, count = N-префиксы по test
$raw['REAL'] = runCorpus('REAL', $realTrain, $realTest, $realTest, ['n/a'], $countN);
$raw['REAL']['train'] = array_map(static fn (array $p): array => ['formula' => $p['formula'], 'source' => $p['source'], 'family' => $p['family'], 'numeric' => $p['numeric']], $realTrain);
$raw['REAL']['test'] = array_map(static fn (array $p): array => ['formula' => $p['formula'], 'source' => $p['source'], 'family' => $p['family'], 'numeric' => $p['numeric']], $realTest);
$raw['semantic_errors'] = $semanticErrors;
$raw['numeric_skipped_structural_only'] = $numericSkipped;
$raw['wall_total_s'] = round((hrtime(true) - $T0) / 1e9, 2);
$raw['ram_peak_mb_total'] = round(memory_get_peak_usage(true) / 1048576, 1);

// ── Вердикты (манифест §6) ──────────────────────────────────────────────
$reg = $raw['REGULAR']['size']['totals'];
$ran = $raw['RANDOM']['size']['totals'];
$rea = $raw['REAL']['size']['totals'];
$regRatios = $raw['REGULAR']['size']['per_program'];
$verdicts = [
    'H1_lossless_heredity' => $semanticErrors === 0 ? 'PASS' : 'FAIL (semantic_errors=' . $semanticErrors . ')',
    'H2_structural_compression' => ($reg['d_total'] < $reg['a_total']) ? 'PASS (D<A на REGULAR)' : 'FAIL (D>=A на REGULAR)',
    'H2_note_vs_B' => ($reg['d_total'] < $reg['b_total']) ? 'D<B' : 'D>=B — преимущество хранения против DAG не подтверждено',
    'H2_note_vs_C' => ($reg['d_total'] < $reg['c_total']) ? 'D<C' : 'D>=C — преимущество хранения против gzip не подтверждено',
    'H3_complexity_scaling' => null, // считается ниже по данным SIZE-оси
    'H4_no_automatic_advantage' => null,
    'H5_cost' => 'см. walls/RAM в raw.json; trade-off документирован',
];
// H3: наклон отношения d_genome/a по узлам (первая vs последняя треть SIZE-оси REGULAR)
$byNodes = $regRatios;
usort($byNodes, static fn (array $x, array $y): int => $x['nodes'] <=> $y['nodes']);
$third = max(1, (int) floor(count($byNodes) / 3));
$ratio = static fn (array $p): float => $p['d_genome_bytes'] / max(1, $p['a_bytes']);
$firstThird = array_slice($byNodes, 0, $third);
$lastThird = array_slice($byNodes, -$third);
$avgFirst = array_sum(array_map($ratio, $firstThird)) / $third;
$avgLast = array_sum(array_map($ratio, $lastThird)) / $third;
$verdicts['H3_complexity_scaling'] = ($avgLast < $avgFirst)
    ? sprintf('PASS (отношение убывает: %.3f → %.3f на REGULAR)', $avgFirst, $avgLast)
    : sprintf('FAIL на REGULAR (%.3f → %.3f)', $avgFirst, $avgLast);
$verdicts['H4_no_automatic_advantage'] = sprintf(
    'RANDOM: D=%d B=%d C=%d A=%d (dict=%d); REAL: D=%d B=%d C=%d A=%d (dict=%d)',
    $ran['d_total'], $ran['b_total'], $ran['c_total'], $ran['a_total'], $ran['d_dict_bytes'],
    $rea['d_total'], $rea['b_total'], $rea['c_total'], $rea['a_total'], $rea['d_dict_bytes']
);
$raw['verdicts'] = $verdicts;

// ── Артефакты ───────────────────────────────────────────────────────────
$outDir = dirname(__DIR__, 2) . '/Benchmarks/genome001';
file_put_contents($outDir . '/checkpoint_b_raw.json', json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$csv = "corpus,nodes,a_bytes,d_genome_bytes\n";
foreach (['REGULAR', 'RANDOM', 'REAL'] as $cn) {
    foreach ($raw[$cn]['size']['per_program'] as $p) {
        $csv .= "{$cn},{$p['nodes']},{$p['a_bytes']},{$p['d_genome_bytes']}\n";
    }
}
file_put_contents($outDir . '/checkpoint_b_size.csv', $csv);

$csv = "corpus,n,a,b,c,d,d_genomes\n";
foreach (['REGULAR', 'RANDOM', 'REAL'] as $cn) {
    foreach ($raw[$cn]['count']['prefixes'] as $p) {
        $csv .= "{$cn},{$p['n']},{$p['a']},{$p['b']},{$p['c']},{$p['d']},{$p['d_genomes']}\n";
    }
}
file_put_contents($outDir . '/checkpoint_b_count.csv', $csv);

// ── stdout-сводка ──
printf("dict: REGULAR %d слов/%d B; RANDOM %d слов/%d B; REAL %d слов/%d B\n",
    $raw['REGULAR']['dict']['words'], $raw['REGULAR']['dict']['bytes'],
    $raw['RANDOM']['dict']['words'], $raw['RANDOM']['dict']['bytes'],
    $raw['REAL']['dict']['words'], $raw['REAL']['dict']['bytes']);
foreach (['REGULAR', 'RANDOM', 'REAL'] as $cn) {
    $s = $raw[$cn]['size']['totals'];
    printf("%s SIZE: A=%d B=%d C=%d D=%d (dict %d) | D/A=%.3f D/B=%.3f D/C=%.3f | break-even A=%s B=%s C=%s\n",
        $cn, $s['a_total'], $s['b_total'], $s['c_total'], $s['d_total'], $s['d_dict_bytes'],
        $raw[$cn]['size']['ratios']['d_vs_a'], $raw[$cn]['size']['ratios']['d_vs_b'], $raw[$cn]['size']['ratios']['d_vs_c'],
        $raw[$cn]['count']['break_even']['A'] ?? 'never', $raw[$cn]['count']['break_even']['B'] ?? 'never', $raw[$cn]['count']['break_even']['C'] ?? 'never');
}
printf("semantic_errors=%d numeric_skipped=%d wall=%.1fs ram_peak=%dMB\n",
    $semanticErrors, $numericSkipped, $raw['wall_total_s'], $raw['ram_peak_mb_total']);
foreach ($verdicts as $k => $v) {
    echo $k, ': ', (is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)), "\n";
}
echo "raw: Benchmarks/genome001/checkpoint_b_raw.json (+ size.csv, count.csv)\n";
