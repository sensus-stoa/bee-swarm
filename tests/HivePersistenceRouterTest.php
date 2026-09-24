<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

/**
 * P1-RESTORE-ROUTER: RESTORE-ветка Hive::bootstrap() делает ранний return
 * ДО создания TaskRouter / OverlapTracker / dormantPool и всего post-init
 * хвоста. После рестарта демона с непустой bee_persistence taskRouter=null
 * навсегда → routedBee=null → ENERGY_REFUSAL на каждый тик при живом рое
 * (bugs/bug-restore-no-taskrouter.md).
 *
 * RED: RESTORE-путь → taskRouter === null, ROUTE: отсутствует в логе.
 * GREEN: RESTORE проваливается в общий init (гварды === null уже стоят,
 * cold-start ветка остаётся).
 */
final class HivePersistenceRouterTest extends TestCase
{
    /** @var list<string> */
    private array $logs = [];

    /** @var list<string> */
    private array $envKeys = ['NO_BASE_TASKS', 'FORAGER_SOURCES', 'NO_NOVELTY', 'SPWN_POOL', 'DISSIPATION_ACCEL_TICKS', 'CORPUS_DIRS'];

    protected function setUp(): void
    {
        parent::setUp();
        // Голодающая среда: пул инжектируется вручную (устойчиво к
        // forager-scan), corpus off (иначе CorpusVocabulary ходит в HOME).
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_NOVELTY');
        putenv('SPWN_POOL');
        putenv('DISSIPATION_ACCEL_TICKS');
        putenv('CORPUS_DIRS=:');
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key); // putenv($k) снимает переменную (phpunit-env-unset-pitfall)
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
     * Контракт P1: после RESTORE TaskRouter создан.
     */
    public function testRestorePathCreatesTaskRouter(): void
    {
        $this->seedPersistence(2);
        $log = $this->newLog();
        $hive = new Hive(maxTicks: 0, logFile: $log);
        $hive->run();

        $content = (string) file_get_contents($log);
        $this->assertStringContainsString('RESTORE:', $content, 'Санити: RESTORE-ветка реально выполнена (иначе тест мёртвый)');

        $this->assertNotNull(
            $this->readProp($hive, 'taskRouter'),
            'RESTORE обязан создать TaskRouter (ранний return до init = роутинг мёртв)'
        );
    }

    /**
     * Аудит остальных post-RESTORE-return зависимостей (тот же класс бага).
     */
    public function testRestorePathInitializesOverlapAndDormant(): void
    {
        $this->seedPersistence(2);
        $log = $this->newLog();
        $hive = new Hive(maxTicks: 0, logFile: $log);
        $hive->run();

        $this->assertNotNull(
            $this->readProp($hive, 'overlapTracker'),
            'overlapTracker создаётся после RESTORE'
        );
        $this->assertTrue(
            $this->propIsSet($hive, 'dormantPool'),
            'dormantPool инициализирован в bootstrap после RESTORE'
        );
    }

    /**
     * Живой путь: тик после RESTORE маршрутизирует задачу (ROUTE: в логе).
     * Retry ×3: CPU-guard может выйти до routing — счёт ДО/ПОСЛЕ, не
     * presence-проверка (прецедент NoveltyBonusTest).
     */
    public function testRestorePathRoutesTaskAfterRestart(): void
    {
        $this->seedPersistence(2);
        $log = $this->newLog();
        $hive = new Hive(maxTicks: 0, logFile: $log);
        $hive->run();
        $this->setPool($hive, [$this->makeTask('restore_probe')]);

        $routesBefore = substr_count((string) file_get_contents($log), 'ROUTE:');
        $method = new \ReflectionMethod(Hive::class, 'doTick');
        $method->setAccessible(true);
        $routed = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $method->invoke($hive);
            if (substr_count((string) file_get_contents($log), 'ROUTE:') > $routesBefore) {
                $routed = true;
                break;
            }
        }

        $this->assertTrue($routed, 'После RESTORE роутинг жив: ROUTE: в логе живого тика');
    }

    /**
     * bee_persistence с N живыми пчёлами → следующий Hive уйдёт в
     * RESTORE-ветку (loadPopulation non-null).
     */
    private function seedPersistence(int $n): void
    {
        $db = Database::get();
        $db->exec('DELETE FROM bee_persistence');
        $stmt = $db->prepare('INSERT INTO bee_persistence (grammar, energy, is_alive) VALUES (?, ?, 1)');
        foreach (range(1, $n) as $i) {
            $stmt->execute([json_encode(['+', '*']), 10.0]);
        }
    }

    private function newLog(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'restore_');
        $this->logs[] = $file;

        return $file;
    }

    private function readProp(Hive $hive, string $name): mixed
    {
        $prop = new \ReflectionProperty(Hive::class, $name);
        $prop->setAccessible(true);

        return $prop->getValue($hive);
    }

    /**
     * isset через bound-closure: для non-nullable typed property (dormantPool)
     * прямое чтение uninitialized бросает Error, isset — нет.
     */
    private function propIsSet(Hive $hive, string $name): bool
    {
        $fn = \Closure::bind(fn (): bool => isset($this->{$name}), $hive, Hive::class);

        return (bool) $fn();
    }

    /**
     * Задача без ключа data: проходит filterInsufficient и DifficultyGovernor,
     * доходит до routing (прецедент NoveltyBonusTest::makeTask).
     *
     * @return array<string, mixed>
     */
    private function makeTask(string $name): array
    {
        return [
            'name' => $name,
            'domain' => 'restore_probe',
        ];
    }

    private function setPool(Hive $hive, array $tasks): void
    {
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);
        $prop->setValue($hive, $tasks);
    }
}
