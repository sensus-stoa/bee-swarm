<?php

declare(strict_types=1);

/**
 * GENOME-002 EVOLVABILITY PROBE: библиотека мутаций и измерителя полезности.
 *
 * Гипотеза (owner): наследуемый исполняемый словарь помогает получать
 * функционально полезных потомков программ при равных ресурсах.
 *
 * Дизайн (freeze — Benchmarks/genome002/MANIFEST.md):
 *   - родитель = test-закон с ОДНИМ структурным повреждением (op-swap /
 *     atom-swap / subtree-drop), сайт и тип — сидированы, ОДНИ И ТЕ ЖЕ для
 *     трёх рук (логический узел дерева);
 *   - словарь — GENOME-001 extraction по TRAIN-корпусу (без test-законов),
 *     заморожен; pool рук A/C — ТЕ ЖЕ shape'ы без упаковки (req 2);
 *   - 4 класса мутаций, одинаковые веса у всех рук, выбор uniform:
 *     op-swap / atom-swap / splice (из pool; у B — word-ref на ref-позициях) /
 *     drop; НИКАКОЙ селекции и поколений — одна волна мутаций от родителей;
 *   - useful = корректен (все строки конечны) ∧ rmse_heldout < rmse_heldout(parent)
 *     ∧ novelty (ключ не встречался среди потомков этой руки и родителей);
 *   - вёдра reuse (req 8): offspring = инстанс словарной shape'ы
 *     (alpha-ключ совпал со словом) ИЛИ функционально = родителю;
 *   - granularity = представление (у B — word-ref как единая единица мутации),
 *     вся случайность uniform — req 5 (никаких интеллектуальных операторов).
 *
 * Search/applyN/эволюционные механизмы движка не затрагиваются.
 */

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;

final class GenomeEvolveLib
{
    public const MASTER_SEED = 20261020;

    /** Веса классов мутаций (одинаковы для всех рук, манифест §4). */
    public const OP_WEIGHTS = ['op_swap' => 0.30, 'atom_swap' => 0.30, 'splice' => 0.25, 'drop' => 0.15];

    private const BASE_OPS = ['+', '−', '×', '/'];

    // ── Задачи: test-законы + данные (манифест §2) ─────────────────────

    /**
     * Test-законы (БЕЗ них строится словарь). Источники — REAL-манифест
     * GENOME-001 + REGULAR-оси. numeric=structural-only у R-форм нет —
     * здесь только исполнимая базовая грамматика.
     *
     * @return list<array{id: string, formula: string, source: string}>
     */
    public static function targetLaws(): array
    {
        return [
            ['id' => 'T-ens', 'formula' => '((x0+x1)+(x0−x2))', 'source' => 'V011_ENSEMBLE_calibration_run5.md'],
            ['id' => 'T-kin', 'formula' => '((x0×x3)+((x1×x4)+(x2×x5)))', 'source' => 'EXP040_M1EXT2_REPORT.md (binding 4 колонки: x5→x3)'],
            ['id' => 'T-misc1', 'formula' => '(((x0+x1)×x2)+x3)', 'source' => 'Benchmarks/experiments-log.md'],
            ['id' => 'T-mol', 'formula' => '(((x0×x2)×x1)/x3)', 'source' => 'EXP039_molecule.md (до вложения)'],
            // T-t1 ((x0+x1)/(x0−x1)) ИСКЛЮЧЕНА из целей: её alpha-ключ = M1-мотиву
            // train-корпуса → коллидирует со словом словаря (гигиена req 8,
            // манифест §2; выбывание зафиксировано ДО прогона)
            ['id' => 'T-t2l', 'formula' => '((x0−x2)+((x0+x1)/(x0−x1)))', 'source' => 'g2-закон в развёрнутой форме (post-MOL-001)'],
            ['id' => 'T-kin2', 'formula' => '((x0×(x1×x1))+((x0×(x2×x2))+(x0×(x3×x3))))', 'source' => 'EXP040 kinetic dot (4 колонки)'],
            ['id' => 'T-ens2', 'formula' => '(((x0−x1)×(x0+x1))+x2)', 'source' => 'REGULAR-мотив M2+смещение (форма корпуса, не test REAL)'],
        ];
    }

