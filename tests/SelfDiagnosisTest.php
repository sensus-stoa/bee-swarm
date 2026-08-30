<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * §3.3 Само-модель незнания (Stage 2): Search::find при неудаче обязан
 * диагностировать ПРИЧИНУ отказа — категория из фиксированного набора
 * {DATA, GRAMMAR, NOISE, DEPTH} — вместо безликого 'none'/'TIMEOUT'.
 *
 * Диагноз — элемент[5] ответа find() (добавлен в КОНЕЦ, backward-compat:
 * потребители деструктурируют [0..4]).
 *
 * Приоритет категорий (Е): DATA > DEPTH > GRAMMAR > NOISE — диагноз по
 * наименее дорогой валидации §3.3. NOISE — вердикт-исключение.
 *
 * Валидация категорий (по §3.3):
 * - DATA: добавить данные → решается (tMin-guard: механический pre-filter).
 * - GRAMMAR: добавить операцию → решается (y=1/x без '/': добавь — решится).
 * - NOISE: перемешать метки → CV не изменился (чистый независимый шум).
 * - DEPTH: увеличить глубину → решается (dot: depth=2 fail, depth=3 PASS).
 *
 * Известная граница метрики (документировано, не баг): пила y=x mod 2 на
 * depth=3 даёт NOISE (cv пилы 0.57 > 0.5 для ЛЮБОЙ полиномиальной
 * комбинации; валидация перемешиванием проходит — для полиномиальной
 * грамматики пила неотличима от шума). Человек видит закон, система
 * честно говорит «не знаю» — §0.5 правило 3.
 */
final class SelfDiagnosisTest extends TestCase
{
    /**
     * tMin = max(10, nFeat×5): 2 фичи → tMin=10; 8 строк < 10 → DATA.
     */
    public function testSmallSampleDiagnosedAsData(): void
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/']);

        $X = [[1.0, 2.0], [2.0, 1.0], [3.0, 4.0], [4.0, 3.0],
            [5.0, 6.0], [6.0, 5.0], [7.0, 8.0], [8.0, 7.0]];
        $y = [2.0, 3.0, 7.0, 7.0, 11.0, 11.0, 15.0, 15.0];

        $res = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 10.0, 10);

        $this->assertFalse($res[0], '8 строк < tMin=10 — sufficiency pre-filter §1.2');
        $this->assertSame('DATA', $res[5], 'маленькая выборка обязана диагностироваться как DATA');
    }

    /** y=1/x при грамматике БЕЗ '/': гипербола аппроксимируется полиномами
     *  с cv<0.5 (сигнал есть), но точный закон невыразим. Валидация §3.3:
     *  добавить '/' → решается (x0/x0²). */
    public function testInexpressibleLawDiagnosedAsGrammar(): void
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', 'sq']);

        mt_srand(42);
        $X = [];
        $y = [];
        for ($i = 0; $i < 100; $i++) {
            $x = 1.0 + mt_rand() / mt_getrandmax() * 9.0;
            $X[] = [$x];
            $y[] = 1.0 / $x;
        }

        $res = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 20.0);

        $this->assertFalse($res[0], '1/x без / невыразим — закон не должен «найден»');
        $this->assertSame('GRAMMAR', $res[5], 'сигнал есть, класс не покрыт → GRAMMAR');

        // Валидация §3.3: добавить операцию → решается
        $gWith = new Grammar();
        $gWith->restrictTo(['+', '×', '−', '/', 'sq']);
        $resWith = Search::find($X, $y, $gWith, 3, null, 0.0, 0.15, 20.0);
        $this->assertTrue($resWith[0], 'валидация §3.3: с / закон найден');
    }

    /** Чистый шум: y ~ U(0,1) независимо от x. 60 строк, depth=3.
     *  Валидация §3.3: перемешать метки → CV не изменился → NOISE. */
    public function testPureNoiseDiagnosedAsNoise(): void
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/']);

        mt_srand(7);
        $X = [];
        $y = [];
        for ($i = 0; $i < 60; $i++) {
            $X[] = [mt_rand() / mt_getrandmax()];
            $y[] = mt_rand() / mt_getrandmax();
        }

        $res = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 20.0);

        $this->assertFalse($res[0]);
        $this->assertSame('NOISE', $res[5], 'независимая цель → NOISE');
    }

    /** DEPTH: dot-закон y = x0×x3 + x1×x4 + x2×x5 при depth=2 — каскад
     *  выключен (гейт depth>=3), лучшие depth-2 кандидаты cv>0.5.
     *  Валидация §3.3: увеличить глубину до 3 → решается (slot-каскад). */
    public function testDepthDeficitDiagnosedAsDepth(): void
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/']);

        mt_srand(11);
        $X = [];
        $y = [];
        for ($i = 0; $i < 100; $i++) {
            $v = [];
            for ($j = 0; $j < 6; $j++) {
                $v[] = mt_rand() / mt_getrandmax() * 10.0 - 5.0;
            }
            $X[] = $v;
            $y[] = $v[0] * $v[3] + $v[1] * $v[4] + $v[2] * $v[5];
        }

        $res = Search::find($X, $y, $g, 2, null, 0.0, 0.15, 30.0);

        $this->assertFalse($res[0], 'depth=2 не покрывает dot (каскад выключен)');
        $this->assertSame('DEPTH', $res[5], 'глубина мала → DEPTH');

        // Валидация §3.3: увеличить глубину → решается
        $res3 = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 30.0);
        $this->assertTrue($res3[0], 'валидация §3.3: depth=3 решает dot');
    }
}
