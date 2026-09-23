<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use PHPUnit\Framework\TestCase;

/**
 * V0.18-TICK-BUDGET WU-1: тиковая метрика бюджета поиска.
 *
 * Wall-clock (budgetSec) делает вердикт find() зависимым от загрузки
 * машины (V0.17-класс флаков: -p8 contention → TIMEOUT вместо found).
 * Тиковый бюджет = счётчик работы движка на границах фаз/порций:
 * детерминирован при тех же грамматике/данных/глубине/beam-режиме.
 *
 * Контракт WU-1 (по живому коду 22.09):
 *  - новый параметр budgetTicks=null после $tMin (аддитивно);
 *  - budgetTicks=null и budgetSec=0 → INF (callers не ломаются);
 *  - budgetTicks=0 → явный лимит: отказ с диагнозом TICKS_EXHAUSTED
 *    (гвард env-канала обязан требовать >0 — budget=0 не «выключатель»);
 *  - диагностика разделяет TICKS_EXHAUSTED vs WALLCLOCK_CAP;
 *  - kill-switch: env SEARCH_WALLCLOCK_CAP_S (wall-clock поверх тиков,
 *    default off), сработал → диагноз WALLCLOCK_CAP.
 */
final class SearchTickBudgetTest extends TestCase
{
    private const BEAM_OFF = '0';

