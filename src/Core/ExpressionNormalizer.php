<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * FORMAL-LAYER Ф1: каноническая нормализация выражений.
 *
 * (x1+x0) ≡ (x0+x1) — один канон → структурная дедупликация законов.
 * Тавтологии: (x−x)→0, (x/x)→1, (x max x)→x, (x min x)→x.
 * Тождества: (x+0)→x, (x×1)→x, (x×0)→0.
 *
 * Защита атомов (CONCERNS 05.08): R-атомы (Rmaxx0, R+x0) и JSON-блоки
 * содержат операторные подстроки — перед парсингом заменяются на
 * плейсхолдеры, операторы внутри атомов не ломают разбор.
 */
class ExpressionNormalizer
{
    /** Коммутативные бинарные операции — операнды сортируются. */
    private const COMMUTATIVE = ['+', '×', 'max', 'min'];

    /** Все бинарные операторы (длинные первыми для парсинга). */
    private const BINARY_OPS = ['max', 'min', '×', '−', '/', '+'];

    /** R-префиксы (reduce-атомы из Search::find) — длинные первыми. */
    private const R_PREFIXES = ['range', 'norm', 'max', 'min', '×', '−', '/', '+'];

    /** Unary-суффиксы в L1-unary форме: ((x0+x1)sq). */
    private const UNARY_SUFFIXES = ['sqrt', 'parity', 'log2', 'abs', 'neg', 'inv', 'sq'];

    /**
     * Каноническая форма выражения.
     */
    public static function normalize(string $expr): string
    {
        [$protected, $map] = self::protect($expr);
        $node = self::parse($protected);
        if ($node === null) {
            return $expr;
        }
        $node = self::restoreAtoms($node, $map);
        $node = self::simplify($node);
        return self::render($node);
    }

    /**
     * Восстановить плейсхолдеры в атомах (JSON/R-атомы) после парсинга.
     *
     * @param array $node
     * @param array<string, string> $map
     * @return array
     */
    public static function restoreAtoms(array $node, array $map): array
    {
        if (isset($node['atom'])) {
            if (isset($map[$node['atom']])) {
                $node['atom'] = $map[$node['atom']];
            }
            return $node;
        }
        if ($node['op'] === 'sq') {
            $node['l'] = self::restoreAtoms($node['l'], $map);
            return $node;
        }
        $node['l'] = self::restoreAtoms($node['l'], $map);
        // Унарные (neg, inv, sqrt...) имеют r=null — не рекурсировать
        if ($node['r'] !== null) {
            $node['r'] = self::restoreAtoms($node['r'], $map);
        }
        return $node;
    }

    /**
     * Структурный fingerprint — каноническая форма для дедупликации.
     */
    public static function fingerprint(string $expr): string
    {
        return self::normalize($expr);
    }

    /**
     * Защита атомов: JSON-блоки и R-атомы → плейсхолдеры.
     *
     * @return array{0: string, 1: array<string, string>} [protected, map placeholder=>original]
     */
    public static function protect(string $expr): array
    {
        $map = [];
        $n = 0;

        // 1. JSON-блоки {...} (без вложенности в нашем формате)
        $expr = preg_replace_callback('/\{[^{}]*\}/', function ($m) use (&$map, &$n) {
            $key = "\x01J{$n}\x01";
            $map[$key] = $m[0];
            $n++;
            return $key;
        }, $expr);

        // 2. R-атомы: R + префикс + имя (после JSON-защиты имя — без скобок)
        $expr = preg_replace_callback('/R(?:range|norm|max|min|[+×−\/])([A-Za-zА-Яа-яЁё0-9_]+)/', function ($m) use (&$map, &$n) {
            $key = "\x01R{$n}\x01";
            $map[$key] = $m[0];
            $n++;
            return $key;
        }, $expr);

        return [$expr, $map];
    }

    /**
     * @return array{op: string, l: array, r: array}|array{atom: string}|null
     */
    public static function parse(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }

