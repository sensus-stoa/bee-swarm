<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use PHPUnit\Framework\TestCase;

/**
 * WU TELEMETRY-CV-SEEN (0h-check gradient vs step, 31.10):
 * Search::$lastBestCvSeen — лучший CV среди ВСЕХ испробованных форм
 * ($bestCvSeen), вынесенный наружу last-call статиком (паттерн
 * lastTrailWritten). Цель: промежуточные формы в телеметрии маршрутов
 * (сейчас L пишется только cert-маршрутам — gradient vs step
 * неразличим). НИКАКОГО изменения found/селекции: чистый канал данных.
 */
final class SearchBestCvSeenTest extends TestCase
{
    /**
     * Фикстура heat conduction (как в L3L1Test): найденный закон имеет
     * конечный CV — статик обязан записать лучший увиденный.
     */
    /**
     * Тривиально-найдимый закон y = 2·x0 (канон SearchBudgetTest:
     * 3 строки, дефолтная грамматика, depth 2 — найден мгновенно).
     * Heat-фикстура не годится: её закон — depth-3 композиция, а на
     * restricted grammar depth 3 = фича-отказник с РАННИМ выходом
     * (до присваивания статики) — время-зависимый тест, флейт в suite.
     */
    private function trivialFixture(): array
    {
        return [[[1.0, 2.0], [2.0, 4.0], [3.0, 6.0]], [2.0, 4.0, 6.0]];
    }

    private function grammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);

        return $g;
    }

    public function testFindResetsStaticOnEntry(): void
    {
        // Мусор от прошлого вызова обязан стираться в начале find.
        Search::$lastBestCvSeen = 0.001;
        [$X, $y] = $this->trivialFixture();
        // Мгновенный TIMEOUT: budgetTicks=0 → ранний выход до seeing форм,
        // статик сброшен (9.99).
        Search::find($X, $y, $this->grammar(), 2, null, 0.0, 0.15, 0.0, null, 0);
        $this->assertSame(9.99, Search::$lastBestCvSeen);
    }

    public function testFindRecordsBestCvSeenOnSuccessPath(): void
    {
        // y = 2·x0 — канонический found-кейс (SearchBudgetTest): главный
        // путь find() достигается, статик конечен.
        [$X, $y] = $this->trivialFixture();
        $res = Search::find($X, $y, $this->grammar(), 2, null, 0.0, 0.15, 0.0);
        $this->assertTrue($res[0], 'y=2x0 найден (канон SearchBudgetTest), diag=' . ($res[5] ?? 'null'));
        $this->assertTrue(is_numeric(Search::$lastBestCvSeen), 'статик числовой');
        $this->assertGreaterThanOrEqual(0.0, Search::$lastBestCvSeen);
        $this->assertLessThan(9.99, Search::$lastBestCvSeen, 'успешный поиск видел формы с конечным CV');
    }

    public function testFailurePathCarriesBestSeen(): void
    {
        // Отказ (depth 3 на sum не нужен — берём heat-отказник L3L1):
        // статик остаётся числовым в ЛЮБОМ исходе (INF → 9.99 канон).
        mt_srand(42);
        $X = [];
        $y = [];
        for ($i = 0; $i < 300; $i++) {
            $kappa = mt_rand() / mt_getrandmax() * 10 + 0.1;
            $t2 = mt_rand() / mt_getrandmax() * 70 + 280;
            $t1 = mt_rand() / mt_getrandmax() * 90 + 250;
            $a = mt_rand() / mt_getrandmax() * 4.5 + 0.5;
            $d = mt_rand() / mt_getrandmax() * 1.9 + 0.1;
            $X[] = [$kappa, $t2, $t1, $a, $d];
            $y[] = $kappa * ($t2 - $t1) * $a / $d;
        }
        $res = Search::find($X, $y, $this->grammar(), 3, null, 0.0, 0.15, 10.0);
        $this->assertFalse($res[0], 'depth 3 heat не выражает (L3L1Test-канон)');
        $this->assertTrue(is_numeric(Search::$lastBestCvSeen));
        $this->assertGreaterThanOrEqual(0.0, Search::$lastBestCvSeen);
    }
}