    private ?string $savedBeamK = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Детерминизм тиков требует выключенного random-хвоста beam
        // (Search:493 shuffle($rest) — единственная случайность перебора;
        // phpunit.xml ставит SEARCH_BEAM_K=10, beam-гейт в Search getenv).
        // Ревью deleg_1908be04: putenv('X') в tearDown = unset, xml-значение
        // теряется для последующих классов воркера — сохраняем и восстанавливаем.
        $saved = getenv('SEARCH_BEAM_K');
        $this->savedBeamK = $saved === false ? null : $saved;
        putenv('SEARCH_BEAM_K=' . self::BEAM_OFF);
    }

    protected function tearDown(): void
    {
        if ($this->savedBeamK === null) {
            putenv('SEARCH_BEAM_K');
        } else {
            putenv('SEARCH_BEAM_K=' . $this->savedBeamK);
        }
        putenv('SEARCH_WALLCLOCK_CAP_S');
        parent::tearDown();
    }

    /**
     * Главный критерий WU-1: тиковый бюджет детерминирован.
     * N повторов find() с budgetTicks на недостижимо-шумной фикстуре →
     * РОВНО один вердикт (класс [4] + диагноз [5]) — wall-clock этот
     * тест проваливает флаком под нагрузкой.
     */
    public function testTickBudgetDeterministicVerdictAcrossRepeats(): void
    {
        $fix = self::noiseFixture(10, 40);
        $verdicts = [];
        for ($i = 0; $i < 5; $i++) {
            $res = Search::find($fix['X'], $fix['y'], new Grammar(), 2, null, 0.0, 0.15, 0.0, null, 1);
            $verdicts[] = $res[4] . '|' . $res[5];
        }
        $uniq = array_unique($verdicts);
        self::assertCount(
            1,
            $uniq,
            'тики не детерминированы, получены вердикты: ' . implode('; ', $uniq)
        );
        [$class, $diagnosis] = explode('|', $verdicts[0]);
        self::assertSame('TIMEOUT', $class, 'исчерпание тиков = класс TIMEOUT');
        self::assertSame('TICKS_EXHAUSTED', $diagnosis);
    }

    /**
     * Детерминизм при бюджете, пересекающем НЕСКОЛЬКО фаз: budgetTicks=3
     * проходит L0 и rawFeat, истощается на L2-границе/порции (не входной
     * чек). Ревью deleg_1908be04 №3: budgetTicks=1 не может варьироваться
     * даже при недетерминированном тик-учёте следующих фаз.
     */
    public function testTickBudgetCrossesMultiplePhasesDeterministically(): void
    {
        $fix = self::noiseFixture(10, 40);
        $verdicts = [];
        for ($i = 0; $i < 5; $i++) {
            $res = Search::find($fix['X'], $fix['y'], new Grammar(), 2, null, 0.0, 0.15, 0.0, null, 3);
            $verdicts[] = $res[4] . '|' . $res[5];
        }
        $uniq = array_unique($verdicts);
        self::assertCount(
            1,
            $uniq,
            'тики multi-phase не детерминированы: ' . implode('; ', $uniq)
        );
        self::assertSame('TIMEOUT|TICKS_EXHAUSTED', $verdicts[0]);
    }

    /**
     * Граница совместимости: budgetTicks=null + budgetSec=0 → INF.
     * Существующие callers (DiscoveryEngine и пр.) обязаны работать как раньше.
     */
    public function testNullTickBudgetKeepsLegacyInfinity(): void
    {
        $X = [[1.0, 2.0], [2.0, 4.0], [3.0, 6.0]];
        $y = [2.0, 4.0, 6.0];
        $res = Search::find($X, $y, new Grammar(), 2, null, 0.0, 0.15, 0.0, null);
        self::assertTrue($res[0], 'y=2x0 найден без бюджета (legacy-путь INF)');
    }

    /**
     * Явный 0 = ЛИМИТ (ноль тиков = мгновенное истощение), не «выключено».
     * Инверсия семантики budgetSec=0 (=INF) — сознательная: env-каналы
     * бюджета гвардятся >0 (H5 V0.11: 0 → зависание сертификации).
     */
    public function testZeroTickBudgetIsHardLimit(): void
    {
        $X = [[1.0, 2.0], [2.0, 4.0], [3.0, 6.0]];
        $y = [2.0, 4.0, 6.0];
        $res = Search::find($X, $y, new Grammar(), 2, null, 0.0, 0.15, 0.0, null, 0);
        self::assertFalse($res[0], '0 тиков = никакого перебора');
        self::assertSame('TICKS_EXHAUSTED', $res[5]);
    }

    /**
     * Kill-switch: SEARCH_WALLCLOCK_CAP_S срабатывает поверх тиков и
     * различим в диагнозе (TICKS_EXHAUSTED vs WALLCLOCK_CAP — риск 3 стори:
     * «двойная метрика = раздвоение ответственности»).
     * Тиковый бюджет завышен (не исчерпается) → отказ только от kill-switch.
     */
    public function testWallclockKillSwitchDiagnosedSeparately(): void
    {
        putenv('SEARCH_WALLCLOCK_CAP_S=0.0001');
        $X = [[1.0, 2.0], [2.0, 4.0], [3.0, 6.0]];
        $y = [2.0, 4.0, 6.0];
        // 10^6 тиков на 3 строках не исчерпаются раньше wall-clock-cap.
        $res = Search::find($X, $y, new Grammar(), 2, null, 0.0, 0.15, 0.0, null, 1000000);
        self::assertFalse($res[0], 'kill-switch обязан остановить поиск');
        self::assertSame('WALLCLOCK_CAP', $res[5]);
    }

    /**
     * Положительный тиковый бюджет на шумной фикстуре: отказ именно от
     * исчерпания тиков (не от данных/грамматики — depth=3 ушёл бы в DEPTH
     * только при полном переборе; при 1 тике всегда TICKS_EXHAUSTED).
     */
    public function testPositiveTickBudgetExhaustionDiagnosis(): void
    {
        $fix = self::noiseFixture(6, 30);
        $res = Search::find($fix['X'], $fix['y'], new Grammar(), 3, null, 0.0, 0.15, 0.0, null, 1);
        self::assertFalse($res[0]);
        self::assertSame('TICKS_EXHAUSTED', $res[5], '1 тик на 6 фичах не успевает ничего');
    }

    /**
     * Шумовая фикстура: закон не выразим (R²-независимость), поиск упирается
     * в бюджет, а не в находку. powers-of-2 базис — stable-паттерн копилки.
     *
     * @return array{X: list<list<float>>, y: list<float>}
     */
    private static function noiseFixture(int $nFeat, int $rows): array
    {
        mt_srand(42);
        $X = [];
        $y = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($f = 0; $f < $nFeat; $f++) {
                $row[] = (float) (2 ** ($r % 12)) * (1.0 + $f);
            }
            $X[] = $row;
            // y — своя перестановка, не функция строк X (шум относительно перебора)
            $y[] = mt_rand() / mt_getrandmax() * 5.0;
        }

        return [
            'X' => $X,
            'y' => $y,
        ];
    }
}
