<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\DormantPool;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

/**
 * DORMANT-CONFIG (24.09, premortem И-5): мёртвый $maxDormant — env
 * DORMANT_POOL_MAX вычислялся «для OOM-защиты» (SPAWN-POOL 27.08) и
 * выбрасывался; DormantPool deposit() кладёт безусловно → BP-лавина /
 * burst раздувает пул внутри окна age(maxAge). Фикс: capacity в пул,
 * wiring в Hive (bootstrap + lazy dormantPool()).
 *
 * Контракт eviction: пул держит ЛУЧШИЕ рецепты — вылетает минимальная
 * novelty (при равенстве старший age — ближе к natural removal по age()).
 * Awakened pinned (уходят по awakenedTimeout), eviction их не трогает.
 * Худший приход вытесняет сам себя — новые рецепты не получают бONUS'a
 * только за новизну.
 */
final class DormantPoolCapacityTest extends TestCase
{
    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // wiring-тестам нужен быстрый bootstrap без внешних сканов
        putenv('DORMANT_POOL_MAX'); // снятие = default-путь
        putenv('FORAGER_SOURCES=:');
        putenv('CORPUS_DIRS=:');
        putenv('NO_BASE_TASKS=1');
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        foreach (['DORMANT_POOL_MAX', 'FORAGER_SOURCES', 'CORPUS_DIRS', 'NO_BASE_TASKS'] as $key) {
            putenv($key);
        }
        foreach ($this->logs as $log) {
            if (is_file($log)) {
                unlink($log);
            }
        }
        Database::setPath(':memory:');
        Database::reset();
        parent::tearDown();
    }

    /**
     * Capacity 3, приход 0.95 на пуле {0.9, 0.8, 0.7}: вытесняется
     * резидент 0.7 (минимальный merit), все трое лучших остаются.
     */
    public function testDepositEvictsLowestNoveltyAtCapacity(): void
    {
        $pool = new DormantPool(300, 3);
        foreach ([0.9, 0.8, 0.7, 0.95] as $i => $novelty) {
            $pool->deposit(['op' => '×', 'i' => $i], 'X', $novelty);
        }

        $this->assertSame(3, $pool->size(), 'capacity ограничивает размер пула');
        $novelties = array_column($pool->awaken(3, ['X' => 3]), 'novelty');
        sort($novelties);
        $this->assertSame([0.8, 0.9, 0.95], $novelties, 'вытеснен резидент 0.7, топ-3 сохранены');
    }

    /**
     * Худший приход (novelty ниже всех резидентов) вытесняет сам себя —
     * пул не деградирует, новые рецепты не держатся только за новизну.
     */
    public function testWorstArrivalEvictsItself(): void
    {
        $pool = new DormantPool(300, 2);
        $pool->deposit(['op' => '×'], 'X', 0.9);
        $pool->deposit(['op' => '×'], 'X', 0.8);
        $pool->deposit(['op' => '×'], 'X', 0.1);

        $this->assertSame(2, $pool->size());
        $novelties = array_column($pool->awaken(2, ['X' => 2]), 'novelty');
        sort($novelties);
        $this->assertSame([0.8, 0.9], $novelties, 'приход 0.1 вытеснен, резиденты целы');
    }

    /**
     * Total-capacity контракт: pinned (awakened) тоже занимают ёмкость —
     * OOM-защита ограничивает память пула целиком (pinned уходят по
     * awakenedTimeout). Eviction не трогает pinned: два вытеснения забирают
     * приход-0.7 (сам себя) и резидента 0.8 (минимальный merit), 0.9-pinned
     * выживает — приход 0.85 не получает бонуса за новизну.
     */
    public function testAwakenedPinnedBeyondCapacity(): void
    {
        $pool = new DormantPool(300, 2);
        $pool->deposit(['op' => '×'], 'X', 0.9);
        $pool->deposit(['op' => '×'], 'X', 0.8);
        $pinned = $pool->awaken(1, ['X' => 1]);
        $this->assertCount(1, $pinned, 'санити: 0.9 pinned');

        $pool->deposit(['op' => '×'], 'X', 0.7); // перелив: не-awakened min = 0.7 (приход сам)
        $pool->deposit(['op' => '×'], 'X', 0.85); // перелив: min merit = 0.8 (резидент)

        $this->assertSame(2, $pool->size(), 'total-capacity: pinned занимает ёмкость');
        $novelties = array_column($pool->awaken(10, ['X' => 10]), 'novelty');
        $this->assertSame([0.85], $novelties, 'awaken возвращает только не-awakened; 0.9 pinned выжил оба вытеснения');
    }

    /**
     * Wiring: env DORMANT_POOL_MAX течёт в capacity пула (bootstrap-путь).
     */
    public function testHiveWiresDormantPoolMaxEnv(): void
    {
        putenv('DORMANT_POOL_MAX=1234');
        $capacity = $this->bootstrappedPoolCapacity();

        $this->assertSame(1234, $capacity, 'env DORMANT_POOL_MAX -> DormantPool capacity (мёртвый $maxDormant ожил)');
    }

    /**
     * Default 50000, floor 1000 (max(1000, env) из исходного замысла) и
     * PHP-falsy гвард: env='0' — строка, старый `?:` молча подставил бы
     * default (50000), гвард !== false даёт 1000 (criterion-audit).
     */
    public function testHiveWiresDefaultAndFloor(): void
    {
        $this->assertSame(50000, $this->bootstrappedPoolCapacity(), 'без env = default 50000');

        putenv('DORMANT_POOL_MAX=5');
        $this->assertSame(1000, $this->bootstrappedPoolCapacity(), 'floor 1000: env ниже пола поднимается до 1000');

        putenv('DORMANT_POOL_MAX=0');
        $this->assertSame(1000, $this->bootstrappedPoolCapacity(), "PHP-falsy гвард: env='0' -> floor 1000, не default 50000");
    }

    /**
     * All-pinned переполнение (criterion-audit (б)): awaken не удаляет
     * резидентов — при cap 1 deposit→awaken→deposit даёт переполнение без
     * не-awakened жертв → новоприбывший вытесняет сам себя.
     */
    public function testAllPinnedOverflowSelfEvicts(): void
    {
        $pool = new DormantPool(300, 1);
        $pool->deposit(['op' => '×'], 'X', 0.9);
        $pinned = $pool->awaken(1, ['X' => 1]);
        $this->assertCount(1, $pinned, 'санити: единственный резидент pinned');

        $id = $pool->deposit(['op' => '×'], 'X', 0.5);

        $this->assertSame(1, $pool->size(), 'total-capacity при all-pinned: newcomer self-evicted');
        $novelties = array_column($pool->awaken(10, ['X' => 10]), 'novelty');
        $this->assertSame([], $novelties, 'awaken видит только не-awakened; pinned 0.9 цел');
        unset($id); // self-evicted id не гарантирует запись (PHPDoc deposit)
    }

    /**
     * Bootstrap (maxTicks:0 -> run()) -> reflection dormantPool.capacity.
     */
    private function bootstrappedPoolCapacity(): int
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'dormcfg_');
        $this->logs[] = $logFile;

        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        $poolProp = new \ReflectionProperty(Hive::class, 'dormantPool');
        $poolProp->setAccessible(true);
        $pool = $poolProp->getValue($hive);
        self::assertInstanceOf(DormantPool::class, $pool);

        $capProp = new \ReflectionProperty(DormantPool::class, 'capacity');
        $capProp->setAccessible(true);
        $capacity = $capProp->getValue($pool);
        self::assertIsInt($capacity);

        return $capacity;
    }
}
