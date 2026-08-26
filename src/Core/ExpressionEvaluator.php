<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * SEARCH-TOP-K (05.08.2026): вычисление ВЫРАЖЕНИЙ по данным.
 *
 * Находка: LawValidator::evaluateHeldout использовал AtomRegistry::apply —
 * он понимает ТОЛЬКО атомы (имена операций), не выражения вида "(x0×K2)".
 * Held-out НИКОГДА не проверял выражения: всё не-атомарное → null → 9.99.
 * R-подгонки «ловились», потому что ловилось ВСЁ.
 *
 * ExpressionEvaluator: парсит формулу (ExpressionNormalizer::parse),
 * предвычисляет R-статистики по выборке, вычисляет вектор значений.
 * R-атомы на held-out считаются по ТЕСТОВОЙ выборке → R-подгонки
 * разваливаются (их reduce меняется), законы без R проходят.
 */
class ExpressionEvaluator
{
    /** B-AS-ARGUMENT: глубина рекурсии B-в-B (guard от циклов) */
    private static int $bDepth = 0;
    /** R-операторы в именах атомов: R+{col}, R×{col}, Rmax{col}, Rmin{col}, Rrange{col}, Rnorm{col} */
    private const R_OPS = ['range', 'norm', 'max', 'min', '×', '−', '/', '+'];

    /** @var array<string,array|null> кэш definition по имени атома */
    private static array $defCache = [];

    public static function clearDefCache(): void
    {
        self::$defCache = [];
    }

    private static function definition(string $atom): ?array
    {
        if (array_key_exists($atom, self::$defCache)) {
            return self::$defCache[$atom];
        }
        $db = \BeeSwarm\Infra\Database::get();
        $stmt = $db->prepare('SELECT definition FROM grammar_ops WHERE name = ? AND definition IS NOT NULL AND definition != \'\' LIMIT 1');
        $stmt->execute([$atom]);
        $def = $stmt->fetchColumn();
        if ($def === false) {
            self::$defCache[$atom] = null;
            return null;
        }
        [$protected, $map] = ExpressionNormalizer::protect((string) $def);
        $node = ExpressionNormalizer::parse($protected, []); // null-safe: нет B-контекста
        if ($node === null) {
            self::$defCache[$atom] = null;
            return null;
        }
        if (! empty($map)) {
            $node = ExpressionNormalizer::restoreAtoms($node, $map);
        }
        self::$defCache[$atom] = $node;
        return $node;
    }

    /**
     * Вычислить формулу по всем строкам. null если формула неприменима.
     */
    public static function evaluateFormula(string $formula, array $rows, ?array $stats = null, array $extraOps = [], array $opDefs = []): ?array
    {
        // R-атомы (R+x0) содержат операторы — защищаем как в normalize:
        // protect → parse → restore, иначе "R+x0" разбирается как R + x0
        // B-CULTURE-PARSE (26.08, EXP-029): B-атомы из БД = ОПЕРАТОРЫ парсера
        // (иначе (x1B1x2) → один атом 'x1B1x2' → definition('x1B1x2') → NULL
        // → вся culture-цепочка умирает на heldout!)
        $bNames = self::birthOpNames();
        $allExtraOps = array_merge($extraOps, $bNames);
        [$protected, $map] = ExpressionNormalizer::protect($formula);
        $node = ExpressionNormalizer::parse($protected, $allExtraOps);
        if ($node === null) {
            return null;
        }
        if (! empty($map)) {
            $node = ExpressionNormalizer::restoreAtoms($node, $map);
        }
        // CONCERNS (deleg_6ee92a50): переданные stats (по TRAIN) имеют приоритет
        // — R-атомы = константы модели, не пересчитываются по тестовой выборке
        $stats ??= self::collectReduceStats($node, $rows);
        $vec = [];
        foreach ($rows as $row) {
            $v = self::evalNode($node, $row, $stats, $opDefs);
            if ($v === null || ! is_finite($v)) {
                return null;
            }
            $vec[] = $v;
        }

        return $vec;
    }

    /**
     * Публичный: R-статистики формулы по выборке (для testCv — по TRAIN).
     */
    public static function collectStats(string $formula, array $rows, array $extraOps = [], array $opDefs = []): array
    {
        $allExtraOps = array_merge($extraOps, self::birthOpNames());
        [$protected, $map] = ExpressionNormalizer::protect($formula);
        $node = ExpressionNormalizer::parse($protected, $allExtraOps);
        if ($node === null) {
            return [];
        }
        if (! empty($map)) {
            $node = ExpressionNormalizer::restoreAtoms($node, $map);
        }

        return self::collectReduceStats($node, $rows);
    }

