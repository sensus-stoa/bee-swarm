<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\ResourceGuard;

class ResourceGuardTest extends TestCase
{
    /** Создание с лимитами по умолчанию */
    public function test_default_limits(): void
    {
        $g = new ResourceGuard();
        $stats = $g->stats();
        $this->assertEquals('ok', $stats['status']);
        $this->assertGreaterThan(0, $g->sleepUs());
    }

    /** sleep адаптивный: при ok уменьшается */
    public function test_sleep_decreases_when_ok(): void
    {
        $g = new ResourceGuard(0.99, 0.99); // очень высокие лимиты
        $initial = $g->sleepUs();
        
        // Много вызовов guard с ok статусом
        for ($i = 0; $i < 10; $i++) {
            $g->guard();
        }
        
        $this->assertLessThanOrEqual($initial, $g->sleepUs(), 'Sleep should decrease when resources are ok');
    }

    /** sleep увеличивается при превышении CPU */
    public function test_sleep_increases_on_throttle(): void
    {
        $g = new ResourceGuard(0.0, 0.0); // лимит 0 → всегда throttle
        $initial = $g->sleepUs();
        
        $g->guard();
        
        $this->assertGreaterThanOrEqual($initial, $g->sleepUs(), 'Sleep should increase when throttled');
    }

    /** stats возвращает корректную структуру */
    public function test_stats_structure(): void
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

    /** guard возвращает статус */
    public function test_guard_returns_status(): void
    {
        $g = new ResourceGuard(0.99, 0.99);
        $status = $g->guard();
        $this->assertEquals('ok', $status, 'Should be ok with high limits');
    }

    /** sleep не уходит ниже минимума */
    public function test_sleep_has_minimum(): void
    {
        $g = new ResourceGuard(0.99, 0.99);
        for ($i = 0; $i < 100; $i++) {
            $g->guard();
        }
        $this->assertGreaterThanOrEqual(200000, $g->sleepUs(), 'Sleep should not go below 200ms');
    }

    /** sleep не превышает максимум */
    public function test_sleep_has_maximum(): void
    {
        $g = new ResourceGuard(0.0, 0.0);
        for ($i = 0; $i < 10; $i++) {
            $g->guard();
        }
        $this->assertLessThanOrEqual(5000000, $g->sleepUs(), 'Sleep should not exceed 5s');
    }
}
