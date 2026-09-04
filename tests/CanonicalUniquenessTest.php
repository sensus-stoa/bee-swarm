<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * T2 (story theorem-level) Phase 2a: единственность канонизации —
 * закрываемые контрпример-классы.
 *
 * Канон = слово языка роя (§3.8). Один класс эквивалентности обязан
 * давать ровно один канон — иначе словарь получает слова-дубли
 * (usage_count дробится, compose переиспользует разные имена).
 *
 * НОТАЦИЯ РОЯ — инфиксная: (x0maxx1), НЕ функциональная max(x0,x1)
 * (функциональная не язык роя, parse честно возвращает atom).
 *
 * Класс A: инфиксные self-tautology max/min — УЖЕ работают (covered-тест).
 * Класс B: правые идентичности (−0, /1) — НЕ покрыты (новые правила).
 * Ассоциативность — отдельная сторя T2b (flatten N-арного дерева).
 */
final class CanonicalUniquenessTest extends TestCase
{
    /** A-covered: инфиксный self-tautology max — один канон. */
    public function testMaxSelfTautologyCollapses(): void
    {
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0maxx0)'));
    }

    /** A-covered: min аналог. */
    public function testMinSelfTautologyCollapses(): void
    {
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0minx0)'));
    }

    /** B: (x0−0) → x0 — правая нулевая идентичность вычитания (RED). */
    public function testSubZeroRightIdentityCollapses(): void
    {
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0−0)'));
    }

    /** B: (x0/1) → x0 — правая единичная идентичность деления (RED). */
    public function testDivOneRightIdentityCollapses(): void
    {
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0/1)'));
    }

    /** Идемпотентность канона после новых правил (питфолл 05.08). */
    public function testNormalizeIdempotentOnNewRules(): void
    {
        foreach (['(x0−0)', '(x0/1)', '(x0maxx0)'] as $expr) {
            $once = ExpressionNormalizer::normalize($expr);
            self::assertSame(
                $once,
                ExpressionNormalizer::normalize($once),
                "normalize неидемпотентен на {$expr}"
            );
        }
    }

    /** НЕ схлопываются (некоммутативные — правильное поведение, 05.08): */
    public function testNonCommutativeNotMerged(): void
    {
        self::assertNotSame(
            ExpressionNormalizer::normalize('(x0−x1)'),
            ExpressionNormalizer::normalize('(x1−x0)')
        );
    }
}
