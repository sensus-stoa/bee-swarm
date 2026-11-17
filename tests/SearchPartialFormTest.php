<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use PHPUnit\Framework\TestCase;

/**
 * WU PARTIAL-EVAL (31.10, ответ рецензенту на «affinity = 1/CV»):
 * Search::$lastBestFormSeen — ИМЯ лучшей plausible-формы на failure-пути
 * (сейчас имя умирает: bestName=null при held-out отказе, наружу 'none'
 * → eval-CV partial-форм не считается → train-eval gap непроверяем).
 * Паттерн last-call статики ($lastBestCvSeen / $lastTrailWritten).
 * found/селекция НЕ меняются.
 */
final class SearchPartialFormTest extends TestCase
{
    private function grammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);

        return $g;
    }

    /** Heat-фикстура L3L1 (канон): depth 4 — ПЕРЕБОРНЫЙ отказ (не фича-граница). */
    private function heatFixture(): array
    {
        $X = [];
        $y = [];
        mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $kappa = mt_rand() / mt_getrandmax() * 10 + 0.1;
            $t2 = mt_rand() / mt_getrandmax() * 70 + 280;
            $t1 = mt_rand() / mt_getrandmax() * 90 + 250;
            $a = mt_rand() / mt_getrandmax() * 4.5 + 0.5;
            $d = mt_rand() / mt_getrandmax() * 1.9 + 0.1;
            $X[] = [$kappa, $t2, $t1, $a, $d];
            $y[] = $kappa * ($t2 - $t1) * $a / $d;
        }

        return [$X, $y];
    }

    public function testFindResetsPartialFormOnEntry(): void
    {
        // Мусор прошлого вызова стирается в начале find.
        Search::$lastBestFormSeen = 'BOGUS';
        [$X, $y] = $this->heatFixture();
        // budgetTicks=0 → мгновенный TIMEOUT до seeing форм.
        Search::find($X, $y, $this->grammar(), 2, null, 0.0, 0.15, 0.0, null, 0);
        $this->assertSame('none', Search::$lastBestFormSeen);
    }

    public function testRefusalCarriesPartialForm(): void
    {
        // Depth 4 heat: отказ переборный (L3L1Test-канон), plausible
        // собран → статик несёт ИМЯ лучшей формы (не 'none').
        [$X, $y] = $this->heatFixture();
        $res = Search::find($X, $y, $this->grammar(), 4, null, 0.0, 0.15, 60.0);
        $this->assertFalse($res[0], 'depth 4 heat не находит (L3L1-канон)');
        $this->assertNotSame('none', Search::$lastBestFormSeen, 'plausible был непуст — имя обязано выйти наружу');
        $this->assertNotSame('', Search::$lastBestFormSeen);
        // Согласованность каналов: имя есть → CV конечный.
        $this->assertLessThan(9.99, Search::$lastBestCvSeen);
    }
}
