<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\EnvPressure;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * §2.6 Environmental Pressure — WU-1: K-таймаут задач.
 *
 * Контракт протокола: задача, не решённая за K тиков, выбрасывается и
 * считается упущенной возможностью (MISSED_OPPORTUNITY). Возраст задачи =
 * возраст в пуле (env_birth_tick штампуется при вставке; consume-on-pick
 * означает, что «не решена» == «висит в пуле».
 */
final class EnvPressureTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        putenv('ENV_TIMEOUT_K=10');  // детерминированный K
        putenv('TASKS_PER_HOUR');    // R-адмиссия выключена в этом тесте
        // Bench-env (копилка): пустые источники — forager-скан не затирает
        // инжектированный пул задач (на ноутбуке сканяет реальный HOME).
        putenv('FORAGER_SOURCES=:');
        $this->logFile = tempnam(sys_get_temp_dir(), 'envp_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        putenv('ENV_TIMEOUT_K');
        putenv('TASKS_PER_HOUR');
        putenv('FORAGER_SOURCES');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    private function setPool(array $tasks): void
    {
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);
        $prop->setValue($this->hive, $tasks);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pool(): array
    {
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);

        return $prop->getValue($this->hive);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runCollect(int $tick): array
    {
        $m = new \ReflectionMethod(Hive::class, 'collectTimeouts');
        $m->setAccessible(true);

        return $m->invokeArgs($this->hive, [$tick]);
    }

    /**
     * Свежая задача не выбрасывается.
     */
    public function testFreshTaskNotCollected(): void
    {
        $t = [
            'name' => 'env_fresh',
            'domain' => 'metrics',
            'data' => [[1.0, 2.0]],
        ];
        $this->setPool([EnvPressure::stamp($t, tick: 100)]);
        $collected = $this->runCollect(105);

        self::assertSame([], $collected, 'свежая задача (age 5 < K=10) жива');
        self::assertCount(1, $this->pool(), 'пул не уменьшился');
    }

    /**
     * Задача старше K выбрасывается, лог MISSED_OPPORTUNITY.
     */
    public function testExpiredTaskCollected(): void
    {
        $t = [
            'name' => 'env_old',
            'domain' => 'metrics',
            'data' => [[1.0, 2.0]],
        ];
        $this->setPool([EnvPressure::stamp($t, tick: 100)]);
        $collected = $this->runCollect(115);

        self::assertCount(1, $collected, 'задача age 15 > K=10 выброшена');
        self::assertSame('env_old', $collected[0]['name'] ?? null);
        self::assertCount(0, $this->pool(), 'пул освободился');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('MISSED_OPPORTUNITY: env_old', $log);
    }

    /**
     * K из env (ENV_TIMEOUT_K), не хардкод.
     */
    public function testTimeoutFromEnv(): void
    {
        putenv('ENV_TIMEOUT_K=3');
        $t = [
            'name' => 'env_env',
            'domain' => 'metrics',
            'data' => [[1.0, 2.0]],
        ];
        $this->setPool([EnvPressure::stamp($t, tick: 50)]);
        $collected = $this->runCollect(54);

        self::assertCount(1, $collected, 'age 4 > K=3 (env) → выброс');
    }

    /**
     * Граница: age == K — уже выброс (задача прожила ровно свой срок).
     */
    public function testBoundaryAgeEqualsKCollected(): void
    {
        $t = [
            'name' => 'env_edge',
            'domain' => 'metrics',
            'data' => [[1.0, 2.0]],
        ];

        $this->setPool([EnvPressure::stamp($t, tick: 100)]);
        $collected = $this->runCollect(110);
        self::assertCount(1, $collected, 'age == K → выброс');
    }

    /**
     * Unstamped задача (legacy-пул) не выбрасывается — гвард null.
     */
    public function testUnstampedTaskNeverCollected(): void
    {
        $t = [
            'name' => 'env_legacy',
            'domain' => 'metrics',
            'data' => [[1.0, 2.0]],
        ];
        $this->setPool([$t]);
        $collected = $this->runCollect(99999);

        self::assertSame([], $collected, 'legacy-задача без штампа бессмертна (fail-safe)');
        self::assertCount(1, $this->pool());
    }

    /**
     * Wiring: collectTimeouts вызывается из живого тика doTick.
     */
    public function testWiringTickCallsCollect(): void
    {
        // Просроченная задача: штамп tick=-9, K=10 → на тике 1 age=10 → выброс.
        $t = [
            'name' => 'env_wire',
            'domain' => 'metrics',
            'data' => [[1.0, 2.0]],
        ];
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);
        $prop->setValue($this->hive, [EnvPressure::stamp($t, tick: -9)]);

        $hive = new Hive(maxTicks: 1, logFile: $this->logFile);
        $prop->setValue($hive, [EnvPressure::stamp($t, tick: -9)]);
        $hive->run();

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('MISSED_OPPORTUNITY: env_wire', $log, 'живой тик выкидывает просроченную задачу');
        $after = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $after->setAccessible(true);
        self::assertCount(0, $after->getValue($hive), 'пул живого тика освободился');
    }

    /**
     * R-адмиссия выключена (env не задан) → пропускаем всё.
     */
    public function testAdmissionDisabledWithoutEnv(): void
    {
        putenv('TASKS_PER_HOUR');  // снять
        $tasks = [[
            'name' => 'r1',
        ], [
            'name' => 'r2',
        ]];
        [$admitted, $discarded, $logs] = EnvPressure::admitAll($tasks, 1000.0);

        self::assertCount(2, $admitted);
        self::assertSame([], $discarded);
        self::assertSame([], $logs, 'выключенный лимит не логируется');
    }

    /**
     * R-адмиссия: больше R в скользящий час не пускаем, лишнее — R_DISCARD.
     */
    public function testAdmissionCapsAtR(): void
    {
        putenv('TASKS_PER_HOUR=3');
        EnvPressure::resetAdmission();
        $tasks = [[
            'name' => 'r1',
        ], [
            'name' => 'r2',
        ], [
            'name' => 'r3',
        ], [
            'name' => 'r4',
        ], [
            'name' => 'r5',
        ]];
        [$admitted, $discarded, $logs] = EnvPressure::admitAll($tasks, 1000.0);

        self::assertCount(3, $admitted, 'ровно R пущено');
        self::assertCount(2, $discarded);
        self::assertSame('R_DISCARD: r4 R=3', $logs[0]);
        self::assertSame('R_DISCARD: r5 R=3', $logs[1]);
    }

    /**
     * Скользящее окно: через час + 1с места снова есть.
     */
    public function testAdmissionWindowExpires(): void
    {
        putenv('TASKS_PER_HOUR=1');
        EnvPressure::resetAdmission();
        [, $d1] = EnvPressure::admitAll([[
            'name' => 'a',
        ]], 1000.0);
        self::assertSame([], $d1);

        // 3599с — окно ещё держит
        [, $d2] = EnvPressure::admitAll([[
            'name' => 'b',
        ]], 1000.0 + 3599.0);
        self::assertCount(1, $d2, 'внутри часа — лимит держит');

        // 3601с — окно истекло
        [$adm3, $d3] = EnvPressure::admitAll([[
            'name' => 'c',
        ]], 1000.0 + 3601.0);
        self::assertCount(1, $adm3, 'за часом — окно пустое, пускает');
        self::assertSame([], $d3);
    }
}