        // Унарный суффикс: ((x0+x1)sq) — L1-unary форма (CONCERNS Ф1 05.08)
        foreach (self::UNARY_SUFFIXES as $suffix) {
            if (str_ends_with($expr, $suffix) && str_starts_with($expr, '(')) {
                $inner = substr($expr, 0, -strlen($suffix));
                if (! str_starts_with($inner, '(') || ! str_ends_with($inner, ')')) {
                    continue; // не обёртка: col1sq — атом
                }
                $parsed = self::parse($inner);
                if ($parsed !== null) {
                    return ['op' => $suffix, 'l' => $parsed, 'r' => null];
                }
            }
        }

        // Унарный квадрат: (X)² или X² (² — 2 байта UTF-8, нужен mb_substr)
        if (mb_substr($expr, -1, 1) === '²') {
            $inner = mb_substr($expr, 0, -1);
            $parsed = self::parse($inner);
            if ($parsed === null) {
                return null;
            }
            return ['op' => 'sq', 'l' => $parsed, 'r' => null];
        }

        // Атом: нет внешних скобок
        if (! str_starts_with($expr, '(') || ! str_ends_with($expr, ')')) {
            return ['atom' => $expr];
        }

        // Внутренность внешних скобок
        $inner = substr($expr, 1, -1);

