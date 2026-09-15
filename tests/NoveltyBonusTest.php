<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\TaskRouter;
use BeeSwarm\Infra\Database;
use BeeSwarm\Infra\PlateauDetector;
use PHPUnit\Framework\TestCase;

/**
 * S1.7-NOVELTY: Novelty Bonus — контракт wiring-пути.
 *
 * Механика живая (Bee::rewardNovelty +0.5, doTick novelty-блок), но контракт
 * не закрыт: static $seenFingerprints = process-lifetime + test-poison,
 * wiring-путь не покрыт, пустой fingerprint не гвардится.
 *
 * Бессdata-задача (domain novelty_probe) доходит до routing/novelty и выходит
 * по early-return ДО Search — быстрый детерминированный путь. Дельта энергии
 * считается по СУММЕ роя: routedBee стохастичен (exploration array_rand),
 * сумма — нет (+0.5 бонус − 3 × 0.01 tick-cost).
 */
final class NoveltyBonusTest extends TestCase
{
    /**
     * Бонус novelty (буква стори — не менять).
     */
    private const NOVELTY_BONUS = 0.5;

    /**
     * @var list<string>
     */
    private array $logs = [];

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        // Детерминизм: голодная среда (только инжектированный пул), скан off,
        // энергия сидов запинена; гасим утечки env из соседних тестов.
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        putenv('SEED_ENERGY=10.0');
        putenv('NO_NOVELTY');
        putenv('SPWN_POOL');
        putenv('DISSIPATION_ACCEL_TICKS');
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        foreach (['NO_BASE_TASKS', 'FORAGER_SOURCES', 'SEED_ENERGY', 'NO_NOVELTY', 'SPWN_POOL', 'DISSIPATION_ACCEL_TICKS'] as $key) {
            putenv($key);
        }
        foreach ($this->logs as $log) {
            if (is_file($log)) {
                unlink($log);
            }
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * Новая задача даёт энергию пчеле
     */
    public function testNoveltyBonusExists(): void
    {
        // Проверяем что константа определена в коде
        $code = file_get_contents(__DIR__ . '/../src/Hive/Hive.php');
        $this->assertStringContainsString('NOVELTY', $code, 'Novelty bonus constant must exist');
    }

    /**
     * Bee получает +0.5 за exploration
     */
    public function testBeeGainsNoveltyEnergy(): void
    {
        $bee = new Bee(['add', 'mul'], 5.0);
        $energyBefore = $bee->energy();

        // Симулируем novelty reward
        $bee->rewardNovelty();

        $this->assertEqualsWithDelta($energyBefore + 0.5, $bee->energy(), 0.001, 'Novelty gives +0.5 energy');
    }

    /**
     * WU-1: два Hive в одном процессе независимы (static → instance).
     * Static $seenFingerprints переживает свой Hive: второй Hive с тем же
     * fingerprint не получает novelty — process-lifetime семантика.
     */
    public function testNoveltyIsPerHiveInstance(): void
    {
        $logA = $this->runNoveltyTick();
        $logB = $this->runNoveltyTick();

        $contentA = (string) file_get_contents($logA);
        $contentB = (string) file_get_contents($logB);

        self::assertSame(1, substr_count($contentA, 'NOVELTY:'), 'Hive A: novelty за новый fingerprint');
        self::assertSame(
            1,
            substr_count($contentB, 'NOVELTY:'),
            'Hive B: novelty не должна быть потрачена чужим Hive (static-poison)'
        );
    }

    /**
     * WU-2: wiring-контракт живого пути — первое выполнение задачи с
     * fingerprint X даёт +0.5 РОВНО ОДИН РАЗ, повторное — ноль.
     *
     * Оба тика — ОДИН Hive-instance, пул refill'ится twin-задачей (второе
     * имя, тот же fingerprint — иначе consume-on-pick оставляет пустой пул
     * и тик 2 выходит по early return с другой структурой). Мера = СУММА
     * обоих тиков: GAP_SPAWN (forced seed при threshold=0) стреляет в тике 1
     * с cooldown'ом в тике 2 — асимметрична ПО ТИКАМ, симметрична ПО ПАРЕ;
     * baseline (NO_NOVELTY) имеет ту же структуру → разница = чистый бонус.
     */
    public function testWiringNoveltyOncePerFingerprint(): void
    {
        $baseline = $this->wiringEnergyBaseline();

        [$log, $hive] = $this->makeWiringHive();

        $sum = 0.0;
        $sum += $this->tickAndMeasure($hive, $log);
        $this->setPool($hive, [$this->makeTask('novelty_probe_twin')]);
        $sum += $this->tickAndMeasure($hive, $log);

        self::assertEqualsWithDelta(
            self::NOVELTY_BONUS,
            $sum - $baseline,
            0.001,
            'Пара тиков: рой получает ровно +0.5 novelty сверх baseline'
        );

        $content = (string) file_get_contents($log);
        self::assertSame(1, substr_count($content, 'NOVELTY:'), 'Ровно один NOVELTY-лог за два тика');
    }

    /**
     * WU-2: NO_NOVELTY=1 гвард — wiring-путь не выдаёт бонус.
     * Прямой вызов rewardNovelty не проверяет гвард (он в Hive::doTick).
     */
    public function testWiringNoNoveltyEnvGuard(): void
    {
        putenv('NO_NOVELTY=1');
        try {
            $baseline = $this->wiringEnergyBaseline();
            [$log, $hive] = $this->makeWiringHive();
            // Пара тиков — та же структура, что baseline и main-ветка WU-2
            // (F2, agent-review): разница = чистый novelty-эффект.
            $delta = $this->tickAndMeasure($hive, $log);
            $this->setPool($hive, [$this->makeTask('novelty_probe_twin')]);
            $delta += $this->tickAndMeasure($hive, $log);
        } finally {
            // H3 (premortem): исключение между putenv и снятием оставило бы
            // флаг в env воркера (-p8) → order-dependent краснота.
            putenv('NO_NOVELTY');
        }

        self::assertEqualsWithDelta(
            0.0,
            $delta - $baseline,
            0.001,
            'NO_NOVELTY=1: бонус не выдан'
        );
        $content = (string) file_get_contents($log);
        self::assertSame(0, substr_count($content, 'NOVELTY:'), 'NO_NOVELTY=1: нет NOVELTY-лога');
    }

    /**
     * WU-2: fingerprint стабильность — TaskRouter::fingerprint одной задачи
     * дважды = одна строка (иначе novelty вырождается: каждая задача «новая»).
     */
    public function testFingerprintStableAcrossCalls(): void
    {
        $router = new TaskRouter([], 0);
        $task = $this->makeTask();

        self::assertSame(
            $router->fingerprint($task),
            $router->fingerprint($task),
            'fingerprint детерминирован на идентичной задаче'
        );
    }

    /**
     * F5-хвост (S1.6-стиль): пустой fingerprint ('') — NOVELTY не выдаётся.
     * Явный контракт: isset('') ловит, но тест фиксирует семантику.
     */
    public function testWiringEmptyFingerprintNoNovelty(): void
    {
        $hive = $this->newBootstrappedHive($log = $this->newLog(), new PlateauDetector(0));
        $this->setPool($hive, [$this->makeTask()]);
        // Инжекция TaskRouter-заглушки: fingerprint() всегда ''. Пчёлы РЕАЛЬНЫЕ
        // (иначе route() → null → routedBee null → блок novelty не выполняется —
        // тест проходил бы по обходному пути, не по гварду).
        $bees = $this->bees($hive);
        $stub = new class($bees) extends TaskRouter {
            public function fingerprint(array $task): string
            {
                return '';
            }
        };
        $prop = new \ReflectionProperty(Hive::class, 'taskRouter');
        $prop->setAccessible(true);
        $prop->setValue($hive, $stub);
        $this->runOneTick($hive, $log);

        $content = (string) file_get_contents($log);
        self::assertSame(1, substr_count($content, 'ROUTE:'), 'Санити: route выполнен (иначе тест weak)');
        self::assertSame(
            0,
            substr_count($content, 'NOVELTY:'),
            'Пустой fingerprint: NOVELTY не выдаётся (гвард isset(\'\'))'
        );
    }

    private function newLog(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'novelty_');
        $this->logs[] = $file;

        return $file;
    }