    /** Данные задачи: 12 train + 8 heldout строк, 6 колонок, y = закон+шум. */
    public static function makeTask(string $formula, int $seed): array
    {
        $ast = WordCodec::parseDefinition($formula, ['BW5737967f9b4ecb628fd48d7d82502f40', 'BW59baeae13abe8cbdfae0c823ff207db2']);
        mt_srand($seed);
        // строки с недопустимой операцией цели (x0==x1 в знаменателе) —
        // перегенерируются детерминированно (сид фиксирован, лимит 50)
        $genRow = static function () use ($ast): array {
            for ($t = 0; $t < 50; $t++) {
                $row = [mt_rand(5, 50) / 10, mt_rand(5, 50) / 10, mt_rand(5, 50) / 10, mt_rand(5, 50) / 10, mt_rand(5, 50) / 10, mt_rand(5, 50) / 10];
                $v = ExpressionEvaluator::evalNode($ast, $row, [], []);
                if ($v !== null && is_finite($v)) {
                    return [$row, $v];
                }
            }
            throw new RuntimeException('makeTask: цель не жива за 50 попыток');
        };
        $build = static function (int $n) use ($genRow): array {
            $rows = [];
            $y = [];
            for ($i = 0; $i < $n; $i++) {
                [$row, $v] = $genRow();
                $y[] = $v + (mt_rand(-100, 100) / 100.0) * 0.02 * max(1.0, abs($v));
                $rows[] = $row;
            }

            return [$rows, $y];
        };
        [$train, $yTrain] = $build(12);
        [$held, $yHeld] = $build(8);

        return ['ast' => $ast, 'train' => $train, 'held' => $held, 'y_train' => $yTrain, 'y_held' => $yHeld, 'formula' => $formula];
    }

    // ── Родители: одно повреждение, ОДИН логический сайт для трёх рук ──

    /**
     * Повреждённый родитель: тип и сайт из seed. Возвращает дерево родителя
     * и ОПИСАНИЕ повреждения (для одинакового применения во всех руках).
     *
     * @return array{ast: array, damage: array{kind: string, path: list<int>, detail: string}}
     */
    public static function damageParent(array $targetAst, int $seed): array
    {
        $rng = new Random\Randomizer(new Random\Engine\Mt19937($seed));
        $kinds = ['op_swap', 'atom_swap', 'drop'];
        $kind = $kinds[$rng->getInt(0, 2)];
        // список сайтов: op-узлы для op_swap, атомы для atom_swap, узлы для drop
        $sites = [];
        $walk = static function (array $node, array $path) use (&$walk, &$sites, $kind): void {
            if (isset($node['atom'])) {
                if ($kind === 'atom_swap') {
                    $sites[] = $path;
                }

                return;
            }
            if ($kind === 'op_swap' || $kind === 'drop') {
                $sites[] = $path;
            }
            foreach (['l', 'r'] as $i => $k) {
                if (isset($node[$k]) && is_array($node[$k])) {
                    $walk($node[$k], array_merge($path, [$k]));
                }
            }
        };
        $walk($targetAst, []);
        if ($sites === []) {
            $kind = 'op_swap';
            $walk2 = static function (array $node, array $path) use (&$walk2, &$sites): void {
                if (! isset($node['atom'])) {
                    $sites[] = $path;
                    foreach (['l', 'r'] as $k) {
                        if (isset($node[$k]) && is_array($node[$k])) {
                            $walk2($node[$k], array_merge($path, [$k]));
                        }
                    }
                }
            };
            $walk2($targetAst, []);
        }
        $path = $sites[$rng->getInt(0, count($sites) - 1)];

        $ast = GenomeBenchmarkLib::cloneAst($targetAst);
        // применяем повреждение по path; detail фиксирует ЧТО изменилось
        $ref = &$ast;
        foreach ($path as $step) {
            $ref = &$ref[$step];
        }
        $detail = '';
        if ($kind === 'op_swap') {
            $old = (string) $ref['op'];
            $ops = array_values(array_diff(self::BASE_OPS, [$old]));
            $new = $ops[$rng->getInt(0, count($ops) - 1)];
            $ref['op'] = $new;
            $detail = $old . '→' . $new;
        } elseif ($kind === 'atom_swap') {
            $old = (string) $ref['atom'];
            if (preg_match('/^x(\d+)$/', $old, $m) === 1) {
                $cols = array_values(array_diff(['0', '1', '2', '3', '4', '5'], [$m[1]]));
                $new = 'x' . $cols[$rng->getInt(0, count($cols) - 1)];
            } else {
                $new = 'x0';
            }
            $ref['atom'] = $new;
            $detail = $old . '→' . $new;
        } else {
            $detail = 'drop@' . count($path);
            $ref = ['atom' => 'x' . $rng->getInt(0, 5)];
        }

        return ['ast' => $ast, 'damage' => ['kind' => $kind, 'path' => $path, 'detail' => $detail]];
    }

