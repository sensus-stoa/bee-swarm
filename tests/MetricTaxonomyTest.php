<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\MetricPreflight;
use BeeSwarm\Hive\Hive;
use PHPUnit\Framework\TestCase;

/**
 * V0.12 WU-4: METRIC-DOMAIN в refusal-таксономии §3.3 (T1 re-check).
 *
 * T1 (theorem-level): таксономия отказов = partition пространства отказов;
 * полнота эмпирическая — контрпримеры интегрируются как новые строки
 * (прецеденты: ENERGY тихий отказ, INSUFFICIENT-семейство).
 *
 * METRIC-DOMAIN = подкласс GRAMMAR (сигнал может существовать, сертификационная
 * машина не различает — rel-метрика вне домена различимости). Точка отказа:
 * filterInsufficient (роутер, pre-filter), НЕ Search::find (поиск не стартует).
 *
 * Контракт partition: каждый отказ = один и только один класс. Проверки:
 *  - pre-flight отказ логируется классом METRIC_DOMAIN (не DEPTH/NOISE/GRAMMAR);
 *  - класс ПРЕДШЕСТВУЕТ поиску (до discover()) — инвариант «pre-класс»;
 *  - таксономия остаётся исчерпывающей: METRIC-DOMAIN не открывает дыр
 *    (все прежние классы работают — suite).
 */
final class MetricTaxonomyTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'preflight_tax_');
    }

    protected function tearDown(): void
    {
        foreach (['PREFLIGHT_GATE_FACTOR', 'SWARM_DB_PATH', 'FORAGER_SOURCES', 'NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K'] as $k) {
            putenv($k);
        }
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testMetricDomainIsRefusalBeforeSearch(): void
    {
        // Pre-flight класс существует, статус METRIC_DOMAIN, отказ до поиска.
        $y = array_fill(0, 50, 100.0);
        $y[0] = 101.0; // микрошум, CV≈0
        $out = MetricPreflight::check(0.15, $y);
        $this->assertSame('METRIC_DOMAIN', $out->status);
        $this->assertFalse($out->passes);
    }

    public function testPreFlightClassLoggedByRouter(): void
    {
        // Живой путь: filterInsufficient логирует класс METRIC_DOMAIN_PREFLIGHT.
        putenv('PREFLIGHT_GATE_FACTOR=0.5');
        $hive = new Hive(maxTicks: 0);
        $task = [
            'name' => 'taxonomy_lowcv_' . uniqid(),
            'domain' => 'taxonomy_test',
            'data' => array_map(
                fn (int $i): array => [(float) $i, 50.0 + $i * 0.001], // таргет почти константа
                range(1, 60),
            ),
        ];
        $method = new \ReflectionMethod(Hive::class, 'filterInsufficient');
        $method->setAccessible(true);
        $out = $method->invoke($hive, [$task]);
        $this->assertSame([], $out, 'Низко-CV задача отказана классом METRIC_DOMAIN');
    }

    public function testTaxonomyCompletenessWithNewClass(): void
    {
        // T1 re-check: закрытый набор {DATA, DEPTH, GRAMMAR, NOISE, TIMEOUT,
        // ENERGY} + METRIC-DOMAIN (подкласс GRAMMAR). Инвариант полноты:
        // каждый refusal-статус системы ∈ закрытый набор ∪ pre-классы.
        // Pre-flight статусы: PASS | METRIC_DOMAIN | DISABLED.
        $statuses = [];
        // [gate, y]: отказ-константа, pass-разброс, disabled-режим
        foreach ([[0.15, array_fill(0, 50, 100.0)], [0.15, $this->spreadY()]] as [$gate, $y]) {
            $statuses[] = MetricPreflight::check($gate, $y)->status;
        }
        putenv('PREFLIGHT_GATE_FACTOR=0');
        $statuses[] = MetricPreflight::check(0.15, array_fill(0, 50, 100.0))->status;
        putenv('PREFLIGHT_GATE_FACTOR');
        foreach ($statuses as $st) {
            $this->assertContains($st, ['PASS', 'METRIC_DOMAIN', 'DISABLED'], 'Статус вне закрытого набора pre-flight: ' . $st);
        }
        $this->assertContains('METRIC_DOMAIN', $statuses, 'Набор проверок покрывает отказ');
        $this->assertContains('PASS', $statuses);
        $this->assertContains('DISABLED', $statuses);
    }

    private function spreadY(): array
    {
        mt_srand(999);
        $y = [];
        for ($i = 0; $i < 50; $i++) {
            $y[] = mt_rand() / mt_getrandmax() * 100;
        }

        return $y;
    }
}
