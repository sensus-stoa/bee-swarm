<?php

declare(strict_types=1);

/**
 * GENOME-001 CHECKPOINT B: библиотека корпуса.
 *
 * Все параметры заморожены в Benchmarks/genome001/CHECKPOINT_B_MANIFEST.md
 * ДО измерений. Эта библиотека — точная реализация манифеста:
 *   - генераторы REGULAR/RANDOM (фиксированные сиды);
 *   - REAL — замороженный список с источниками (манифест §1);
 *   - алгоритм извлечения словаря (манифест §3);
 *   - представления A/B/C (манифест §4), D — через WordCodec.
 *
 * Search/applyN/эволюция не затрагиваются.
 */

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\WordCodec;

final class GenomeBenchmarkLib
{
    public const SEED_REGULAR = 20261015;

    public const SEED_REGULAR_TRAIN = 20261016;

    public const SEED_RANDOM = 20261016;

    public const SEED_RANDOM_TRAIN = 20261017;

    /** Мотивы REGULAR (манифест §1). */
    private const MOTIFS = [
        '((x0+x1)/(x0−x1))',
        '((x0−x1)×(x0+x1))',
        '((x0−x1)+(x0×x1))',
        '((x0−x2)+(x0×x1))',
        '((x0/(x0+x1))−(x1−x0))',
    ];

    /** Параметры извлечения словаря (манифест §3). */
    public const EXTRACT_MIN_NODES = 4;

    public const EXTRACT_MIN_PROGRAMS = 2;

    public const EXTRACT_CAP = 32;

    // ── Генераторы ─────────────────────────────────────────────────────

    /**
     * REGULAR-программа: лево-свёрнутая композиция k мотивов, случайные
     * инъективные binding'и слотов на колонки x0..x5.
     *
     * @return array{ast: array, nodes: int, formula: string}
     */
    public static function regularProgram(int $k, object $rng): array
    {
        $pool = ['x0', 'x1', 'x2', 'x3', 'x4', 'x5'];
        $acc = null;
        for ($i = 0; $i < $k; $i++) {
            // смещённое распределение мотивов: повторяемость — свойство корпуса
            $idx = (int) floor(pow(self::float01($rng), 1.7) * count(self::MOTIFS));
            $idx = min($idx, count(self::MOTIFS) - 1);
            $motif = WordCodec::parseDefinition(self::MOTIFS[$idx]);
            $slots = WordCodec::slotIndices($motif);
            // сидированная перестановка (shuffle() использует mt_rand мимо Randomizer)
            $cols = [0, 1, 2, 3, 4, 5];
            for ($s = 5; $s > 0; $s--) {
                $t = $rng->getInt(0, $s);
                [$cols[$s], $cols[$t]] = [$cols[$t], $cols[$s]];
            }
            $binding = [];
            foreach (array_values($slots) as $j => $slot) {
                $binding['x' . $slot] = ['atom' => $pool[$cols[$j]]];
            }
            $instance = self::substituteAlpha($motif, $binding);
            if ($acc === null) {
                $acc = $instance;

                continue;
            }
            $op = self::float01($rng) < 0.6 ? '+' : '×';
            $acc = ['op' => $op, 'l' => $acc, 'r' => $instance];
        }

        return self::pack($acc);
    }

    /**
     * RANDOM-программа: случайное дерево до целевого числа узлов.
     *
     * @return array{ast: array, nodes: int, formula: string}
     */
    public static function randomProgram(int $targetNodes, object $rng): array
    {
        $build = static function (int $n) use (&$build, $rng): array {
            if ($n <= 1) {
                $atom = self::float01($rng) < 0.85 ? 'x' . $rng->getInt(0, 5) : 'K1';

                return ['atom' => $atom];
            }
            $op = ['+', '−', '×', '/'][$rng->getInt(0, 3)];
            $l = $rng->getInt(1, $n - 1);
            return ['op' => $op, 'l' => $build($l), 'r' => $build($n - 1 - $l)];
        };
        $ast = $build(max(2, $targetNodes));

        return self::pack($ast);
    }

