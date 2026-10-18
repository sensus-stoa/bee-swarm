<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * GENOME-001 (15.10.2026): кодек наследуемых исполняемых слов (CHECKPOINT A).
 *
 * Кодек работает НАД деревом ExpressionNormalizer ({op,l,r} / {atom}), не над строками.
 *
 * Узлы генома:
 *  - {'atom': name}                     — лист (xN / K1 / K2 / число)
 *  - {'op': op, 'l': node, 'r': node}   — операторный узел, проходим БЕЗ изменений
 *    (born-op application {op: BW…} тоже проходит как есть — faithful-сериализация);
 *  - {'word': name, 'args': [node,…]}   — ссылка на слово; возникает ТОЛЬКО при
 *    pattern-match поддерева против definition статьи словаря.
 *
 * Decode разворачивает {word} ссылки одновременной подстановкой слотов
 * (xN → args[N] — та же конвенция, что evalNode row[N] у born-операторов),
 * capture-free (вставленные аргументы не ре-обходятся). Decode НЕ пере-парсит
 * строки — структурная эквивалентность гарантирована построением; допустимое
 * отклонение — порядок коммутативных операндов (+' ×' max min add mul).
 *
 * decodeExpanded() дополнительно раскрывает born-op application {op: W, l, r}
 * известного слова. Арность W обязана быть 2 (бинарная форма AST); при арности ≠2 —
 * ЯВНЫЙ отказ: движок на этом месте молча подставлял бы xN→0 (Link2 FAIL,
 * [FACT: ExpressionEvaluator.php:282] row=[$l,$r]) — кодек повторять это не имеет права.
 *
 * Словарь: список статей {'name', 'arity', 'genome'} (+ ленивое 'def' = AST);
 * definition статьи сами могут содержать {word}-ссылки (T₂ → T₁, разделяемые
 * подвыражения → одна статья). Циклы ссылок запрещены (active-set guard).
 *
 * НЕ трогает Search / applyN / bornBinary / механизмы рождения (граница GENOME-001).
 */
final class WordCodec
{
    /**
     * Коммутативные операторы (символьные + имена Search). born-op имена сюда не входят.
     */
    private const COMMUTATIVE = ['+', '×', 'max', 'min', 'add', 'mul'];

    public const BUNDLE_FORMAT = 'genome001';

    public const BUNDLE_VERSION = 1;

    // ── Построение словаря ─────────────────────────────────────────────

    /**
     * Словарь из упорядоченных definition-строк (порядок = порядок зависимостей).
     *
     * @param array<string, string> $defs       name => definition
     * @param list<string>          $birthNames имена birth-операторов для парсера
     *
     * @return list<array{name: string, arity: int, genome: array, def?: array}>
     */
    public static function buildDictionary(array $defs, array $birthNames = []): array
    {
        $entries = [];
        foreach ($defs as $name => $definition) {
            $ast = self::parseDefinition((string) $definition, $birthNames);
            $entries[] = self::makeEntry((string) $name, $ast, $entries);
        }
        self::validateDictionary($entries);

        return $entries;
    }

    /**
     * protect → parse(birthNames) → restoreAtoms — путь evaluateFormula (B-CULTURE-PARSE).
     *
     * @return array{op: string, l: array, r: array|null}|array{atom: string}
     */
    public static function parseDefinition(string $definition, array $birthNames = []): array
    {
        [$protected, $map] = ExpressionNormalizer::protect($definition);
        $node = ExpressionNormalizer::parse($protected, $birthNames);
        if ($node === null) {
            throw new WordCodecException("parse failed: {$definition}");
        }
        if ($map !== []) {
            $node = ExpressionNormalizer::restoreAtoms($node, $map);
        }

        return $node;
    }