    // ── Мутации (req 5: сопоставимые, uniform) ─────────────────────────

    /**
     * Применить одну мутацию к клону AST (руки A и C; C хранит DAG, мутация
     * та же по дереву — copy-on-write на стороне бенчмарка).
     *
     * @param list<array{ast: array, slots: list<int>}> $pool shape'ы словаря (alpha-канонические AST)
     */
    public static function mutateAst(array $ast, object $rng, array $pool): array
    {
        $op = self::pickOp($rng);
        // сайты
        $opSites = [];
        $atomSites = [];
        $allSites = [];
        $walk = static function (array $node, array $path) use (&$walk, &$opSites, &$atomSites, &$allSites): void {
            if (isset($node['atom'])) {
                $atomSites[] = $path;
                $allSites[] = $path;

                return;
            }
            $opSites[] = $path;
            $allSites[] = $path;
            foreach (['l', 'r'] as $k) {
                if (isset($node[$k]) && is_array($node[$k])) {
                    $walk($node[$k], array_merge($path, [$k]));
                }
            }
        };
        $walk($ast, []);

        $apply = static function (array $site, callable $f) use (&$ast): void {
            $ref = &$ast;
            foreach ($site as $step) {
                $ref = &$ref[$step];
            }
            $f($ref);
        };

        switch ($op) {
            case 'op_swap':
                if ($opSites === []) {
                    return ['ast' => $ast, 'op' => $op, 'site_kind' => 'none', 'noop' => true];
                }
                $site = $opSites[$rng->getInt(0, count($opSites) - 1)];
                $apply($site, static function (&$node) use ($rng): void {
                    $ops = array_values(array_diff(['+', '−', '×', '/'], [(string) $node['op']]));
                    $node['op'] = $ops[$rng->getInt(0, count($ops) - 1)];
                });

                return ['ast' => $ast, 'op' => $op, 'site_kind' => $op === 'op_swap' ? 'op' : ($op === 'atom_swap' ? 'atom' : 'any'), 'noop' => false];
            case 'atom_swap':
                if ($atomSites === []) {
                    return ['ast' => $ast, 'op' => $op, 'site_kind' => 'none', 'noop' => true];
                }
                $site = $atomSites[$rng->getInt(0, count($atomSites) - 1)];
                $apply($site, static function (&$node) use ($rng): void {
                    $node['atom'] = 'x' . $rng->getInt(0, 5);
                });

                return ['ast' => $ast, 'op' => $op, 'site_kind' => $op === 'op_swap' ? 'op' : ($op === 'atom_swap' ? 'atom' : 'any'), 'noop' => false];
            case 'splice':
                if ($allSites === [] || $pool === []) {
                    return ['ast' => $ast, 'op' => $op, 'site_kind' => 'none', 'noop' => true];
                }
                $site = $allSites[$rng->getInt(0, count($allSites) - 1)];
                $shape = $pool[$rng->getInt(0, count($pool) - 1)];
                $apply($site, static function (&$node) use ($shape, $rng): void {
                    $node = GenomeEvolveLib::instantiateShape($shape['ast'], $rng);
                });

                return ['ast' => $ast, 'op' => $op, 'site_kind' => $op === 'op_swap' ? 'op' : ($op === 'atom_swap' ? 'atom' : 'any'), 'noop' => false];
            default: // drop
                if ($allSites === []) {
                    return ['ast' => $ast, 'op' => 'drop', 'site_kind' => 'none', 'noop' => true];
                }
                $site = $allSites[$rng->getInt(0, count($allSites) - 1)];
                $apply($site, static function (&$node) use ($rng): void {
                    $node = ['atom' => 'x' . $rng->getInt(0, 5)];
                });

                return ['ast' => $ast, 'op' => 'drop', 'site_kind' => 'any', 'noop' => false];
        }
    }