    /**
     * REAL-корпус: замороженный список манифеста §1 (formula, source, family,
     * numeric-режим).
     *
     * @return list<array{formula: string, source: string, family: string, numeric: string}>
     */
    public static function realCorpus(): array
    {
        return [
            ['formula' => '((x0+x1)/(x0−x1))', 'source' => 'Benchmarks/probe1/g1_r1.json G1F1', 'family' => 'sdr', 'numeric' => 'full'],
            ['formula' => '((x0BW5737967f9b4ecb628fd48d7d82502f40x1)+(x0−x2))', 'source' => 'Benchmarks/probe1/g2_B_r1.json G2A (канон post-MOL-001)', 'family' => 'molecular', 'numeric' => 'full'],
            ['formula' => '((x0×(x1×x1))+((x0×(x2×x2))+(x0×(x3×x3))))', 'source' => 'Benchmarks/EXP040_M1EXT2_REPORT.md kinetic dot', 'family' => 'kinetic', 'numeric' => 'full'],
            ['formula' => '((x0×x3)+((x1×x4)+(x2×x5)))', 'source' => 'Benchmarks/EXP040_M1EXT2_REPORT.md kinetic', 'family' => 'kinetic', 'numeric' => 'full'],
            ['formula' => '((x0+x1)+(x0−x2))', 'source' => 'Benchmarks/V011_ENSEMBLE_calibration_run5.md', 'family' => 'ensemble', 'numeric' => 'full'],
            ['formula' => '(((x0+x1)×x2)+x3)', 'source' => 'Benchmarks/experiments-log.md', 'family' => 'misc', 'numeric' => 'full'],
            ['formula' => '(((x0×x2)×x1)/x3)', 'source' => 'EvoFamily/Benchmarks/EXP039_molecule.md (до вложения)', 'family' => 'molecular-pre', 'numeric' => 'full'],
            ['formula' => '((x0×(x1/R+x1))/(x2×x2))', 'source' => 'Benchmarks/V011_ENSEMBLE_calibration_run5.md', 'family' => 'ensemble', 'numeric' => 'structural-only'],
            ['formula' => '((x0+K2)−((x0×R)+x0))', 'source' => 'Benchmarks/experiments-log.md', 'family' => 'misc', 'numeric' => 'structural-only'],
        ];
    }

    // ── Извлечение словаря (манифест §3) ───────────────────────────────

    /**
     * Универсальное извлечение словаря из train-программ.
     *
     * @param list<array{ast: array}> $trainPrograms
     *
     * @return list<array{name: string, arity: int, genome: array, def: array}>
     */
    public static function extractDictionary(array $trainPrograms): array
    {
        // 1. счёт поддеревьев: канонический alpha-ключ → [distinct_programs, occurrences, shape-программа]
        $stats = [];
        foreach ($trainPrograms as $pi => $prog) {
            $seenInProgram = [];
            self::walkShapes($prog['ast'], static function (array $shape) use (&$stats, &$seenInProgram, $pi): void {
                $key = self::alphaCanonicalKey($shape);
                if (! isset($seenInProgram[$key])) {
                    $seenInProgram[$key] = true;
                    $stats[$key]['programs'] = ($stats[$key]['programs'] ?? 0) + 1;
                }
                $stats[$key]['occurrences'] = ($stats[$key]['occurrences'] ?? 0) + 1;
                $stats[$key]['shape'] ??= $shape;
                $stats[$key]['nodes'] ??= WordCodec::nodesCount($shape);
            });
        }
        // 2. кандидаты по манифесту
        $candidates = [];
        foreach ($stats as $key => $s) {
            if ($s['nodes'] < self::EXTRACT_MIN_NODES || $s['programs'] < self::EXTRACT_MIN_PROGRAMS) {
                continue;
            }
            if (! self::atomsAllowed($s['shape'])) {
                continue;
            }
            $candidates[] = ['key' => $key, 'shape' => $s['shape'], 'nodes' => $s['nodes'], 'programs' => $s['programs']];
        }
        usort($candidates, static function (array $a, array $b): int {
            return [$b['nodes'], $b['programs'], $a['key']] <=> [$a['nodes'], $a['programs'], $b['key']];
        });
        // 3. cap + построение через WordCodec (вложенные ссылки против предыдущих статей)
        $entries = [];
        $names = [];
        foreach (array_slice($candidates, 0, self::EXTRACT_CAP) as $c) {
            $name = 'BW' . md5($c['key']);
            if (isset($names[$name])) {
                continue;
            }
            $names[$name] = true;
            $canonical = self::alphaCanonical($c['shape']);
            $entries[] = WordCodec::makeEntry($name, $canonical, $entries);
        }

        return $entries;
    }

