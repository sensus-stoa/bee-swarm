<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use PHPUnit\Framework\TestCase;

/**
 * WU SELECTION-CEIL (31.10, BAR-E3): развязка selection-входа и found-гейта.
 *
 * Проблема: единый $cvTrainMax фильтровал ТРИ места — plausible-вход (:1980),
 * testCv-гейт (:2175), found-конъюнкцию (:2256). Форма с train-CV в
 * [eps..] не доходила до held-out теста (бар до бара); кампанийный кейс
 * E3: train 0.104 / eval 0.0961 — терялась. Env SELECTION_CEIL поднимает
 * ТОЛЬКО plausible-вход; found при testRatio>0 решается held-out
 * (train-бар победителя устранён — форма с train выше порога, прошедшая
 * held-out, легитимный кандидат). testRatio=0 путь не тронут (там train-CV
 * — единственная метрика).
 *
 * TEMPORARY, not biological (reviewer 31.10): относительный ceil
 * (median×1.2) — следующая строка после кампании.
 */
final class SearchSelectionCeilTest extends TestCase
{
    private function grammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);

        return $g;
    }

    /**
     * Расщеплённая цель: train (строки 0..15) y = 5·x0, held-out
     * (последние 20% при testRatio=0.2) y = 2·x0. Форма (x0+x0) имеет
     * train-CV ≈ 0.6 (выше любого разумного порога) и held-out CV = 0.
     * Ни одна форма не может пройти ОБОИХ срезов линейно — selection
     * обязан найти победителя только через расширенный вход.
     */
    private function splitFixture(): array
    {
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = $i <= 16 ? 5.0 * $i : 2.0 * $i;
        }

        return [$X, $y];
    }

    private function uniformFixture(): array
    {
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = 2.0 * $i;
        }

        return [$X, $y];
    }

    protected function tearDown(): void
    {
        putenv('SELECTION_CEIL');
    }

    public function testCeilUnlocksHeldoutQualifiedForm(): void
    {
        putenv('SELECTION_CEIL=0.7');
        [$X, $y] = $this->splitFixture();
        $r = Search::find($X, $y, $this->grammar(), 2, null, 0.2, 0.10, 30.0);
        $this->assertTrue((bool) $r[0], 'форма с train 0.6 / held-out 0 обязана стать found при расширенном входе');
        $this->assertIsNumeric($r[3]);
        $this->assertLessThan(0.10, (float) $r[3], 'held-out CV победителя обязан быть ниже абсолютного порога');
    }

    public function testWithoutEnvSplitFixtureStaysUnfound(): void
    {
        [$X, $y] = $this->splitFixture();
        $r = Search::find($X, $y, $this->grammar(), 2, null, 0.2, 0.10, 30.0);
        $this->assertFalse((bool) $r[0], 'без env прежняя семантика: форма с train выше порога не found');
    }

    public function testDefaultUniformFixtureStillFound(): void
    {
        // Регресс: штатный found-путь (однородная цель) не сломан.
        [$X, $y] = $this->uniformFixture();
        $r = Search::find($X, $y, $this->grammar(), 2, null, 0.2, 0.10, 30.0);
        $this->assertTrue((bool) $r[0]);
    }

    public function testZeroRatioPathUnchanged(): void
    {
        // testRatio=0: train-CV — единственная метрика, семантика прежняя.
        [$X, $y] = $this->uniformFixture();
        $r = Search::find($X, $y, $this->grammar(), 2, null, 0.0, 0.15, 30.0);
        $this->assertTrue((bool) $r[0]);
    }
}
