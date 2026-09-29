<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * DREAM-CONGRUENCE (29.09): нотационный мост compose-форм.
 *
 * discoverCompose пишет функциональную нотацию 'outer(inner)' (sq(+)).
 * Это НЕ язык роя: ExpressionNormalizer::parse возвращает атом-строку,
 * evaluator — null → детектор конгруэнтности, LawClassifier и
 * rewardDiscovery (hasFeatures) мертвы для compose-форм.
 *
 * Мост переводит функциональную форму в инфиксную по СЕМАНТИКЕ
 * AtomProvider::discoverCompose (probe 29.09, файл:строка):
 *  - unary outer (sq): applyToRow(inner) → outer(v)
 *    → sq(+) === ((x0+x1))² при inner='+' (binary inner на строке
 *    считается add(x0,x1) — AtomProvider nFeat>=2 ветка);
 *  - binary outer (×/+/−//): apply(outer, v1, row[1]) при nFeat=2,
 *    apply(outer, v1, row[2]) при nFeat>=3 → мост ЗАВИСИТ от nFeat,
 *    для nFeat>=3 форма неоднозначна → бинарный outer НЕ мостится
 *    (fail-closed: форма остаётся как есть, поглощение — как сегодня).
 *
 * F1b (agent-review 29.09, f1n-проба): ТОЛЬКО sq-суффикс (²) живёт в
 * ExpressionNormalizer. Суффиксы sqrt/parity/log2/abs/neg/inv парсятся,
 * evaluator их считает, НО simplify() падает TypeError (unary node
 * с r=null) → RecordKeeper::record:547 = FATAL записи. Мост сужен до
 * 'sq' — остальные унарные fail-closed: форма остаётся атомом
 * (мёртвый закон, F1a), что безопаснее краша записи.
 *
 * F4 (agent-review): 'pow'/'mod' убраны из BINARY_INFIX — инфиксных
 * токенов (x0powx1)/(x0modx1) в языке роя НЕ СУЩЕСТВУЕТ (evaluator
 * знает только +,×,−,/,max,min,sq,sqrt,...) → мёртвый синтаксис.
 *
 * Правило протокола: фиксстуры и законы — на языке роя (инфикс).
 * Мост = переводчик на границе dream-пути, не новый синтаксис парсера.
 */
final class ComposeBridge
{
    /**
     * Инфиксное выражение для inner-имени на строке данных.
     * binary inner на 2 фичах: apply(inner, row[0], row[1]) → (x0 op x1).
     * nFeat>=3 ИЗМЕНИЛ бы семантику для некоторых inner (apply берёт
     * row[1] всегда в applyToRow — семантика nFeat-независима для binary).
     */
    private const BINARY_INFIX = [
        '+' => '(x0+x1)',
        'add' => '(x0+x1)',
        '×' => '(x0×x1)',
        'mul' => '(x0×x1)',
        '−' => '(x0−x1)',
        'sub' => '(x0−x1)',
        '/' => '(x0/x1)',
        'div' => '(x0/x1)',
        'min' => '(x0minx1)',
        'max' => '(x0maxx1)',
    ];

    /**
     * Унарные опы, чей суффикс ЖИВЁТ в normalize (f1n-матрица 29.09:
     * только 'sq' проходит normalize без TypeError). Остальные унарные
     * (sqrt/abs/... даже с суффикс-парсингом) fail-closed → null.
     */
    private const SUFFIXED_UNARY = [
        'sq' => '²',
    ];

    /**
     * Унарный мост: 'sq(+)' → '((x0+x1))²'.
     * Возвращает null для форм вне моста (binary outer, мусор,
     * суффиксы вне normalize-живых).
     */
    public static function toInfix(string $atom): ?string
    {
        if (preg_match('/^([A-Za-z0-9]+)\((.+)\)$/', $atom, $m) !== 1) {
            return null;
        }
        $outer = $m[1];
        $inner = $m[2];

        // Binary outer не мостится: семантика зависит от nFeat (probe 29.09).
        if (! isset(self::SUFFIXED_UNARY[$outer])) {
            return null;
        }

        // F3 (agent-review): inner — ИМЯ опа (из discoverCompose) или уже
        // инфикс с фичами. Скобочный inner ('add(x0') = артефакт сериализации,
        // не имя → отказ, чтобы не плодить несбалансированные скобки.
        if (isset(self::BINARY_INFIX[$inner])) {
            $innerExpr = self::BINARY_INFIX[$inner];
        } elseif (str_contains($inner, 'x') && ! str_contains($inner, '(') && ! str_contains($inner, ')')) {
            $innerExpr = $inner;
        } else {
            return null;
        }

        $suffix = self::SUFFIXED_UNARY[$outer];

        return '(' . $innerExpr . ')' . $suffix;
    }
}