    /**
     * Статья словаря из definition-AST: валидация слотов (контiguity 0..max),
     * геном definition кодируется против уже существующих статей.
     *
     * @param array{op: string, l: array, r: array|null}|array{atom: string} $defAst
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $existingEntries
     *
     * @return array{name: string, arity: int, genome: array, def?: array}
     */
    public static function makeEntry(string $name, array $defAst, array $existingEntries): array
    {
        $indices = self::slotIndices($defAst);
        $arity = 0;
        if ($indices !== []) {
            $max = max($indices);
            if (count($indices) !== $max + 1) {
                $list = implode(',', $indices);
                throw new WordCodecException("word {$name}: slots не контigуальны ({$list}) — арность неоднозначна");
            }
            $arity = $max + 1;
        }
        $existing = self::withDefs($existingEntries);
        $genome = self::encodeNode($defAst, self::indexEntries($existing), self::orderedEntries($existing));

        return [
            'name' => $name,
            'arity' => $arity,
            'genome' => $genome,
            'def' => $defAst,
        ];
    }

    // ── Encode / Decode ────────────────────────────────────────────────

    /**
     * AST программы → геном. Жадное сопоставление: на каждом узле статьи
     * перебираются от крупнейшей к меньшей (nodesCount desc, name asc).
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     */
    public static function encode(array $ast, array $entries): array
    {
        $withDefs = self::withDefs($entries);

        return self::encodeNode($ast, self::indexEntries($withDefs), self::orderedEntries($withDefs));
    }

    /**
     * Геном → AST: разворачивание {word}-ссылок (структурная эквивалентность исходнику).
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     */
    public static function decode(array $genome, array $entries): array
    {
        return self::decodeNode($genome, self::indexEntries(self::withDefs($entries)), [], false);
    }

    /**
     * Decode + раскрытие born-op application известных слов → чистый AST
     * базовой грамматики (исполним БЕЗ зарегистрированных атомов).
     * Арность application обязана быть 2 — иначе явный отказ.
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     */
    public static function decodeExpanded(array $genome, array $entries): array
    {
        return self::decodeNode($genome, self::indexEntries(self::withDefs($entries)), [], true);
    }

    /**
     * Definition статьи (геном) → AST definition (ссылки развернуты).
     *
     * @param array{name: string, arity: int, genome: array, def?: array} $entry
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     *
     * @return array{op: string, l: array, r: array|null}|array{atom: string}
     */
    public static function decodeDefinition(array $entry, array $entries): array
    {
        if (isset($entry['def']) && is_array($entry['def'])) {
            return $entry['def'];
        }

        return self::decodeNode($entry['genome'], self::indexEntries(self::withDefs($entries)), [(string) $entry['name']], false);
    }

    // ── Слоты / арность / зависимости ──────────────────────────────────

    /**
     * Формальные слоты definition: индексы N атомов xN, отсортированы.
     *
     * @return list<int>
     */
    public static function slotIndices(array $ast): array
    {
        $set = [];
        $walk = static function (array $node) use (&$walk, &$set): void {
            if (isset($node['atom'])) {
                if (preg_match('/^x(\d+)$/', (string) $node['atom'], $m) === 1) {
                    $set[(int) $m[1]] = true;
                }

                return;
            }
            foreach (['l', 'r'] as $k) {
                if (isset($node[$k]) && is_array($node[$k])) {
                    $walk($node[$k]);
                }
            }
        };
        $walk($ast);
        $idx = array_keys($set);
        sort($idx);

        return $idx;
    }

    /**
     * Зависимости статьи: {word}-ссылки + born-op application, чьи имена —
     * статьи этого словаря. Для валидации циклов и полноты передачи в чистую БД.
     *
     * @param array{name: string, arity: int, genome: array, def?: array} $entry
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     *
     * @return list<string>
     */
    public static function dependenciesOfEntry(array $entry, array $entries): array
    {
        $names = [];
        foreach ($entries as $e) {
            $names[(string) $e['name']] = true;
        }
        $deps = [];
        $walk = static function (array $node) use (&$walk, &$deps, $names): void {
            if (isset($node['atom'])) {
                return;
            }
            if (isset($node['word']) && isset($names[(string) $node['word']])) {
                $deps[(string) $node['word']] = true;
            }
            if (isset($node['op']) && isset($names[(string) $node['op']])) {
                $deps[(string) $node['op']] = true;
            }
            foreach (['l', 'r'] as $k) {
                if (isset($node[$k]) && is_array($node[$k])) {
                    $walk($node[$k]);
                }
            }
            if (isset($node['args']) && is_array($node['args'])) {
                foreach ($node['args'] as $a) {
                    if (is_array($a)) {
                        $walk($a);
                    }
                }
            }
        };
        $walk($entry['genome']);
        $out = array_keys($deps);
        sort($out);

        return $out;
    }