    // ── Представления A / B / C (манифест §4) ──────────────────────────

    /** A: json-байты AST. */
    public static function astBytes(array $ast): int
    {
        return strlen(json_encode($ast, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * B: глобальный DAG (hash-cons по каноническим ключам узлов, коммутативные
     * операнды сортируются). Возвращает байты и число узлов таблицы.
     *
     * @param list<array{ast: array}> $programs все программы оси (whole-corpus baseline)
     *
     * @return array{bytes: int, table_nodes: int}
     */
    public static function dagBytes(array $programs): array
    {
        $ids = [];
        $table = [];
        $intern = static function (array $node) use (&$intern, &$ids, &$table): int {
            if (isset($node['atom'])) {
                $key = 'a:' . $node['atom'];
            } else {
                $l = isset($node['l']) && is_array($node['l']) ? $intern($node['l']) : -1;
                $r = isset($node['r']) && is_array($node['r']) ? $intern($node['r']) : -1;
                $op = (string) $node['op'];
                if (in_array($op, ['+', '×', 'max', 'min'], true) && $r >= 0) {
                    $pair = $l <= $r ? [$l, $r] : [$r, $l];
                    [$l, $r] = $pair;
                }
                $key = 'o:' . $op . ':' . $l . ':' . $r;
            }
            if (isset($ids[$key])) {
                return $ids[$key];
            }
            $id = count($table);
            if (isset($node['atom'])) {
                $table[] = ['a', (string) $node['atom']];
            } else {
                $table[] = ['o', (string) $node['op'], $l, $r];
            }
            $ids[$key] = $id;

            return $id;
        };
        $roots = [];
        foreach ($programs as $p) {
            $roots[] = $intern($p['ast']);
        }
        $bytes = strlen(json_encode(['t' => $table, 'r' => $roots], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['bytes' => $bytes, 'table_nodes' => count($table)];
    }

    /** C: gzip-9 конкатенации A-сериализаций (whole-corpus baseline). */
    public static function gzipCorpusBytes(array $programs): int
    {
        $parts = [];
        foreach ($programs as $p) {
            $parts[] = json_encode($p['ast'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return strlen((string) gzencode(implode("\n", $parts), 9));
    }

    // ── Численная семантика ────────────────────────────────────────────

    /**
     * 10 сидированных строк (4 колонки хватает мотивам/slots ≤4) + 2 строки
     * с нулевым знаменателем (x0==x1).
     *
     * @return list<list<float>>
     */
    public static function evalRows(): array
    {
        mt_srand(99001);
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [mt_rand(5, 50) / 10, mt_rand(5, 50) / 10, mt_rand(5, 50) / 10, mt_rand(5, 50) / 10];
        }
        $rows[] = [3.0, 3.0, 2.0, 1.0];
        $rows[] = [0.0, 0.0, 0.0, 0.0];

        return $rows;
    }

    /** Построчный evalNode: list<float|null>. */
    public static function evalAst(array $ast, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = \BeeSwarm\Core\ExpressionEvaluator::evalNode($ast, $row, [], []);
        }

        return $out;
    }

    /** Сравнение векторов: null-паттерн обязан совпадать, значения 1e-9 relative. */
    public static function vectorsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $va) {
            $vb = $b[$i];
            if (($va === null) !== ($vb === null)) {
                return false;
            }
            if ($va === null) {
                continue;
            }
            if (abs($va - $vb) > 1e-9 * max(1.0, abs((float) $va))) {
                return false;
            }
        }

        return true;
    }

    // ── Вспомогательное ────────────────────────────────────────────────

    /** PHP 8.2: у Randomizer нет float-методов — float из getInt (детерминировано). */
    private static function float01(object $rng): float
    {
        return $rng->getInt(0, 1000000) / 1000000.0;
    }

    /** @return array{ast: array, nodes: int, formula: string} */
    public static function pack(array $ast): array
    {
        return ['ast' => $ast, 'nodes' => WordCodec::nodesCount($ast), 'formula' => ExpressionNormalizer::render($ast)];
    }

    /**
     * Одновременная подстановка: атомы xN → binding (shape-инстанцирование).
     *
     * @param array<string, array> $binding
     */
    public static function substituteAlpha(array $node, array $binding): array
    {
        if (isset($node['atom'])) {
            $name = (string) $node['atom'];

            return isset($binding[$name]) ? self::cloneAst($binding[$name]) : ['atom' => $name];
        }

        return [
            'op' => (string) $node['op'],
            'l' => isset($node['l']) && is_array($node['l']) ? self::substituteAlpha($node['l'], $binding) : null,
            'r' => isset($node['r']) && is_array($node['r']) ? self::substituteAlpha($node['r'], $binding) : null,
        ];
    }

    public static function cloneAst(array $node): array
    {
        if (isset($node['atom'])) {
            return ['atom' => (string) $node['atom']];
        }

        return [
            'op' => (string) $node['op'],
            'l' => isset($node['l']) && is_array($node['l']) ? self::cloneAst($node['l']) : null,
            'r' => isset($node['r']) && is_array($node['r']) ? self::cloneAst($node['r']) : null,
        ];
    }

    /**
     * Alpha-канонизация: атомы xN → x0,x1,… по первому появлению (лево-право).
     *
     * @return array атом-нормализованная копия
     */
    public static function alphaCanonical(array $node): array
    {
        $map = [];
        $next = 0;
        $walk = static function (array $n) use (&$walk, &$map, &$next): array {
            if (isset($n['atom'])) {
                $a = (string) $n['atom'];
                if (preg_match('/^x(\d+)$/', $a) === 1) {
                    if (! isset($map[$a])) {
                        $map[$a] = 'x' . $next;
                        ++$next;
                    }

                    return ['atom' => $map[$a]];
                }

                return ['atom' => $a];
            }

            return [
                'op' => (string) $n['op'],
                'l' => isset($n['l']) && is_array($n['l']) ? $walk($n['l']) : null,
                'r' => isset($n['r']) && is_array($n['r']) ? $walk($n['r']) : null,
            ];
        };

        return $walk($node);
    }

    /**
     * Канонический ключ shape: alpha-канонизация + сортировка коммутативных
     * операндов; строка-рендер.
     */
    public static function alphaCanonicalKey(array $node): string
    {
        $canon = self::alphaCanonical($node);
        $key = static function (array $n) use (&$key): string {
            if (isset($n['atom'])) {
                return (string) $n['atom'];
            }
            $l = isset($n['l']) && is_array($n['l']) ? $key($n['l']) : '';
            $r = isset($n['r']) && is_array($n['r']) ? $key($n['r']) : '';
            $op = (string) $n['op'];
            if ($r === '' ) {
                return '(' . $op . ' ' . $l . ')';
            }
            if (in_array($op, ['+', '×', 'max', 'min'], true) && strcmp($l, $r) > 0) {
                [$l, $r] = [$r, $l];
            }

            return '(' . $l . $op . $r . ')';
        };

        return $key($canon);
    }

    /** Атомы shape — только xN/K1/K2 (манифест §3 п.2). */
    public static function atomsAllowed(array $node): bool
    {
        if (isset($node['atom'])) {
            return (bool) preg_match('/^(x\d+|K1|K2)$/', (string) $node['atom']);
        }
        foreach (['l', 'r'] as $k) {
            if (isset($node[$k]) && is_array($node[$k]) && ! self::atomsAllowed($node[$k])) {
                return false;
            }
        }

        return true;
    }

    private static function walkShapes(array $node, callable $visit): void
    {
        $visit($node);
        foreach (['l', 'r'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                self::walkShapes($node[$k], $visit);
            }
        }
    }
}
