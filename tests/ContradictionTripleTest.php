<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\ContradictionEngine;
use PHPUnit\Framework\TestCase;

/**
 * V0.13 WU-4: двухслойная запись закона — тройка (shape, m̂, sign).
 *
 * Инверсия меняет ЗНАК задачи: инвертированный закон предсказывает −y,
 * его anchor считается на −y. Финальный anchor на ОРИГИНАЛЬНОМ y:
 * m̂_final = −m̂_inv (pred_orig = −pred_inv → y/pred_orig = −(y/pred_inv)).
 * Тройка сериализуется для записи закона: shape — форма, m̂ — масштаб
 * с учётом знака, sign — канал обнаружения (direct|inverted).
 */
final class ContradictionTripleTest extends TestCase
{
    public function testTripleSerializationInverted(): void
    {
        // Инвертированный закон: anchor на −y дал m̂_inv = 2.0 → на y: m̂ = −2.0
        $out = ContradictionEngine::buildLawTriple('(*×*)', 2.0, 'inverted');
        $this->assertSame(['shape' => '(*×*)', 'm_hat' => -2.0, 'sign' => 'inverted'], $out);
    }

    public function testTripleSerializationDirect(): void
    {
        $out = ContradictionEngine::buildLawTriple('(C×*)', 0.5, 'direct');
        $this->assertSame(['shape' => '(C×*)', 'm_hat' => 0.5, 'sign' => 'direct'], $out);
    }

    public function testInvertedAnchorNegation(): void
    {
        // Контракт знака: инверсия переворачивает масштаб (m̂_final = −m̂_inv).
        // Проверка на паре: инвертированный anchor 0.5 → финальный −0.5;
        // инвертированный −3.0 → финальный +3.0.
        $a = ContradictionEngine::buildLawTriple('(*×*)', 0.5, 'inverted');
        $this->assertSame(-0.5, $a['m_hat']);
        $b = ContradictionEngine::buildLawTriple('(*×*)', -3.0, 'inverted');
        $this->assertSame(3.0, $b['m_hat']);
    }

    public function testDirectAnchorUnchanged(): void
    {
        $a = ContradictionEngine::buildLawTriple('(*×*)', 0.5, 'direct');
        $this->assertSame(0.5, $a['m_hat'], 'Прямой канал: anchor без инверсии');
    }
}