    /**
     * Hive с выполненным bootstrap (maxTicks: 0 → run() без тиков).
     * Инжекция пула ПОСЛЕ bootstrap устойчива к forager-scan.
     */
    private function newBootstrappedHive(string $logFile, ?PlateauDetector $plateau = null): Hive
    {
        $hive = new Hive(maxTicks: 0, logFile: $logFile, plateau: $plateau);
        $hive->run();

        return $hive;
    }

    /**
     * Задача БЕЗ ключа data: проходит filterInsufficient (!isset → pass) и
     * DifficultyGovernor, доходит до routing/novelty (раньше discovery-блока),
     * Search не запускает (early return по empty($data) — уже после novelty).
     *
     * @return array<string, mixed>
     */
    private function makeTask(string $name = 'novelty_probe'): array
    {
        return [
            'name' => $name,
            'domain' => 'novelty_probe',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    private function setPool(Hive $hive, array $tasks): void
    {
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);
        $prop->setValue($hive, $tasks);
    }

    /**
     * @return list<Bee>
     */
    private function bees(Hive $hive): array
    {
        $prop = new \ReflectionProperty(Hive::class, 'bees');
        $prop->setAccessible(true);
        $bees = $prop->getValue($hive);
        self::assertIsArray($bees);

        return $bees;
    }

    private function energySum(Hive $hive): float
    {
        $sum = 0.0;
        foreach ($this->bees($hive) as $bee) {
            $sum += $bee->energy();
        }

        return $sum;
    }

    /**
     * Один тик через reflection (tick=0). Retry до 3 попыток: CPU-guard
     * (load > 0.7×nproc) выходит ДО routing — следующий вызов выполняет работу.
     * Счёт ДО/ПОСЛЕ (F1, agent-review): presence-проверка 'ROUTE:' мертва
     * на тике ≥2 (строка уже в логе с тика 1) — CPU-guard на таком вызове
     * измерил бы нулевой тик как валидный.
     */
    private function runOneTick(Hive $hive, string $logFile): void
    {
        $method = new \ReflectionMethod(Hive::class, 'doTick');
        $method->setAccessible(true);
        $routesBefore = substr_count((string) file_get_contents($logFile), 'ROUTE:');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $method->invoke($hive);
            if (substr_count((string) file_get_contents($logFile), 'ROUTE:') > $routesBefore) {
                return;
            }
        }
    }

