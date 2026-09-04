<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\LawShape;
use PHPUnit\Framework\TestCase;

/**
 * T4 (story theorem-level): form-invariance через law-distance.
 *
 * Канон различает законы (T2: одна функция = одно слово). Но форма
 * (структура) может ПЕРЕНОСИТЬСЯ между законами: y=2x и y=3x имеют
 * одинаковую форму «линейный», y=x² — другую. Это основа transfer-метрики:
 * закон-дистанция = расстояние между инвариантами формы.
 *
 * Инвариант = канон с замаскированными листьями: атомы→*, константы→C.
 * y=2x → (*×C), y=3x → (*×C) — одна форма.
 * y=x → * — другая (меньше структуры).
 * y=x² → (*×*) — ещё другая (глубина ×).
 */
final class LawShapeTest extends TestCase
{
    /** Scale-пара: 2x и 3x — одна форма (масштаб не меняет структуру). */
    public function testScaleLawsShareShape(): void
    {
        $this->assertSame(
            LawShape::of('K2×x0'),
            LawShape::of('(K3×x0)'),
            '2x и 3x — одна форма (линейная)'
        );
    }

    /** Структурная пара: x² и x — разные формы. */
    public function testSquareVsLinearDiffer(): void
    {
        $this->assertNotSame(
            LawShape::of('(x0×x0)'),
            LawShape::of('x0'),
            'x² и x — разная структура'
        );
    }

    /** Унарная форма: sqrt(x) и x — разные. */
    public function testUnaryDiffers(): void
    {
        $this->assertNotSame(LawShape::of('sqrt(x0)'), LawShape::of('x0'));
    }

    /** Композиция: (2x)² и (3x)² — одна форма ((*)²-структура с конст-масштабом). */
    public function testComposedScaleShareShape(): void
    {
        $this->assertSame(
            LawShape::of('((K2×x0)×(K2×x0))'),
            LawShape::of('((K3×x0)×(K3×x0))')
        );
    }

    /** Law-distance: same shape → 0, разные → 1 (пока бинарная метрика). */
    public function testLawDistanceBinary(): void
    {
        $this->assertSame(0, LawShape::distance('K2×x0', '(K3×x0)'));
        $this->assertSame(1, LawShape::distance('x0', '(x0×x0)'));
    }

    /** Инвариант не теряет НЕкоммутативную структуру: x−y и x/y — разные формы. */
    public function testNonCommutativeStructureKept(): void
    {
        $this->assertNotSame(LawShape::of('(x0−x1)'), LawShape::of('(x0/x1)'));
    }
}
