<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\ContradictionEngine;
use PHPUnit\Framework\TestCase;

/**
 * V0.13 WU-3: ANOMALY-ветвь + METRIC_BLINDNESS_FLAG.
 *
 * Инцидент-контракт: противоречие устойчиво, инверсия не подтвердила гипотезу →
 * ANOMALY + флаг в METRIC-FAMILY очередь (v1.5b). Флаг идемпотентен: повторный
 * вызов на том же (shape, domain) не дублирует (флап-флуд bool-флага — класс
 * ловли двойным ревью 04-05.09).
 */
final class ContradictionAnomalyTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        ContradictionEngine::resetBlindnessFlags();
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'contradiction_anom_');
    }

    protected function tearDown(): void
    {
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testFlagLoggedOnce(): void
    {
        $candidate = ['atom' => '((x0/Rminx0)−Rrangex3)'];
        ContradictionEngine::flagMetricBlindness($candidate, 0.947, 'ccpp', $this->logFile);
        ContradictionEngine::flagMetricBlindness($candidate, 0.947, 'ccpp', $this->logFile);
        ContradictionEngine::flagMetricBlindness($candidate, 0.947, 'ccpp', $this->logFile);

        $count = substr_count((string) file_get_contents($this->logFile), 'METRIC_BLINDNESS_FLAG');
        $this->assertSame(1, $count, 'Флаг идемпотентен: 3 вызова = 1 запись (флап-флуд класс)');
    }

    public function testFlagDistinctDomainsBothLogged(): void
    {
        $candidate = ['atom' => '((x0/Rminx0)−Rrangex3)'];
        ContradictionEngine::flagMetricBlindness($candidate, 0.947, 'ccpp', $this->logFile);
        ContradictionEngine::flagMetricBlindness($candidate, 0.947, 'airfoil', $this->logFile);

        $log = (string) file_get_contents($this->logFile);
        $this->assertSame(1, substr_count($log, 'domain=ccpp'));
        $this->assertSame(1, substr_count($log, 'domain=airfoil'), 'Разные домены = разные флаги (NEW-PARADIGM материал)');
    }

    public function testFlagCarriesFingerprintFields(): void
    {
        // Флаг несёт фидометрию для дизайна метрики v1.5b: shape, |corr|, домен
        $candidate = ['atom' => '((x0/Rminx0)−Rrangex3)'];
        ContradictionEngine::flagMetricBlindness($candidate, 0.947, 'ccpp', $this->logFile);
        $log = (string) file_get_contents($this->logFile);

        $this->assertStringContainsString('shape=((x0/Rminx0)−Rrangex3)', $log);
        $this->assertStringContainsString('|corr|=0.947', $log);
        $this->assertStringContainsString('domain=ccpp', $log);
        $this->assertStringContainsString('METRIC-FAMILY', $log);
    }
}
