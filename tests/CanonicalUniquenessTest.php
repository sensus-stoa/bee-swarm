<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\LawShape;
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

    /** T2-review (deleg_79f23159): 0/0 НЕ схлопывается в 1 — математически NaN. */
    public function testZeroOverZeroDoesNotCollapseToOne(): void
    {
        // ((x0−x0)/(x0−x0)): оба операнда резолвятся в 0, 0/0 ≠ 1
        $this->assertNotSame(
            '1',
            ExpressionNormalizer::normalize('((x0−x0)/(x0−x0))'),
            '0/0 → 1 = математическая ошибка (NaN трактован как единица)'
        );
    }

    /** 0/x → 0 (валидный случай, остаётся). */
    public function testZeroOverNonZeroCollapses(): void
    {
        $this->assertSame('0', ExpressionNormalizer::normalize('(0/x0)'));
    }

    /** LawShape K-константы за K9 (premortem deleg_f0b2fe04): K10/K11/K20 — все C. */
    public function testLawShapeKConstantsBeyondNineStable(): void
    {
        $this->assertSame(0, LawShape::distance('K10×x0', 'K11×x0'), 'K10 и K11 — одна форма (C)');
        $this->assertSame(0, LawShape::distance('K20×x0', 'K10×x0'), 'K20 и K10 — одна форма');
        $this->assertSame('(C×*)', LawShape::of('K10×x0'), 'маска K10 стабильна, не C0');
    }

    /** LawShape: атомы с цифрами не сливаются (review concern 2, регресс). */
    public function testLawShapeDigitsInsideAtomsNotMerged(): void
    {
        $this->assertSame(1, LawShape::distance('c2_5', 'c3_7'));
        $this->assertSame(1, LawShape::distance('Rnormx12', 'Rnormx13'));
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
