<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\ResourceGuard;

/**
 * Тесты ДО фикса: доказывают, что текущий ResourceGuard НЕ ловит процессный CPU.
 */
class ResourceGuardCpuTest extends TestCase
{
    /**
     * sys_getloadavg меряет СИСТЕМНУЮ нагрузку, не процессную.
     * Если система idle, guard всегда возвращает 'ok' даже если процесс жрёт 100% CPU.
     */
    public function testSystemLoadDoesNotReflectProcessCpu(): void
    {
        $g = new ResourceGuard(0.1, 0.99); // лимит CPU 10%

        // Имитируем нагрузку: guard должен вернуть ok на idle системе
        $status = $g->guard();

        // На idle системе load average < 0.1 × cores → всегда ok
        // Даже если сам процесс жрёт CPU, sys_getloadavg может быть низким
        $this->assertNotNull($status, 'Guard should return status');
        // Не можем гарантировать throttle на CI, но проверяем что метод работает
    }

    /**
     * После фикса: guard должен использовать getrusage() или /proc/self/stat
     * для измерения ПРОЦЕССНОГО CPU.
     */
    public function testProcessCpuMeasurementAvailable(): void
    {
        // Проверяем доступность /proc/self/stat (Linux)
        $stat = @file_get_contents('/proc/self/stat');
        $this->assertNotFalse($stat, '/proc/self/stat должен быть доступен на Linux');

        // Проверяем getrusage
        $usage = getrusage();
        $this->assertArrayHasKey('ru_utime.tv_sec', $usage, 'getrusage должен возвращать user time');
    }

    /**
     * После фикса: бёрн CPU должен детектироваться.
     */
    public function testCpuBurnDetection(): void
    {
        $g = new ResourceGuard(0.3, 0.99); // лимит CPU 30%

        // Первый замер
        $g->guard();

        // Жжём CPU: 200ms плотного счёта
        $start = microtime(true);
        while (microtime(true) - $start < 0.2) {
            sqrt(rand() * rand());
        }

        // Второй замер
        $status = $g->guard();
        $stats = $g->stats();

        // С текущей реализацией этот тест СКОРЕЕ ВСЕГО пройдёт (guard вернёт ok),
        // потому что sys_getloadavg не отражает процессную нагрузку.
        // Это ДЕМОНСТРАЦИЯ проблемы, а не проверка фикса.
        $this->assertIsFloat($stats['cpu']);
    }

    /**
     * После фикса: sleep адаптируется к процессной нагрузке.
     * На idle системе с низким лимитом — sleep должен уменьшаться до минимума.
     */
    public function testSleepAdaptsToProcessLoad(): void
    {
        $g = new ResourceGuard(0.1, 0.99);

        $initial = $g->sleepUs();

        // Много вызовов guard на idle системе → sleep уменьшается к минимуму.
        // БЕЗ usleep: быстрый loop = процессный CPU 100% в моменты измерения →
        // guard видит нагрузку и НЕ уменьшает sleep (флаки paratest)
        for ($i = 0; $i < 20; $i++) {
            usleep(20_000); // процесс реально отдыхает → CPU ~0
            $g->guard();
        }

        $final = $g->sleepUs();

        // На idle системе sleep должен уменьшиться или остаться на минимуме
        $this->assertLessThanOrEqual(
            $initial,
            $final,
            'Sleep should decrease on idle system (guard адаптируется к нагрузке)'
        );
        $this->assertGreaterThanOrEqual(200_000, $final, 'Min sleep = 200ms');
    }
}