    /**
     * Предвычисление R-статистик по выборке (R+x0 = сумма колонки x0 и т.д.).
     */
    /**
     * REVIEW deleg_fe365da6: единая точка сброса static-кэшей.
     * Новый static-кэш добавлять СЮДА ЖЕ (иначе order-dependent флаки).
     */
    public static function resetCaches(): void
    {
        self::$birthOpCache = null;
        self::$birthOpSentinel = null;
        self::$defCache = [];
    }

    /**
     * B-CULTURE-PARSE (26.08): имена рождённых атомов (source='birth')
     * для парсера — иначе (x0B1x1) неразличим от неизвестного атома.
     */
    private static ?array $birthOpCache = null;
    private static ?int $birthOpSentinel = null;

    private static function birthOpNames(): array
    {
        // REVIEW deleg_fe365da6: межпроцессная сталесть — новые birth-атомы
        // из другого воркера не подхватятся. Сентинел COUNT(*) копеечный,
        // полный DISTINCT только при изменении.
        try {
            $cnt = (int) \BeeSwarm\Infra\Database::get()
                ->query("SELECT COUNT(*) FROM grammar_ops WHERE source = 'birth'")->fetchColumn();
            if (self::$birthOpCache !== null && self::$birthOpSentinel === $cnt) {
                return self::$birthOpCache;
            }
            self::$birthOpSentinel = $cnt;
        } catch (\Throwable) {
            // нет БД — отдаём кэш как есть (тесты без БД)
            return self::$birthOpCache ?? [];
        }
        $names = [];
        try {
            $stmt = \BeeSwarm\Infra\Database::get()->prepare(
                "SELECT DISTINCT name FROM grammar_ops WHERE source = 'birth' AND name LIKE 'B%'"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $r) {
                $names[] = $r['name'];
            }
        } catch (\Throwable) {
            // нет таблицы/БД — пусто (тесты без БД)
        }
        self::$birthOpCache = $names;
        return $names;
    }

    private static function collectReduceStats(array $node, array $rows): array
    {
        $stats = [];
        $walk = function (array $n) use (&$walk, &$stats, $rows): void {
            if (isset($n['atom']) && str_starts_with($n['atom'], 'R')) {
                foreach (self::R_OPS as $rop) {
                    if (str_starts_with($n['atom'], "R{$rop}")) {
                        $colName = substr($n['atom'], 1 + strlen($rop));
                        $col = self::column($rows, $colName);
                        if ($col === null) {
                            break;
                        }
                        // norm: НЕ класть null (isset(null) убивает evaluateFormula!).
                        // Собираем Rmin/Rrange для поточечной ветки Rnorm в evalNode.
                        if ($rop === 'norm') {
                            $stats["Rmin{$colName}"] = min($col);
                            $stats["Rrange{$colName}"] = max($col) - min($col);
                        } else {
                            $stats[$n['atom']] = match ($rop) {
                                '+' => array_sum($col),
                                '×' => array_product($col),
                                'max' => max($col),
                                'min' => min($col),
                                'range' => max($col) - min($col),
                                default => null,
                            };
                        }
                        break;
                    }
                }
            }
            if (isset($n['l'])) {
                $walk($n['l']);
            }
            if (isset($n['r'])) {
                $walk($n['r']);
            }
        };
        $walk($node);

        return $stats;
    }

    private static function column(array $rows, string $colName): ?array
    {
        if (preg_match('/^x(\d+)$/', $colName, $m)) {
            $idx = (int) $m[1];
            $col = [];
            foreach ($rows as $row) {
                $col[] = (float) ($row[$idx] ?? 0);
            }

            return $col;
        }

        return null;
    }