    private function runNoveltyTick(): string
    {
        $log = $this->newLog();
        $hive = $this->newBootstrappedHive($log);
        $this->setPool($hive, [$this->makeTask()]);
        $this->runOneTick($hive, $log);

        return $log;
    }

    /**
     * WU-2: Hive в plateau-конфигурации + задача в пуле (до measure).
     * PlateauDetector(0) → getTasks идёт по plateau-ветке: пул = только
     * инжектированные задачи (compose-генерация отключена плато).
     *
     * @return array{0: string, 1: Hive}
     */
    private function makeWiringHive(): array
    {
        $log = $this->newLog();
        $hive = $this->newBootstrappedHive($log, new PlateauDetector(0));

        return [$log, $hive];
    }

    /**
     * WU-2: один тик + энергодельта роя.
     */
    private function tickAndMeasure(Hive $hive, string $log): float
    {
        $before = $this->energySum($hive);
        $this->runOneTick($hive, $log);

        return $this->energySum($hive) - $before;
    }

    /**
     * Baseline энергии: NO_NOVELTY=1 прогоны, медиана трёх.
     * ПАРА тиков на итерацию (F2, agent-review): структурная идентичность с
     * main-веткой WU-2 — вся энергия тиков (tick-cost, GAP_SPAWN-pair,
     * bookkeeping) взаимно сокращается, остаётся только novelty-бонус.
     * Однотиковый baseline сходился бы на нулевом тике 2 — хрупко.
     */
    private function wiringEnergyBaseline(): float
    {
        $put = getenv('NO_NOVELTY');
        putenv('NO_NOVELTY=1');
        $sums = [];
        for ($i = 0; $i < 3; $i++) {
            [$log, $hive] = $this->makeWiringHive();
            $sum = $this->tickAndMeasure($hive, $log);
            $this->setPool($hive, [$this->makeTask('novelty_probe_twin')]);
            $sum += $this->tickAndMeasure($hive, $log);
            $sums[] = $sum;
        }
        putenv($put === false ? 'NO_NOVELTY' : 'NO_NOVELTY=' . $put);
        sort($sums);

        return $sums[1];
    }
}
