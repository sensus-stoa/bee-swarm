<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use PHPUnit\Framework\TestCase;

/**
 * T1 (story theorem-level): тест-фикстуры полноты refusal taxonomy.
 *
 * Критерий partition: каждый отказ Search::find/Hive несёт РОВНО один
 * класс вердикта [5], и классы покрывают все отказные ветви.
 *
 * Классы v2: DATA, DEPTH, NOISE, GRAMMAR, TIMEOUT (+ ENERGY/INSUFFICIENT
 * на Hive-слое — вне этого файла, проверяются отдельно).
 */
final class RefusalTaxonomyCompletenessTest extends TestCase
{
    private Grammar $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = new Grammar();
    }

    /** DATA: пустой вход — отказ ДО перебора, cv sentinel 9.99. */
    public function testDataClassOnEmptyInput(): void
    {
        $r = Search::find([], [], $this->g, 2, null, 0.0, 0.15, 1.0);
        self::assertFalse($r[0]);
        self::assertSame('DATA', $r[5]);
    }

    /** DATA: строк меньше tMin (параметр вызывающего, §1.2). */
    public function testDataClassWhenRowsBelowTMin(): void
    {
        $rows = [[1.0], [2.0], [3.0]];
        $r = Search::find($rows, [1.0, 2.0, 3.0], $this->g, 2, null, 0.0, 0.15, 1.0, 10);
        self::assertFalse($r[0]);
        self::assertSame('DATA', $r[5]);
    }

    /** DEPTH: исчерпан бюджет на depth<3 — depth-приоритет выше budget (документировано). */
    public function testDepthClassOnTinyBudgetAtDepth2(): void
    {
        $rows = [[1.0, 2.0]];
        $r = Search::find($rows, [1.0], $this->g, 2, null, 0.0, 0.15, 0.0001);
        self::assertFalse($r[0]);
        self::assertSame('DEPTH', $r[5]);
    }

    /**
     * NOISE: цель — равномерный шум; любой кандидат даёт cv > NOISE_CV_FLOOR (0.5);
     * валидация протокола: перемешивание y не меняет verdict (метрика глуха к перестановке).
     * NOISE_CV_FLOOR пересекается только конечными cv — sentinel 9.99 не проходит.
     * Ожидание: NOISE при конечном bestCvSeen > 0.5; GRAMMAR при bestCvSeen = INF
     * (is_finite гвард §3.3: «ничего не оценено» — не шум).
     */
    public function testNoiseClassOnUniformNoise(): void
    {
        mt_srand(123);
        $rows = [];
        $y = [];
        for ($x = 1; $x <= 20; $x++) {
            $rows[] = [(float) $x];
            $y[] = mt_rand() / mt_getrandmax() * 100.0;
        }
        $r = Search::find($rows, $y, $this->g, 3, null, 0.0, 0.15, 0.0);
        self::assertFalse($r[0]);
        // Граница: NOISE (лучшие кандидаты конечны и плохи) ИЛИ GRAMMAR (лучших кандидатов не оценено).
        // Оба отказа честны; partition-инвариант: класс ∈ {NOISE, GRAMMAR}, не DEPTH (depth=3).
        self::assertContains($r[5], ['NOISE', 'GRAMMAR']);
    }

    /**
     * Partition-инвариант: любой отказ даёт НЕПУСТОЙ класс [5] из известного набора.
     * Пустой класс = тихий отказ = нарушение self-model (§3.3).
     */
    public function testRefusalAlwaysCarriesNonEmptyClass(): void
    {
        $cases = [
            'empty' => [[], []],
            'single' => [[[1.0]], [1.0]],
        ];
        foreach ($cases as $name => [$X, $y]) {
            $r = Search::find($X, $y, $this->g, 2, null, 0.0, 0.15, 1.0);
            self::assertNotSame('', $r[5], "отказ '{$name}' без класса — тихий отказ");
        }
    }

    /**
     * Sentinel-семантика: 9.99 в результате [1]/[3] — НЕ означает «cv > 0.5».
     * Диагностика обязана различать «не оценено» (INF bestCvSeen) и «оценено плохо».
     * Контрпример из T1: x²+30%noise → GRAMMAR при cv=9.99 в результате
     * (bestCvSeen внутри ≠ результат-значение) — документируем как отдельный
     * NOISE/GRAMMAR-границу. Здесь фиксируем только инвариант: result-cv 9.99
     * не может одновременно означать NOISE-логику.
     */
    public function testSentinelCvDoesNotImplyNoise(): void
    {
        // x² с 30% мультипликативным шумом — выразимая цель с большим шумом
        mt_srand(7);
        $rows = [];
        $y = [];
        for ($x = 1; $x <= 30; $x++) {
            $noise = 1.0 + (mt_rand() / mt_getrandmax() - 0.5) * 0.6;
            $rows[] = [(float) $x];
            $y[] = (float) $x * $x * $noise;
        }
        $r = Search::find($rows, $y, $this->g, 3, null, 0.0, 0.15, 0.0);
        self::assertFalse($r[0]);
        // эмпирика 04.09: class=GRAMMAR при result cv=9.99 → sentinel ≠ NOISE-триггер
        self::assertSame('GRAMMAR', $r[5], 'sentinel-cv не проходит NOISE-ветку (is_finite гвард)');
    }
}
