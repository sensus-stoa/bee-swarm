<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * T2b (story theorem-level): ассоциативность коммутативных операций.
 *
 * ((x0+x1)+x2) и (x0+(x1+x2)) — одна функция (ассоциативность +),
 * но канонизация до flatten давала РАЗНЫЕ слова (эмпирика 04.09) —
 * слово-дубль в словаре. Фикс: flatten N-арных цепочек перед сортировкой.
 *
 * Правило: flatten только для КОММУТАТИВНЫХ операторов (+, ×, max, min).
 * − и / ассоциативны ТОЛЬКО слева (x−y−z ≡ (x−y)−z), правая группировка —
 * другая функция. Для × правая группировка с делением — тоже другая (a/(b/c)).
 */
final class AssociativityCanonicalTest extends TestCase
{
    /** Сложение: обе группировки → один канон. */
    public function testAddAssociativityOneCanon(): void
    {
        $left = ExpressionNormalizer::normalize('((x0+x1)+x2)');
        $right = ExpressionNormalizer::normalize('(x0+(x1+x2))');
        $this->assertSame($left, $right, "лево=$left право=$right");
    }

    /** Умножение: обе группировки → один канон. */
    public function testMulAssociativityOneCanon(): void
    {
        $left = ExpressionNormalizer::normalize('((x0×x1)×x2)');
        $right = ExpressionNormalizer::normalize('(x0×(x1×x2))');
        $this->assertSame($left, $right, "лево=$left право=$right");
    }

    /** Вычитание: НЕ ассоциативно — группировки обязаны остаться разными. */
    public function testSubAssociativityNotMerged(): void
    {
        $left = ExpressionNormalizer::normalize('((x0−x1)−x2)');
        $right = ExpressionNormalizer::normalize('(x0−(x1−x2))');
        $this->assertNotSame($left, $right, '− не ассоциативно: группировки — разные функции');
    }

    /** Деление: НЕ ассоциативно справа. */
    public function testDivAssociativityNotMerged(): void
    {
        $left = ExpressionNormalizer::normalize('((x0/x1)/x2)');
        $right = ExpressionNormalizer::normalize('(x0/(x1/x2))');
        $this->assertNotSame($left, $right, '/ справа-группировка — другая функция');
    }

    /** Идемпотентность: канон ассоциативной цепочки стабилен. */
    public function testCanonIdempotentAfterFlatten(): void
    {
        $once = ExpressionNormalizer::normalize('(x0+(x1+(x2+x3)))');
        $this->assertSame($once, ExpressionNormalizer::normalize($once));
    }

    /** Перемешивание группировок (6 перестановок ассоц-цепочки) → один канон. */
    public function testAllGroupingsOneCanon(): void
    {
        $canons = [
            ExpressionNormalizer::normalize('((x0+x1)+x2)'),
            ExpressionNormalizer::normalize('(x0+(x1+x2))'),
            ExpressionNormalizer::normalize('((x0+x2)+x1)'),
            ExpressionNormalizer::normalize('(x0+(x2+x1))'),
            ExpressionNormalizer::normalize('((x1+x0)+x2)'),
            ExpressionNormalizer::normalize('(x1+(x0+x2))'),
        ];
        $this->assertCount(1, array_unique($canons), 'каноны: ' . implode(' | ', $canons));
    }
}