    /**
     * Циклы ссылок (через {word} И born-op application) запрещены.
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     */
    public static function validateDictionary(array $entries): void
    {
        $byName = self::indexEntries($entries);
        $state = [];
        $visit = static function (string $name, array $stack) use (&$visit, $byName, &$state): void {
            $s = $state[$name] ?? 0;
            if ($s === 2) {
                return;
            }
            if ($s === 1) {
                $stack[] = $name;
                throw new WordCodecException('cyclic dictionary: ' . implode(' -> ', $stack));
            }
            $state[$name] = 1;
            $stack[] = $name;
            foreach (self::dependenciesOfEntry($byName[$name], array_values($byName)) as $dep) {
                $visit($dep, $stack);
            }
            $state[$name] = 2;
        };
        foreach (array_keys($byName) as $n) {
            $visit((string) $n, []);
        }
    }

    // ── Structural helpers (тесты / Checkpoint B) ──────────────────────

    /**
     * Число узлов (атомы, операторы, ссылки с аргументами).
     */
    public static function nodesCount(array $node): int
    {
        if (isset($node['atom'])) {
            return 1;
        }
        $n = 1;
        foreach (['l', 'r'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                $n += self::nodesCount($node[$k]);
            }
        }
        if (isset($node['args']) && is_array($node['args'])) {
            foreach ($node['args'] as $a) {
                if (is_array($a)) {
                    $n += self::nodesCount($a);
                }
            }
        }

        return $n;
    }

    /**
     * Структурная эквивалентность AST. $commutative=true допускает перестановку
     * операндов коммутативных операторов (рамка HANDOFF: каноническая эквивалентность).
     */
    public static function astEqual(array $a, array $b, bool $commutative = true): bool
    {
        if (isset($a['atom']) || isset($b['atom'])) {
            return isset($a['atom'], $b['atom']) && $a['atom'] === $b['atom'];
        }
        if (! isset($a['op'], $b['op']) || $a['op'] !== $b['op']) {
            return false;
        }
        $al = isset($a['l']) && is_array($a['l']) ? $a['l'] : null;
        $ar = isset($a['r']) && is_array($a['r']) ? $a['r'] : null;
        $bl = isset($b['l']) && is_array($b['l']) ? $b['l'] : null;
        $br = isset($b['r']) && is_array($b['r']) ? $b['r'] : null;
        if (($ar === null) !== ($br === null)) {
            return false;
        }
        if ($ar === null) {
            return $al !== null && $bl !== null && self::astEqual($al, $bl, $commutative);
        }
        if ($al === null || $bl === null) {
            return false;
        }

        return self::astEqualChildren((string) $a['op'], $al, $ar, $bl, $br, $commutative);
    }

    /**
     * Дети бинарного узла: прямая пара, для коммутативных операторов —
     * при несовпадении прямой пары проверяется зеркальная.
     */
    private static function astEqualChildren(
        string $op,
        array $al,
        array $ar,
        array $bl,
        array $br,
        bool $commutative
    ): bool {
        $straight = self::astEqual($al, $bl, $commutative) && self::astEqual($ar, $br, $commutative);
        if ($straight || ! $commutative || ! in_array($op, self::COMMUTATIVE, true)) {
            return $straight;
        }

        return self::astEqual($al, $br, $commutative) && self::astEqual($ar, $bl, $commutative);
    }

    /**
     * Есть ли в AST application известного born-op слова (для контроля полноты раскрытия).
     *
     * @param list<string> $names
     */
    public static function containsWordApplications(array $ast, array $names): bool
    {
        if (isset($ast['atom'])) {
            return false;
        }
        if (isset($ast['op']) && in_array((string) $ast['op'], $names, true)) {
            return true;
        }
        foreach (['l', 'r'] as $k) {
            if (isset($ast[$k]) && is_array($ast[$k]) && self::containsWordApplications($ast[$k], $names)) {
                return true;
            }
        }

        return false;
    }