        // Вложенные скобки — рекурсивно разбиваем по оператору верхнего уровня
        $depth = 0;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($depth === 0) {
                // На верхнем уровне — ищем оператор.
                // Токен-граница ТОЛЬКО для мультисимвольных max/min
                // (CONCERNS Ф1 05.08): "speed_max+x0" не должен расколоться
                // по "max" внутри слова. Одиночные +−×/ — безопасны
                // (R-префиксы и JSON уже защищены protect()).
                foreach (self::BINARY_OPS as $op) {
                    if (str_starts_with(substr($inner, $i), $op)) {
                        if (strlen($op) > 1 && $i > 0
                            && preg_match('/[A-Za-z_]/', $inner[$i - 1])) {
                            continue; // "speed_max" — подстрока, не оператор
                        }
                        $left = substr($inner, 0, $i);
                        $right = substr($inner, $i + strlen($op));
                        if ($left === '' || $right === '') {
                            continue;
                        }
                        return [
                            'op' => $op,
                            'l' => self::parse($left) ?? ['atom' => $left],
                            'r' => self::parse($right) ?? ['atom' => $right],
                        ];
                    }
                }
            }
        }

        // Нет оператора на верхнем уровне — inner может быть вложенным
        // выражением: "((x0−x1))" → "(x0−x1)" → распарсить рекурсивно.
        // Или с суффиксом: "(x0+x0)²" → ²-квадрат (SEARCH-TOP-K 05.08)
        if ((str_starts_with($inner, '(') && str_ends_with($inner, ')'))
            || mb_substr($inner, -1, 1) === '²') {
            return self::parse($inner) ?? ['atom' => $inner];
        }
        // Unary-суффикс внутри внешних скобок: "((x0+x1)sq)" → sq("(x0+x1)")
        foreach (self::UNARY_SUFFIXES as $suffix) {
            if (str_ends_with($inner, $suffix) && str_starts_with($inner, '(')
                && str_ends_with(substr($inner, 0, -strlen($suffix)), ')')) {
                $parsed = self::parse(substr($inner, 0, -strlen($suffix)));
                if ($parsed !== null) {
                    return ['op' => $suffix, 'l' => $parsed, 'r' => null];
                }
            }
        }
        // inner содержит оператор-подстроку, но не на верхнем уровне
        // (например, "speed_max+x0" — max внутри слова) → атом С исходными
        // скобками, чтобы не потерять структуру (CONCERNS Ф1 05.08)
        if (preg_match('/[+×−\/]|max|min/', $inner)) {
            return ['atom' => $expr];
        }
        // Атом в избыточных скобках: (x0) → x0
        return ['atom' => $inner];
    }

    /**
     * Упрощение AST: тавтологии, тождества, сортировка коммутативных операндов.
     *
     * @param array $node
     * @return array
     */
    private static function simplify(array $node): array
    {
        if (isset($node['atom'])) {
            return $node;
        }

        $op = $node['op'];
        if ($op === 'sq') {
            $node['l'] = self::simplify($node['l']);
            return $node;
        }

        $l = self::simplify($node['l']);
        $r = self::simplify($node['r']);

        [$l, $r] = self::sortCommutative($op, $l, $r);

        $simplified = self::applyTautology($op, $l, $r);
        if ($simplified !== null) {
            return $simplified;
        }

        $simplified = self::applyIdentity($op, $l, $r);
        if ($simplified !== null) {
            return $simplified;
        }

        return ['op' => $op, 'l' => $l, 'r' => $r];
    }

    /**
     * Коммутативные операции: сортировка операндов по канонической строке.
     *
     * @return array{0: array, 1: array}
     */
    private static function sortCommutative(string $op, array $l, array $r): array
    {
        if (! in_array($op, self::COMMUTATIVE, true)) {
            return [$l, $r];
        }
        $ls = self::render($l);
        $rs = self::render($r);
        if ($ls !== $rs && strcmp($ls, $rs) > 0) {
            return [$r, $l];
        }
        return [$l, $r];
    }

    /**
     * Тавтологии: (x−x)→0, (x/x)→1, (x max x)→x, (x min x)→x.
     *
     * @return array|null упрощённый узел или null если тавтологии нет
     */
    private static function applyTautology(string $op, array $l, array $r): ?array
    {
        if (self::render($l) !== self::render($r)) {
            return null;
        }
        return match ($op) {
            '−' => ['atom' => '0'],
            '/' => ['atom' => '1'],
            'max', 'min' => $l,
            default => null,
        };
    }

    /**
     * Тождества: (x+0)→x, (x×1)→x, (x×0)→0.
     * Число-проверка: '0'/'1' упрощают ТОЛЬКО если атом — число,
     * а не колонка с именем "0" (CONCERNS 05.08).
     *
     * @return array|null упрощённый узел или null если тождества нет
     */
    private static function resolveK(string $s): string
    {
        return match ($s) {
            'K1' => '1',
            'K2' => '2',
            default => $s,
        };
    }

    private static function applyIdentity(string $op, array $l, array $r): ?array
    {
        $ls = self::render($l);
        $rs = self::render($r);
        // LAW-CLASS (08.08): K1 ≡ 1.0, K2 ≡ 2.0 — константы грамматики
        $ls = self::resolveK($ls);
        $rs = self::resolveK($rs);
        $lIsZero = is_numeric($ls) && (float) $ls === 0.0;
        $rIsZero = is_numeric($rs) && (float) $rs === 0.0;
        $lIsOne = is_numeric($ls) && (float) $ls === 1.0;
        $rIsOne = is_numeric($rs) && (float) $rs === 1.0;
        // Тождества
        if ($op === '+') {
            if ($lIsZero) {
                return $r;
            }
            if ($rIsZero) {
                return $l;
            }
        }
        if ($op === '×') {
            if ($lIsZero || $rIsZero) {
                return ['atom' => '0'];
            }
            if ($lIsOne) {
                return $r;
            }
            if ($rIsOne) {
                return $l;
            }
            // Сокращение деления (05.08, R-тавтологии):
            // (R×x) × (x/R×x) = x; (x/R×x) × (R×x) = x
            $lDiv = $l['op'] ?? null;
            $rDiv = $r['op'] ?? null;
            if ($lDiv === '/' && self::render($l['r'] ?? []) === $rs) {
                return $l['l'];
            }
            if ($rDiv === '/' && self::render($r['r'] ?? []) === $ls) {
                return $r['l'];
            }
        }
        return null;
    }

    /**
     * @param array $node
     */
    private static function render(array $node): string
    {
        if (isset($node['atom'])) {
            return $node['atom'];
        }
        if ($node['op'] === 'sq') {
            return '(' . self::render($node['l']) . ')²';
        }
        return '(' . self::render($node['l']) . $node['op'] . self::render($node['r']) . ')';
    }
}