    public static function evalNode(array $node, array $row, array $stats, array $opDefs = []): ?float
    {
        if (isset($node['atom'])) {
            return self::evalAtom($node['atom'], $row, $stats);
        }
        $op = $node['op'] ?? null;
        $l = isset($node['l']) ? self::evalNode($node['l'], $row, $stats, $opDefs) : null;
        $r = isset($node['r']) ? self::evalNode($node['r'], $row, $stats, $opDefs) : null;
        // B-AS-ARGUMENT (09.08): рождённый атом — вычислить definition
        // с аргументами [l, r] (x0→l, x1→r). DEPTH-GUARD (CONCERNS
        // deleg_c1b509c5): вложенные B (B-в-B) и self-reference — лимит 10,
        // иначе цикл/бесконечность.
        // B-CULTURE (26.08): B-оп без opDefs — подтянуть definition из БД.
        // definition() возвращает ГОТОВЫЙ node — вычисляем рекурсивно.
        // REVIEW deleg_fe365da6: regex /^B\d+$/ (было /^B/i — ложные
        // срабатывания на будущих операторах вида Bessel/Beta).
        $birthNames = self::birthOpNames();
        if ($op !== null && ! isset($opDefs[$op])
            && (preg_match('/^B\\d+$/', $op) || in_array($op, $birthNames, true))) {
            $defNode = self::definition($op);
            if ($defNode !== null && isset($defNode['op'])) { // битый node → отказ
                if ($l === null || $r === null) {
                    return null;
                }
                self::$bDepth++;
                try {
                    // definition (x0 op x1): атомы x0/x1 → row[0]/row[1]
                    return self::evalNode($defNode, [$l, $r], $stats, $opDefs);
                } finally {
                    self::$bDepth--;
                }
            }
        }
        if ($op !== null && isset($opDefs[$op])) {
            if ($l === null || $r === null) {
                return null;
            }
            if (self::$bDepth >= 10) {
                return null;
            }
            self::$bDepth++;
            try {
                $def = $opDefs[$op];
                $sub = self::evaluateFormula($def, [[$l, $r]], $stats, array_keys($opDefs), $opDefs);
                return $sub[0] ?? null;
            } finally {
                self::$bDepth--;
            }
        }

        return match ($op) {
            '+', 'add' => ($l !== null && $r !== null) ? $l + $r : null,
            '−', 'sub' => ($l !== null && $r !== null) ? $l - $r : null,
            '×', 'mul' => ($l !== null && $r !== null) ? $l * $r : null,
            '/', 'div' => ($l !== null && $r !== null && $r != 0) ? $l / $r : null,
            'max' => ($l !== null && $r !== null) ? max($l, $r) : null,
            'min' => ($l !== null && $r !== null) ? min($l, $r) : null,
            'sq' => $l !== null ? $l * $l : null,
            'cube' => $l !== null ? $l * $l * $l : null,
            'sqrt' => ($l !== null && $l >= 0) ? sqrt($l) : null,
            'neg' => $l !== null ? -$l : null,
            'inv', 'inverse' => ($l !== null && $l != 0) ? 1.0 / $l : null,
            'abs' => $l !== null ? abs($l) : null,
            'log2' => $l !== null ? log(max($l, 0.001)) / log(2) : null,
            'parity' => $l !== null ? (((int) $l % 2 === 0) ? 1.0 : -1.0) : null,
            default => null,
        };
    }

    private static function evalAtom(string $atom, array $row, array $stats, array $opDefs = []): ?float
    {
        if (preg_match('/^x(\d+)$/', $atom, $m)) {
            return (float) ($row[(int) $m[1]] ?? 0);
        }
        if ($atom === 'K1') {
            return 1.0;
        }
        if ($atom === 'K2') {
            return 2.0;
        }
        if (is_numeric($atom)) {
            return (float) $atom;
        }
        if (isset($stats[$atom])) {
            return $stats[$atom];
        }
        // GRAMMAR-BIRTH (ЭКСП-015): атом с definition (source='birth')
        $def = self::definition($atom);
        if ($def !== null) {
            return self::evalNode($def, $row, $stats, $opDefs);
        }

        // Rnorm{col}: (x - min) / range — поточечно, нужен контекст строки
        if (str_starts_with($atom, 'Rnorm')) {
            $colName = substr($atom, 5);
            $col = self::column([$row], $colName);
            $min = null;
            $range = null;
            foreach ($stats as $k => $v) {
                if (str_starts_with($k, "Rmin{$colName}")) {
                    $min = $v;
                }
                if (str_starts_with($k, "Rrange{$colName}")) {
                    $range = $v;
                }
            }
            if ($col === null || $min === null || $range === null || $range == 0) {
                return null;
            }

            return ($col[0] - $min) / $range;
        }

        return null;
    }
}