    // ── Bundle (передача в чистую линию) ───────────────────────────────

    /**
     * Словарь+геномы → JSON. 'def' в bundle не попадает (восстанавливается лениво).
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     * @param array<string, array> $genomes name => genome node
     */
    public static function serializeBundle(array $entries, array $genomes = []): string
    {
        $dict = [];
        foreach ($entries as $e) {
            $dict[] = [
                'name' => (string) $e['name'],
                'arity' => (int) $e['arity'],
                'genome' => $e['genome'],
            ];
        }

        return (string) json_encode(
            [
                'format' => self::BUNDLE_FORMAT,
                'version' => self::BUNDLE_VERSION,
                'dictionary' => $dict,
                'genomes' => $genomes,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * JSON → [entries, genomes]. Формат/версия проверяются.
     *
     * @return array{0: list<array{name: string, arity: int, genome: array}>, 1: array<string, array>}
     */
    public static function loadBundle(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (
            ! is_array($data)
            || ($data['format'] ?? null) !== self::BUNDLE_FORMAT
            || ($data['version'] ?? null) !== self::BUNDLE_VERSION
        ) {
            throw new WordCodecException('bundle: неизвестный формат/версия');
        }
        $dict = $data['dictionary'] ?? null;
        if (! is_array($dict)) {
            throw new WordCodecException('bundle: dictionary missing');
        }
        $entries = [];
        foreach ($dict as $e) {
            if (! is_array($e) || ! isset($e['name'], $e['arity'], $e['genome']) || ! is_array($e['genome'])) {
                throw new WordCodecException('bundle: malformed entry');
            }
            $entries[] = [
                'name' => (string) $e['name'],
                'arity' => (int) $e['arity'],
                'genome' => $e['genome'],
            ];
        }
        $genomes = $data['genomes'] ?? [];
        if (! is_array($genomes)) {
            throw new WordCodecException('bundle: malformed genomes');
        }

        return [$entries, $genomes];
    }

    // ── БД-мосты (clean-DB transfer; Search/рождение НЕ трогаются) ─────

    /**
     * Словарь из birth-атомов БД (порядок id = порядок рождения).
     *
     * @return list<array{name: string, arity: int, genome: array, def?: array}>
     */
    public static function dictionaryFromDb(): array
    {
        $rows = \BeeSwarm\Infra\Database::get()
            ->query("SELECT name, definition FROM grammar_ops WHERE source = 'birth' AND definition IS NOT NULL AND definition != '' ORDER BY id")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $defs = [];
        foreach ($rows as $row) {
            $defs[(string) $row['name']] = (string) $row['definition'];
        }

        return self::buildDictionary($defs, ExpressionEvaluator::birthOpNames());
    }

    /**
     * Регистрация статей в grammar_ops чистой БД (source='birth'), definition =
     * точный рендер развернутого definition. Сбрасывает stale-кэш birthOpNames
     * (HANDOFF питфолл 3: sentinel по COUNT).
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     */
    public static function registerDictionary(array $entries): void
    {
        $stmt = \BeeSwarm\Infra\Database::get()->prepare(
            "INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')"
        );
        foreach ($entries as $e) {
            $definition = ExpressionNormalizer::render(self::decodeDefinition($e, $entries));
            $stmt->execute([(string) $e['name'], $definition]);
        }
        ExpressionEvaluator::resetCaches();
    }

    // ── Внутреннее ─────────────────────────────────────────────────────

    /**
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     *
     * @return array<string, array>
     */
    private static function indexEntries(array $entries): array
    {
        $byName = [];
        foreach ($entries as $e) {
            $byName[(string) $e['name']] = $e;
        }

        return $byName;
    }

    /**
     * Порядок перебора статей при encode: крупнейшие первыми (жадный максимум),
     * при равенстве — по имени (детерминизм).
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     *
     * @return list<array{name: string, arity: int, genome: array, def?: array}>
     */
    private static function orderedEntries(array $entries): array
    {
        $ordered = $entries;
        usort($ordered, static function (array $a, array $b): int {
            $ca = self::nodesCount((array) $a['genome']);
            $cb = self::nodesCount((array) $b['genome']);
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $ordered;
    }

    /**
     * Ленивое восстановление 'def' (AST) для статей, пришедших из bundle.
     *
     * @param list<array{name: string, arity: int, genome: array, def?: array}> $entries
     *
     * @return list<array{name: string, arity: int, genome: array, def?: array}>
     */
    private static function withDefs(array $entries): array
    {
        $byName = self::indexEntries($entries);
        foreach ($entries as $i => $e) {
            if (! isset($e['def']) || ! is_array($e['def'])) {
                $e['def'] = self::decodeNode((array) $e['genome'], $byName, [(string) $e['name']], false);
            }
            $entries[$i] = $e;
        }

        return $entries;
    }

    /**
     * @param array<string, array> $byName
     */
    private static function encodeNode(array $node, array $byName, array $ordered): array
    {
        if (isset($node['atom'])) {
            return [
                'atom' => (string) $node['atom'],
            ];
        }
        foreach ($ordered as $entry) {
            $binding = [];
            if (self::matchNode((array) $entry['def'], $node, self::entrySlotNames($entry), $binding)) {
                $args = [];
                for ($i = 0; $i < (int) $entry['arity']; $i++) {
                    $slot = 'x' . $i;
                    if (! isset($binding[$slot]) || ! is_array($binding[$slot])) {
                        throw new WordCodecException("encode: слот {$slot} слова {$entry['name']} не связан");
                    }
                    $args[] = self::encodeNode($binding[$slot], $byName, $ordered);
                }

                return [
                    'word' => (string) $entry['name'],
                    'args' => $args,
                ];
            }
        }
        $l = isset($node['l']) && is_array($node['l']) ? self::encodeNode($node['l'], $byName, $ordered) : null;
        $r = isset($node['r']) && is_array($node['r']) ? self::encodeNode($node['r'], $byName, $ordered) : null;

        return [
            'op' => (string) ($node['op'] ?? ''),
            'l' => $l,
            'r' => $r,
        ];
    }

    /**
     * @param array{name: string, arity: int, genome: array, def?: array} $entry
     *
     * @return list<string>
     */
    private static function entrySlotNames(array $entry): array
    {
        $names = [];
        $count = (int) $entry['arity'];
        for ($i = 0; $i < $count; $i++) {
            $names[] = 'x' . $i;
        }

        return $names;
    }

    /**
     * Unification definition-паттерна против поддерева. Слоты паттерна (xN) —
     * wildcard'ы; повторное вхождение слота обязано связаться с ТОЙ ЖЕ структурой
     * (T₁ требует одинаковый x0 в числителе и знаменателе). Коммутативные
     * операторы: обе перестановки детей. Вставка в $binding (out-param).
     *
     * @param list<string> $slotNames
     * @param array<string, array> $binding
     */
    private static function matchNode(array $pattern, array $subject, array $slotNames, array &$binding): bool
    {
        if (isset($pattern['atom'])) {
            return self::matchAtomPattern((string) $pattern['atom'], $subject, $slotNames, $binding);
        }
        if (isset($subject['atom']) || ! isset($subject['op']) || (string) $subject['op'] !== (string) $pattern['op']) {
            return false;
        }
        $pl = isset($pattern['l']) && is_array($pattern['l']) ? $pattern['l'] : null;
        $pr = isset($pattern['r']) && is_array($pattern['r']) ? $pattern['r'] : null;
        $sl = isset($subject['l']) && is_array($subject['l']) ? $subject['l'] : null;
        $sr = isset($subject['r']) && is_array($subject['r']) ? $subject['r'] : null;
        if (($pr === null) !== ($sr === null)) {
            return false;
        }
        if ($pr === null) {
            return $pl !== null && $sl !== null && self::matchNode($pl, $sl, $slotNames, $binding);
        }
        if ($pl === null || $sl === null) {
            return false;
        }
        if (in_array((string) $pattern['op'], self::COMMUTATIVE, true)) {
            return self::matchCommutative($pl, $pr, $sl, $sr, $slotNames, $binding);
        }

        return self::matchNode($pl, $sl, $slotNames, $binding) && self::matchNode($pr, $sr, $slotNames, $binding);
    }

    /**
     * Атом-ветка паттерна: слот (wildcard, повторное вхождение — та же структура)
     * или литерал (константа/имя, точное совпадение).
     *
     * @param array<string, array> $binding
     * @param list<string>         $slotNames
     */
    private static function matchAtomPattern(string $name, array $subject, array $slotNames, array &$binding): bool
    {
        if (in_array($name, $slotNames, true)) {
            if (isset($binding[$name])) {
                return self::astEqual($binding[$name], $subject, true);
            }
            $binding[$name] = $subject;

            return true;
        }

        return isset($subject['atom']) && (string) $subject['atom'] === $name;
    }

    /**
     * Дети коммутативного оператора: прямая пара, при неудаче — зеркальная.
     * Binding первого успешного варианта побеждает (детерминизм).
     *
     * @param array<string, array> $binding
     * @param list<string>         $slotNames
     */
    private static function matchCommutative(
        array $pl,
        array $pr,
        array $sl,
        array $sr,
        array $slotNames,
        array &$binding
    ): bool {
        $try1 = $binding;
        if (self::matchNode($pl, $sl, $slotNames, $try1) && self::matchNode($pr, $sr, $slotNames, $try1)) {
            $binding = $try1;

            return true;
        }
        $try2 = $binding;
        if (self::matchNode($pl, $sr, $slotNames, $try2) && self::matchNode($pr, $sl, $slotNames, $try2)) {
            $binding = $try2;

            return true;
        }

        return false;
    }

    /**
     * @param array<string, array> $byName
     * @param list<string>         $active
     */
    private static function decodeNode(array $genome, array $byName, array $active, bool $expandApps): array
    {
        if (isset($genome['atom'])) {
            return [
                'atom' => (string) $genome['atom'],
            ];
        }
        if (isset($genome['word'])) {
            return self::decodeWordRef($genome, $byName, $active, $expandApps);
        }
        if (! isset($genome['op'])) {
            throw new WordCodecException('decode: malformed genome node (нет atom/op/word)');
        }
        $l = isset($genome['l']) && is_array($genome['l']) ? self::decodeNode($genome['l'], $byName, $active, $expandApps) : null;
        $r = isset($genome['r']) && is_array($genome['r']) ? self::decodeNode($genome['r'], $byName, $active, $expandApps) : null;
        $op = (string) $genome['op'];
        if ($expandApps && $l !== null && $r !== null && isset($byName[$op])) {
            // born-op application бинарной формы: ровно 2 аргумента, иначе отказ
            // (движок здесь молча подставлял бы xN→0 при арности ≥3 — Link2 FAIL)
            return self::applyWord($op, [$l, $r], $byName, $active, $expandApps);
        }

        return [
            'op' => $op,
            'l' => $l,
            'r' => $r,
        ];
    }

    /**
     * @param array<string, array> $byName
     * @param list<string>         $active
     */
    private static function decodeWordRef(array $genome, array $byName, array $active, bool $expandApps): array
    {
        $name = (string) $genome['word'];
        $entry = $byName[$name] ?? null;
        if ($entry === null) {
            throw new WordCodecException("unknown word: {$name}");
        }
        if (in_array($name, $active, true)) {
            throw new WordCodecException("cyclic word dependency: {$name}");
        }
        $args = $genome['args'] ?? null;
        if (! is_array($args)) {
            throw new WordCodecException("word {$name}: args missing");
        }
        $args = array_values($args);
        if (count($args) !== (int) $entry['arity']) {
            throw new WordCodecException("word {$name}: arity mismatch: " . count($args) . ' != ' . $entry['arity']);
        }
        $childActive = array_merge($active, [$name]);
        $def = self::entryDefAst($entry, $byName, $childActive, $expandApps);
        $decodedArgs = [];
        foreach ($args as $a) {
            if (! is_array($a)) {
                throw new WordCodecException("word {$name}: malformed arg");
            }
            // АРГУМЕНТЫ — контекст ПРОГРАММЫ, а не определения: вложенное
            // применение того же слова в аргументах легально (W(W(a,b,c),d,e));
            // active-set стража циклов защищает только цепочку ОПРЕДЕЛЕНИЙ
            // (EVO-FIX-001 FIX-2: раньше args декодировались с childActive —
            // легальные геномы ложно отклонялись как цикл)
            $decodedArgs[] = self::decodeNode($a, $byName, $active, $expandApps);
        }

        return self::substituteSlots($def, $decodedArgs, $name);
    }

    /**
     * Применение слова к аргументам (для decodeExpanded бинарных application).
     *
     * @param array<string, array> $byName
     * @param list<array>          $args
     * @param list<string>         $active
     */
    private static function applyWord(string $name, array $args, array $byName, array $active, bool $expandApps): array
    {
        $entry = $byName[$name];
        if (count($args) !== (int) $entry['arity']) {
            throw new WordCodecException("word {$name}: arity mismatch: " . count($args) . ' != ' . $entry['arity']);
        }
        if (in_array($name, $active, true)) {
            throw new WordCodecException("cyclic word dependency: {$name}");
        }
        $def = self::entryDefAst($entry, $byName, array_merge($active, [$name]), $expandApps);

        return self::substituteSlots($def, $args, $name);
    }

    /**
     * @param array{name: string, arity: int, genome: array, def?: array} $entry
     * @param array<string, array> $byName
     * @param list<string>         $active
     * @param bool                 $expandApps раскрывать ли born-op application ВНУТРИ definition
     *                                  (decodeExpanded: да; pattern-матчер encode: нет — паттерн faithful)
     *
     * @return array{op: string, l: array, r: array|null}|array{atom: string}
     */
    private static function entryDefAst(array $entry, array $byName, array $active, bool $expandApps): array
    {
        if (! $expandApps && isset($entry['def']) && is_array($entry['def'])) {
            return $entry['def'];
        }

        return self::decodeNode((array) $entry['genome'], $byName, $active, $expandApps);
    }

    /**
     * Одновременная подстановка слотов definition: xN → args[N]
     * (конвенция evalNode row[N] у born-операторов). Вставленные аргументы
     * НЕ ре-обходятся — capture невозможен.
     *
     * @param list<array> $args
     */
    private static function substituteSlots(array $def, array $args, string $wordName): array
    {
        $map = [];
        foreach (self::slotIndices($def) as $i) {
            if (! isset($args[$i]) || ! is_array($args[$i])) {
                throw new WordCodecException("word {$wordName}: слот x{$i} без аргумента");
            }
            $map['x' . $i] = $args[$i];
        }

        return self::substitute($def, $map);
    }

    /**
     * @param array<string, array> $map
     */
    private static function substitute(array $node, array $map): array
    {
        if (isset($node['atom'])) {
            $name = (string) $node['atom'];

            return array_key_exists($name, $map) ? self::cloneNode($map[$name]) : [
                'atom' => $name,
            ];
        }
        $l = isset($node['l']) && is_array($node['l']) ? self::substitute($node['l'], $map) : null;
        $r = isset($node['r']) && is_array($node['r']) ? self::substitute($node['r'], $map) : null;

        return [
            'op' => (string) $node['op'],
            'l' => $l,
            'r' => $r,
        ];
    }

    private static function cloneNode(array $node): array
    {
        if (isset($node['atom'])) {
            return [
                'atom' => (string) $node['atom'],
            ];
        }
        $l = isset($node['l']) && is_array($node['l']) ? self::cloneNode($node['l']) : null;
        $r = isset($node['r']) && is_array($node['r']) ? self::cloneNode($node['r']) : null;

        return [
            'op' => (string) $node['op'],
            'l' => $l,
            'r' => $r,
        ];
    }
}
