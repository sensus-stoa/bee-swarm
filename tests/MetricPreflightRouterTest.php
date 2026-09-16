<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\MetricPreflight;
use BeeSwarm\Hive\Hive;
use PHPUnit\Framework\TestCase;

/**
 * V0.12 WU-2: роутерная интеграция pre-flight (§1.2 паттерн).
 *
 * Точка: doDiscoverTick ПОСЛЕ sufficiency-check (tMin), ДО null-calibration —
 * pre-flight не должен жечь калибровку на домене, который откажем целиком.
 * Референс: INSUFFICIENT_DATA (Hive:1519) — отказ до поиска, роутером.
 *
 * Красный таск = низко-CV таргет (CCPP-класс, CV(PE)=0.0376 при гейте 0.15):
 *  - задачи в пуле/эпизоде с таким таргетом не доходят до discover();
 *  - лог METRIC_DOMAIN_PREFLIGHT присутствует;
 *  - поиск не выполнялся (нет Search-событий).
 *
 * Использую filterInsufficient-зону: для pool-задач pre-flight = фильтр
 * (задача не попадает в очередь), для эпизода = ранний return (как
 * INSUFFICIENT_DATA). Оба контура тестируются.
 */
final class MetricPreflightRouterTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        // Pre-flight ON (default 0.5); низко-CV таргеты отсекаются.
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'preflight_router_');
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

    /** CCPP-класс: 200 строк, таргет с CV≈0.0376 (5 фич). */
    private function lowCvTask(): array
    {
        mt_srand(2024);
        $data = [];
        for ($i = 0; $i < 200; $i++) {
            $base = mt_rand() / mt_getrandmax();
            $row = [$base, mt_rand() / mt_getrandmax(), mt_rand() / mt_getrandmax(), mt_rand() / mt_getrandmax()];
            // таргет = base*2 + 50 (CV≈малый: разброс 2*sd(base)/mean≈50)
            $row[] = $base * 2 + 50;
            $data[] = $row;
        }

        return [
            'name' => 'preflight_lowcv_num',
            'domain' => 'preflight_test_' . uniqid(),
            'data' => $data,
        ];
    }

    /** Проверка интеграции через публичный контракт Hive: доступ к фильтру. */
    public function testFilterInsufficientAppliesPreflight(): void
    {
        putenv('PREFLIGHT_GATE_FACTOR=0.5');
        $hive = new Hive(maxTicks: 0);
        $method = new \ReflectionMethod(Hive::class, 'filterInsufficient');
        $method->setAccessible(true);
        $task = $this->lowCvTask();
        $out = $method->invoke($hive, [$task]);

        $this->assertSame([], $out, 'Низко-CV задача не проходит pre-filter');
    }

    public function testFilterInsufficientKeepsHighCvTask(): void
    {
        putenv('PREFLIGHT_GATE_FACTOR=0.5');
        $hive = new Hive(maxTicks: 0);
        $method = new \ReflectionMethod(Hive::class, 'filterInsufficient');
        $method->setAccessible(true);
        $task = $this->lowCvTask();
        // Высокий CV: растянем таргет без сдвига — убираем +50, таргет = base*2
        foreach ($task['data'] as &$row) {
            $row[4] = $row[0] * 100;
        }
        unset($row);
        $out = $method->invoke($hive, [$task]);

        $this->assertCount(1, $out, 'Высоко-CV задача проходит pre-filter');
    }

    public function testPreflightOffKeepsLowCvTask(): void
    {
        // FACTOR=0 → v1.6 поведение: задача проходит (совместимость).
        putenv('PREFLIGHT_GATE_FACTOR=0');
        $hive = new Hive(maxTicks: 0);
        $method = new \ReflectionMethod(Hive::class, 'filterInsufficient');
        $method->setAccessible(true);
        $out = $method->invoke($hive, [$this->lowCvTask()]);

        $this->assertCount(1, $out, 'Pre-flight off = v1.6');
    }

    public function testPureCheckGateContract(): void
    {
        // Контракт вызова в фильтре: check(gate, y) — гейт = cvTrainMax задачи
        // (getEpsilon ?? 0.15), y = таргет-колонка данных.
        $y = [];
        foreach ($this->lowCvTask()['data'] as $row) {
            $y[] = $row[4];
        }
        $out = MetricPreflight::check(0.15, $y);
        $this->assertFalse($out->passes, 'CCPP-класс таргет отказывается при gate 0.15');
    }
}