    /**
     * Мутация генома (рука B): та же семантика классов, гранулярность —
     * word-ref как единая единица на ref-позициях.
     *
     * @param list<array{name: string, arity: int, genome: array, def: array}> $entries
     */
    public static function mutateGenome(array $genome, object $rng, array $entries): array
    {
        $op = self::pickOp($rng);
        $opSites = [];
        $atomSites = [];
        $refSites = [];
        $allSites = [];
        $walk = static function (array $node, array $path) use (&$walk, &$opSites, &$atomSites, &$refSites, &$allSites, $entries): void {
            if (isset($node['atom'])) {
                $atomSites[] = $path;
                $allSites[] = $path;

                return;
            }
            if (isset($node['word'])) {
                $refSites[] = $path;
                $allSites[] = $path;

                return;
            }
            $opSites[] = $path;
            $allSites[] = $path;
            foreach (['l', 'r'] as $k) {
                if (isset($node[$k]) && is_array($node[$k])) {
                    $walk($node[$k], array_merge($path, [$k]));
                }
            }
        };
        $walk($genome, []);
        $byName = [];
        foreach ($entries as $e) {
            $byName[(string) $e['name']] = $e;
        }

        $apply = static function (array $site, callable $f) use (&$genome): void {
            $ref = &$genome;
            foreach ($site as $step) {
                $ref = &$ref[$step];
            }
            $f($ref);
        };

        switch ($op) {
            case 'op_swap':
                // базовые op-узлы → другая базовая операция; word-ref → другое слово той же арности
                $sites = array_merge($opSites, $refSites);
                if ($sites === []) {
                    return ['genome' => $genome, 'op' => $op, 'noop' => true];
                }
                $site = $sites[$rng->getInt(0, count($sites) - 1)];
                $siteKind = in_array($site, $refSites) ? 'ref' : 'op';
                $apply($site, static function (&$node) use ($rng, $byName): void {
                    if (isset($node['word'])) {
                        $arity = (int) (isset($byName[$node['word']]) ? $byName[$node['word']]['arity'] : -1);
                        $same = [];
                        foreach ($byName as $e) {
                            if ((int) $e['arity'] === $arity && $e['name'] !== $node['word']) {
                                $same[] = (string) $e['name'];
                            }
                        }
                        if ($same !== []) {
                            $node['word'] = $same[$rng->getInt(0, count($same) - 1)];
                        }

                        return;
                    }
                    $ops = array_values(array_diff(['+', '−', '×', '/'], [(string) $node['op']]));
                    $node['op'] = $ops[$rng->getInt(0, count($ops) - 1)];
                });

                return ['genome' => $genome, 'op' => $op, 'site_kind' => $siteKind, 'noop' => false];
            case 'atom_swap':
                if ($atomSites === []) {
                    return ['genome' => $genome, 'op' => $op, 'site_kind' => 'none', 'noop' => true];
                }
                $site = $atomSites[$rng->getInt(0, count($atomSites) - 1)];
                $apply($site, static function (&$node) use ($rng): void {
                    $node['atom'] = 'x' . $rng->getInt(0, 5);
                });

                return ['genome' => $genome, 'op' => $op, 'site_kind' => 'atom', 'noop' => false];
            case 'splice':
                if ($allSites === [] || $entries === []) {
                    return ['genome' => $genome, 'op' => $op, 'site_kind' => 'none', 'noop' => true];
                }
                $site = $allSites[$rng->getInt(0, count($allSites) - 1)];
                $siteKind = in_array($site, $refSites) ? 'ref' : 'any';
                $apply($site, static function (&$node) use ($rng, $byName): void {
                    $names = array_keys($byName);
                    $name = (string) $names[$rng->getInt(0, count($names) - 1)];
                    $arity = (int) $byName[$name]['arity'];
                    $args = [];
                    for ($i = 0; $i < $arity; $i++) {
                        $args[] = ['atom' => 'x' . $rng->getInt(0, 5)];
                    }
                    $node = ['word' => $name, 'args' => $args];
                });

                return ['genome' => $genome, 'op' => $op, 'site_kind' => $siteKind, 'noop' => false];
            default: // drop
                if ($allSites === []) {
                    return ['genome' => $genome, 'op' => 'drop', 'site_kind' => 'none', 'noop' => true];
                }
                $site = $allSites[$rng->getInt(0, count($allSites) - 1)];
                $apply($site, static function (&$node) use ($rng): void {
                    $node = ['atom' => 'x' . $rng->getInt(0, 5)];
                });

                return ['genome' => $genome, 'op' => 'drop', 'site_kind' => 'any', 'noop' => false];
        }
    }

