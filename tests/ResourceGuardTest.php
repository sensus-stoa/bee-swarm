<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\ResourceGuard;

class ResourceGuardTest extends TestCase
{
    /**
     * Создание с лимитами по умолчанию
     */
    public function testDefaultLimits(): void
    {
        $g = new ResourceGuard();
        $stats = $g->stats();
        $this->assertEquals('ok', $stats['status']);
        $this->assertGreaterThan(0, $g->sleepUs());
    }

    /**
     * sleep адаптивный: при ok уменьшается
     */
    public function testSleepDecreasesWhenOk(): void
    {
        $g = new ResourceGuard(0.99, 0.99); // очень высокие лимиты
        $initial = $g->sleepUs();

        // Много вызовов guard с ok статусом
        for ($i = 0; $i < 10; $i++) {
            $g->guard();
        }

        $this->assertLessThanOrEqual($initial, $g->sleepUs(), 'Sleep should decrease when resources are ok');
    }

    /**
     * sleep увеличивается при превышении CPU
     */
    public function testSleepIncreasesOnThrottle(): void
    {
        $g = new ResourceGuard(0.0, 0.0); // лимит 0 → всегда throttle
        $initial = $g->sleepUs();

        $g->guard();

        $this->assertGreaterThanOrEqual($initial, $g->sleepUs(), 'Sleep should increase when throttled');
    }

    /**
     * stats возвращает корректную структуру
     */
    public function testStatsStructure(): void
    {
        $g = new ResourceGuard();
        $g->guard();
        $stats = $g->stats();

        $this->assertArrayHasKey('cpu', $stats);
        $this->assertArrayHasKey('mem', $stats);
        $this->assertArrayHasKey('status', $stats);
        $this->assertIsFloat($stats['cpu']);
        $this->assertIsFloat($stats['mem']);
    }

    /**
     * guard возвращает статус
     */
    public function testGuardReturnsStatus(): void
    {
        $g = new ResourceGuard(0.99, 0.99);
        $status = $g->guard();
        $this->assertEquals('ok', $status, 'Should be ok with high limits');
    }

    /**
     * sleep не уходит ниже минимума
     */
    public function testSleepHasMinimum(): void
    {
        $g = new ResourceGuard(0.99, 0.99);
        for ($i = 0; $i < 100; $i++) {
            $g->guard();
        }
        $this->assertGreaterThanOrEqual(200000, $g->sleepUs(), 'Sleep should not go below 200ms');
    }

    /**
     * sleep не превышает максимум
     */
    public function testSleepHasMaximum(): void
    {
        $g = new ResourceGuard(0.0, 0.0);
        for ($i = 0; $i < 10; $i++) {
            $g->guard();
        }
        $this->assertLessThanOrEqual(5000000, $g->sleepUs(), 'Sleep should not exceed 5s');
    }
}
