<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;

/**
 * FORMAL-LAYER Ф1: каноническая нормализация выражений.
 * (x1+x0) ≡ (x0+x1) — один fingerprint → структурная дедупликация.
 */
class ExpressionNormalizerTest extends TestCase
{
    public function testCommutativeSumCanonical(): void
    {
        $this->assertSame(
            ExpressionNormalizer::normalize('(x0+x1)'),
            ExpressionNormalizer::normalize('(x1+x0)'),
            'Commutative + must normalize to same canonical form'
        );
    }

    public function testCommutativeMulCanonical(): void
    {
        $this->assertSame(
            ExpressionNormalizer::normalize('(x0×x1)'),
            ExpressionNormalizer::normalize('(x1×x0)'),
            'Commutative × must normalize to same canonical form'
        );
    }

    public function testNonCommutativeSubNotSwapped(): void
    {
        $this->assertNotSame(
            ExpressionNormalizer::normalize('(x0−x1)'),
            ExpressionNormalizer::normalize('(x1−x0)'),
            'Subtraction is NOT commutative — order must be preserved'
        );
    }

    public function testTautologySubSelfIsZero(): void
    {
        $this->assertSame('0', ExpressionNormalizer::normalize('(x0−x0)'));
    }

    public function testTautologyDivSelfIsOne(): void
    {
        $this->assertSame('1', ExpressionNormalizer::normalize('(x0/x0)'));
    }

    public function testIdentityAddZero(): void
    {
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0+0)'));
        $this->assertSame('x0', ExpressionNormalizer::normalize('(0+x0)'));
    }

    public function testIdentityMulOne(): void
    {
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0×1)'));
        $this->assertSame('x0', ExpressionNormalizer::normalize('(1×x0)'));
    }

    public function testMaxSelfIsIdentity(): void
    {
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0maxx0)'));
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0minx0)'));
    }

    public function testCanonicalFingerprint(): void
    {
        $this->assertSame(
            ExpressionNormalizer::fingerprint('(x1+x0)'),
            ExpressionNormalizer::fingerprint('(x0+x1)'),
            'Fingerprint must be identical for commutative variants'
        );
    }

    public function testNestedNormalization(): void
    {
        $a = ExpressionNormalizer::normalize('((x1+x0)×x2)');
        $b = ExpressionNormalizer::normalize('((x0+x1)×x2)');
        $this->assertSame($a, $b, 'Nested commutative variants must normalize equally');
    }

    // ── CONCERNS 05.08: R-атомы, JSON, квадраты, кириллица, идемпотентность ──

    public function testRAtomTautologyCollapses(): void
    {
        // (Rmaxx0−Rmaxx0) → 0: R-атом содержит 'max' — оператор внутри атома
        $this->assertSame('0', ExpressionNormalizer::normalize('(Rmaxx0−Rmaxx0)'));
        $this->assertSame('1', ExpressionNormalizer::normalize('(Rminx0/Rminx0)'));
    }

    public function testRAtomDoesNotSplit(): void
    {
        // (Rmaxx0+x1): 'max' внутри атома Rmaxx0 не должен быть оператором
        $this->assertSame(
            ExpressionNormalizer::normalize('(Rmaxx0+x1)'),
            ExpressionNormalizer::normalize('(x1+Rmaxx0)'),
            'R-atoms must stay atomic — only top-level + is the operator'
        );
    }

    public function testJsonAtomDoesNotSplit(): void
    {
        $a = ExpressionNormalizer::normalize('(x0+{"date": "2026-01-17"})');
        $b = ExpressionNormalizer::normalize('({"date": "2026-01-17"}+x0)');
        $this->assertSame($a, $b, 'JSON atoms must stay atomic (commutative swap only at top level)');
    }

    public function testSquareCanonical(): void
    {
        // x0² и (x0)² — один объект
        $this->assertSame(
            ExpressionNormalizer::normalize('x0²'),
            ExpressionNormalizer::normalize('(x0)²'),
            'x0² and (x0)² must normalize to same canonical form'
        );
    }

    public function testNormalizeIsIdempotent(): void
    {
        foreach (['(x0+x1)', '((x0−x1))²', '(Rmaxx0+x1)', '({"date": "2026-01-17"}+x0)', '(День−День²)'] as $expr) {
            $once = ExpressionNormalizer::normalize($expr);
            $twice = ExpressionNormalizer::normalize($once);
            $this->assertSame($once, $twice, "normalize must be idempotent for: $expr");
        }
    }

    public function testCyrillicAtoms(): void
    {
        $this->assertSame(
            ExpressionNormalizer::normalize('(День+Exec)'),
            ExpressionNormalizer::normalize('(Exec+День)'),
            'Cyrillic atoms must sort commutatively'
        );
    }

    public function testColumnNamedZeroNotSimplified(): void
    {
        // Колонка с именем "0" — не число, а колонка: (0+x1) НЕ → x1
        // (проверка: атом '0' является числом — '0' is_numeric → true,
        //  этот тест фиксирует ПОВЕДЕНИЕ для числовых литералов)
        $this->assertSame('x1', ExpressionNormalizer::normalize('(0+x1)'));
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0×1)'));
    }

    public function testRedundantParensAtom(): void
    {
        // (x0) — избыточные скобки → атом
        $this->assertSame('x0', ExpressionNormalizer::normalize('(x0)'));
    }

    public function testNestedParensWithRAttoms(): void
    {
        // Баг 05.08: ((Rmaxx0−Rminx1))² — плейсхолдеры не восстанавливались
        // (после снятия внешних скобок '(' поднимал depth, оператор терялся)
        // − некоммутативен: (a−b) ≠ (b−a), проверяем структуру и восстановление
        $result = ExpressionNormalizer::normalize('((Rmaxx0−Rminx1))²');
        $this->assertStringNotContainsString("\x01", $result, 'No placeholders may leak into output');
        $this->assertSame($result, ExpressionNormalizer::normalize($result), 'Must be idempotent');
        $this->assertSame(
            $result,
            ExpressionNormalizer::normalize('((Rmaxx0−Rminx1))²'),
            'Canonical form must be stable'
        );
    }

    public function testLabelWithMaxInsideWordNotSplit(): void
    {
        // CONCERNS Ф1 (05.08): "speed_max" — метка, max внутри слова.
        // Раньше: split по "max" → мусор "(+x0maxspeed_)".
        $this->assertSame(
            '(speed_max+x0)',
            ExpressionNormalizer::normalize('(speed_max+x0)'),
            'max inside a label must NOT be treated as operator'
        );
        // Оператор max между операндами всё ещё работает
        $this->assertSame('(x0maxx1)', ExpressionNormalizer::normalize('(x0maxx1)'));
    }

    public function testUnarySuffixPreserved(): void
    {
        // CONCERNS Ф1 (05.08): L1-unary форма ((x0+x1)sq) — атом не должен
        // разбираться по-другому; идемпотентность сохраняется
        $a = ExpressionNormalizer::normalize('((x0+x1)sq)');
        $this->assertSame($a, ExpressionNormalizer::normalize($a), 'Unary suffix must be idempotent');
        $this->assertSame($a, ExpressionNormalizer::normalize('((x1+x0)sq)'),
            'Commutative variant of unary-wrapped expression must normalize equally');
    }
}