    /** Shape → инстанс со случайной инъективной привязкой слотов к x0..x3. */
    public static function instantiateShape(array $shapeAst, object $rng): array
    {
        $slots = WordCodec::slotIndices($shapeAst);
        $cols = [0, 1, 2, 3, 4, 5];
        for ($s = count($cols) - 1; $s > 0; $s--) {
            $t = $rng->getInt(0, $s);
            [$cols[$s], $cols[$t]] = [$cols[$t], $cols[$s]];
        }
        $binding = [];
        foreach (array_values($slots) as $j => $slot) {
            $binding['x' . $slot] = ['atom' => 'x' . $cols[$j % 6]];
        }

        return GenomeBenchmarkLib::substituteAlpha($shapeAst, $binding);
    }

    // ── Фитнес и вёдра ─────────────────────────────────────────────────

    /** rmse по строкам; null если хоть одна строка не вычислима (некорректен). */
    public static function rmse(array $ast, array $rows, array $y): ?float
    {
        $sse = 0.0;
        foreach ($rows as $i => $row) {
            $v = ExpressionEvaluator::evalNode($ast, $row, [], []);
            if ($v === null || ! is_finite($v)) {
                return null;
            }
            $sse += ($v - $y[$i]) ** 2;
        }

        return sqrt($sse / count($rows));
    }

    /** Функциональный ключ: структура + атомы as-is, коммутативные сортируются. */
    public static function functionKey(array $node): string
    {
        $key = static function (array $n) use (&$key): string {
            if (isset($n['atom'])) {
                return (string) $n['atom'];
            }
            $l = isset($n['l']) && is_array($n['l']) ? $key($n['l']) : '';
            $r = isset($n['r']) && is_array($n['r']) ? $key($n['r']) : '';
            $op = (string) $n['op'];
            if ($r === '') {
                return '(' . $op . ' ' . $l . ')';
            }
            if (in_array($op, ['+', '×', 'max', 'min'], true) && strcmp($l, $r) > 0) {
                [$l, $r] = [$r, $l];
            }

            return '(' . $l . $op . $r . ')';
        };

        return $key($node);
    }

    public static function pickOp(object $rng): string
    {
        $r = $rng->getInt(0, 1000000) / 1000000.0; // PHP 8.2: нет float-методов
        $acc = 0.0;
        foreach (self::OP_WEIGHTS as $k => $w) {
            $acc += $w;
            if ($r < $acc) {
                return $k;
            }
        }

        return 'drop';
    }

    // ── Рука C: DAG с in-place мутацией общих узлов ────────────────────

    /** Hash-cons таблицы (как GenomeBenchmarkLib::dagBytes, но возвращает структуру). */
    public static function dagBuild(array $ast): array
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
                    [$l, $r] = $l <= $r ? [$l, $r] : [$r, $l];
                }
                $key = 'o:' . $op . ':' . $l . ':' . $r;
            }
            if (isset($ids[$key])) {
                return $ids[$key];
            }
            $id = count($table);
            $table[] = isset($node['atom']) ? ['a', (string) $node['atom']] : ['o', (string) $node['op'], $l, $r];
            $ids[$key] = $id;

            return $id;
        };
        $root = $intern($ast);

        return ['table' => $table, 'root' => $root];
    }

    /**
     * Мутация DAG: сайт = узел ТАБЛИЦЫ (uniform); общие узлы меняются во ВСЕХ
     * вхождениях (семантика представления). splice — интернирование shape'ы.
     *
     * @param list<array{ast: array, slots: list<int>}> $pool
     */
    public static function mutateDag(array $dag, object $rng, array $pool): array
    {
        $table = $dag['table'];
        $op = self::pickOp($rng);
        // сайты: узлы таблицы, достижимые из root (все таблицы достижимы — таблица строилась от root)
        $n = count($table);
        if ($n === 0) {
            return ['table' => $table, 'root' => $dag['root'], 'op' => $op, 'noop' => true];
        }
        $id = $rng->getInt(0, $n - 1);
        switch ($op) {
            case 'op_swap':
                if ($table[$id][0] !== 'o') {
                    return ['table' => $table, 'root' => $dag['root'], 'op' => $op, 'noop' => true];
                }
                $ops = array_values(array_diff(['+', '−', '×', '/'], [$table[$id][1]]));
                $table[$id][1] = $ops[$rng->getInt(0, count($ops) - 1)];

                return ['table' => $table, 'root' => $dag['root'], 'op' => $op, 'noop' => false];
            case 'atom_swap':
                if ($table[$id][0] !== 'a') {
                    return ['table' => $table, 'root' => $dag['root'], 'op' => $op, 'noop' => true];
                }
                $table[$id][1] = 'x' . $rng->getInt(0, 5);

                return ['table' => $table, 'root' => $dag['root'], 'op' => $op, 'noop' => false];
            case 'splice':
                if ($pool === []) {
                    return ['table' => $table, 'root' => $dag['root'], 'op' => $op, 'noop' => true];
                }
                $shape = $pool[$rng->getInt(0, count($pool) - 1)];
                $inst = self::instantiateShape($shape['ast'], $rng);
                $sub = self::dagBuild($inst);
                // переприсваиваем id поддерева в конец таблицы offspring'а
                $map = [];
                foreach ($sub['table'] as $i => $entry) {
                    $map[$i] = count($table);
                    $table[] = $entry;
                }
                foreach ($table as &$e) {
                    if ($e[0] === 'o') {
                        if (isset($map[$e[2]])) {
                            $e[2] = $map[$e[2]];
                        }
                        if (isset($map[$e[3]])) {
                            $e[3] = $map[$e[3]];
                        }
                    }
                }
                unset($e);
                $table[$id] = $table[$map[$sub['root']]];

                return ['table' => $table, 'root' => $dag['root'], 'op' => $op, 'noop' => false];
            default: // drop
                $table[$id] = ['a', 'x' . $rng->getInt(0, 5)];

                return ['table' => $table, 'root' => $dag['root'], 'op' => 'drop', 'noop' => false];
        }
    }

    /** DAG → эквивалентный AST (для ключей новизны; hash-cons после мутаций может дублировать узлы). */
    public static function dagToAst(array $table, int $id): array
    {
        $e = $table[$id];
        if ($e[0] === 'a') {
            return ['atom' => (string) $e[1]];
        }

        return [
            'op' => (string) $e[1],
            'l' => $e[2] >= 0 ? self::dagToAst($table, $e[2]) : null,
            'r' => $e[3] >= 0 ? self::dagToAst($table, $e[3]) : null,
        ];
    }

    /** Исполнение DAG: построчно. */
    public static function evalDagRows(array $dag, array $rows): array
    {
        $table = $dag['table'];
        $memo = [];
        $eval = static function (int $id, array $row) use (&$eval, $table, &$memo): ?float {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            $e = $table[$id];
            if ($e[0] === 'a') {
                $v = preg_match('/^x(\d+)$/', (string) $e[1], $m) === 1
                    ? (float) ($row[(int) $m[1]] ?? 0)
                    : (is_numeric((string) $e[1]) ? (float) $e[1] : ($e[1] === 'K1' ? 1.0 : ($e[1] === 'K2' ? 2.0 : null)));
                $memo[$id] = $v;

                return $v;
            }
            $l = $e[2] >= 0 ? $eval($e[2], $row) : null;
            $r = $e[3] >= 0 ? $eval($e[3], $row) : null;
            $v = match ($e[1]) {
                '+' => ($l !== null && $r !== null) ? $l + $r : null,
                '−' => ($l !== null && $r !== null) ? $l - $r : null,
                '×' => ($l !== null && $r !== null) ? $l * $r : null,
                '/' => ($l !== null && $r !== null && $r != 0) ? $l / $r : null,
                default => null,
            };
            $memo[$id] = $v;

            return $v;
        };
        $out = [];
        foreach ($rows as $row) {
            $out[] = $eval($dag['root'], $row);
        }

        return $out;
    }

    /** DAG → rmse (null = некорректен). */
    public static function rmseDag(array $dag, array $rows, array $y): ?float
    {
        $vals = self::evalDagRows($dag, $rows);
        $sse = 0.0;
        foreach ($vals as $i => $v) {
            if ($v === null || ! is_finite($v)) {
                return null;
            }
            $sse += ($v - $y[$i]) ** 2;
        }

        return sqrt($sse / count($rows));
    }

    // ── Один повтор руки (общая машина пилота и бенчмарка) ─────────────

    /**
     * @param list<array{ast: array, slots: list<int>}> $pool
     * @param list<array{name: string, arity: int, genome: array, def: array}> $entries
     * @return array статистика руки на этом повторе
     */
    public static function runReplicate(
        string $arm,
        array $task,
        array $parentAst,
        array $pool,
        array $entries,
        int $attempts,
        int $seed
    ): array {
        $parentRmse = self::rmse($parentAst, array_merge($task['train'], $task['held']), array_merge($task['y_train'], $task['y_held']));
        $parentRmseHeld = self::rmse($parentAst, $task['held'], $task['y_held']);
        $buckets = ['incorrect' => 0, 'correct_flat' => 0, 'reuse_shape' => 0, 'improved_not_novel' => 0, 'useful_new' => 0, 'noop' => 0, 'invalid' => 0];
        $seen = [];
        $seen['f:' . self::functionKey($parentAst)] = true;
        $parentKey = self::functionKey($parentAst);
        $evalCalls = 0;
        $usefulByOp = ['op_swap' => 0, 'atom_swap' => 0, 'splice' => 0, 'drop' => 0];
        $refAttempts = 0;
        $refUseful = 0;
        $t = hrtime(true);
        $parentGenome = null;
        $parentDag = null;
        if ($arm === 'B') {
            $parentGenome = WordCodec::encode($parentAst, $entries);
        }
        if ($arm === 'C') {
            $parentDag = self::dagBuild($parentAst);
        }
        $rowsAll = array_merge($task['train'], $task['held']);
        $yAll = array_merge($task['y_train'], $task['y_held']);

        for ($a = 0; $a < $attempts; $a++) {
            $rng = new Random\Randomizer(new Random\Engine\Mt19937($seed + 977 * $a));
            $childAst = null;
            $res = null;
            try {
                if ($arm === 'A') {
                    $res = self::mutateAst($parentAst, $rng, $pool);
                    if ($res['noop']) {
                        $buckets['noop']++;

                        continue;
                    }
                    $childAst = $res['ast'];
                } elseif ($arm === 'B') {
                    $res = self::mutateGenome($parentGenome, $rng, $entries);
                    if ($res['noop']) {
                        $buckets['noop']++;

                        continue;
                    }
                    if ($res['site_kind'] === 'ref') {
                        $refAttempts++;
                    }
                    try {
                        $childAst = WordCodec::decode($res['genome'], $entries);
                    } catch (\BeeSwarm\Core\WordCodecException) {
                        $buckets['invalid']++;

                        continue;
                    }
                } else {
                    $res = self::mutateDag($parentDag, $rng, $pool);
                    if ($res['noop']) {
                        $buckets['noop']++;

                        continue;
                    }
                }
            } catch (\Throwable) {
                $buckets['invalid']++;

                continue;
            }

            $evalCalls += count($rowsAll);
            // корректность: все 20 строк конечны
            if ($arm === 'C') {
                $rmseAll = self::rmseDag(['table' => $res['table'], 'root' => $res['root']], $rowsAll, $yAll);
            } else {
                $rmseAll = self::rmse($childAst, $rowsAll, $yAll);
            }
            if ($rmseAll === null) {
                $buckets['incorrect']++;

                continue;
            }
            if ($arm === 'C') {
                $childAst = self::dagToAst($res['table'], $res['root']);
            }
            $childKey = 'f:' . self::functionKey($childAst);
            // улучшение: ТОЛЬКО held-out (req 7)
            if ($arm === 'C') {
                $rmseHeld = self::rmseDag(['table' => $res['table'], 'root' => $res['root']], $task['held'], $task['y_held']);
            } else {
                $rmseHeld = self::rmse($childAst, $task['held'], $task['y_held']);
            }
            $alphaKey = $childAst === null ? null : GenomeBenchmarkLib::alphaCanonicalKey($childAst);
            if (! ($rmseHeld < $parentRmseHeld - 1e-12)) {
                if ($childKey !== null) {
                    $seen[$childKey] = true;
                }
                $buckets['correct_flat']++;

                continue;
            }
            // улучшение есть; req 8: reuse готовой формулы / известной конструкции
            if ($alphaKey !== null && isset(self::$dictAlphaKeys[$alphaKey])) {
                $buckets['reuse_shape']++;

                continue;
            }
            if ($childKey !== null && $childKey === 'f:' . $parentKey) {
                $buckets['reuse_shape']++; // функционально идентичен родителю

                continue;
            }
            if ($childKey !== null && isset($seen[$childKey])) {
                $buckets['improved_not_novel']++;

                continue;
            }
            if ($childKey !== null) {
                $seen[$childKey] = true;
            }
            $buckets['useful_new']++;
            $usefulByOp[$res['op']] = ($usefulByOp[$res['op']] ?? 0) + 1;
            if ($arm === 'B' && $res['site_kind'] === 'ref') {
                $refUseful++;
            }
        }
        $wallMs = (hrtime(true) - $t) / 1e6;

        return [
            'arm' => $arm,
            'parent_rmse' => $parentRmse,
            'buckets' => $buckets,
            'useful_new' => $buckets['useful_new'],
            'useful_by_op' => $usefulByOp,
            'ref_attempts' => $refAttempts,
            'ref_useful' => $refUseful,
            'eval_calls' => $evalCalls,
            'wall_ms' => round($wallMs, 1),
        ];
    }

    /** Alpha-ключи словаря (req 8: reuse-детектор) — заполняется бенчмарком. */
    public static array $dictAlphaKeys = [];

    // ── Train-семейство реплики (манифест §2) ──────────────────────────

    /**
     * «Ранее открытые конструкции» цели: её собственные proper-subtrees
     * (≥4 узлов, топ-3 по размеру) × 2 случайных binding'а каждый —
     * probe0-сценарий (та же форма открыта на других доменах/колонках).
     * Целые формы в train НЕ попадают → repaired-цель остаётся genuinely-new,
     * reuse-ведро ловит только голые инстансы слов (манифест §5).
     *
     * @return list<array{ast: array}> программы train-корпуса реплики
     */
    public static function trainFamily(array $targetAst, int $seed): array
    {
        $shapes = [];
        $seen = [];
        $walk = static function (array $node, int $depth) use (&$walk, &$shapes, &$seen): void {
            if (isset($node['atom'])) {
                return;
            }
            $nodes = WordCodec::nodesCount($node);
            $key = GenomeBenchmarkLib::alphaCanonicalKey($node);
            if ($depth >= 1 && $nodes >= 4 && ! isset($seen[$key])) {
                $seen[$key] = true;
                $shapes[] = ['ast' => GenomeBenchmarkLib::cloneAst($node), 'nodes' => $nodes];
            }
            foreach (['l', 'r'] as $k) {
                if (isset($node[$k]) && is_array($node[$k])) {
                    $walk($node[$k], $depth + 1);
                }
            }
        };
        $walk($targetAst, 0);
        usort($shapes, static fn (array $a, array $b): int => $b['nodes'] <=> $a['nodes']);
        $top = array_slice($shapes, 0, 3);
        $rng = new Random\Randomizer(new Random\Engine\Mt19937($seed));
        $programs = [];
        foreach ($top as $sh) {
            foreach ([0, 1] as $j) {
                $programs[] = ['ast' => self::instantiateShape($sh['ast'], $rng)];
            }
        }

        return $programs;
    }
}
