<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * S2.3-IGNORANCE wiring: отказ живого discovery-пути (FAILED с диагнозом)
 * обязан оставить строку в failed_attempts. Хук — наблюдатель: вызов из
 * doDiscoverTick ПОСЛЕ FAILED-лога, ДО partialBirth (порядок §2.5.2 не
 * меняется). Дедуп по fingerprint: повторный отказ той же задачи не плодит
 * строки.
 *
 * Фикстура живого пути: инжекция пула + один тик (NoveltyBonusTest
 * прецедент), задача с данными, не решаемыми грамматикой → FAILED.
 */
final class FailedAttemptWiringTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Database::reset();
        Database::get();
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        putenv('SEED_ENERGY=10.0');
        putenv('DISSIPATION_ACCEL_TICKS');
    }

    protected function tearDown(): void
    {
        foreach (['NO_BASE_TASKS', 'FORAGER_SOURCES', 'SEED_ENERGY', 'DISSIPATION_ACCEL_TICKS'] as $key) {
            putenv($key);
        }
        foreach ($this->logs as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        Database::reset();
        parent::tearDown();
    }

    /**
     * Задача, гарантированно отказная: 40 строк, y = 1/(1+exp(−x)) —
     * невыразима полиномиальной грамматикой (сигнал есть, класс не покрыт).
     * tMin = max(10, 1×5) = 10 → DATA-гвард проходит, Search честно ищет.
     *
     * @return array<string, mixed>
     */
    private function makeGrammarTask(): array
    {
        mt_srand(31);
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $x = -4.0 + mt_rand() / mt_getrandmax() * 8.0;
            $rows[] = [$x, 1.0 / (1.0 + exp(-$x))];
        }

        return [
            'name' => 'ignorance_probe',
            'domain' => 'ignorance_probe',
            'data' => $rows,
        ];
    }

    /**
     * Hive с выполненным bootstrap (maxTicks: 0 → run() без тиков) + пул.
     *
     * @param array<int, array<string, mixed>> $tasks
     */
    private function newWiringHive(array $tasks): Hive
    {
        $log = (string) tempnam(sys_get_temp_dir(), 's23w_');
        $this->logs[] = $log;
        $hive = new Hive(maxTicks: 0, logFile: $log);
        $hive->run();
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);
        $prop->setValue($hive, $tasks);

        return $hive;
    }

    /**
     * Тик с retry до появления строки в failed_attempts (CPU-guard ловится
     * контентом таблицы, не лог-маркером). Возвращает счётчик строк.
     */
    private function tickUntilRecorded(Hive $hive, \ReflectionMethod $doTick, int $maxAttempts = 3): int
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $doTick->invoke($hive);
            if ($this->failedCount() > 0) {
                break;
            }
        }

        return $this->failedCount();
    }

    private function failedCount(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) FROM failed_attempts')->fetchColumn();
    }

    /**
     * Живой тик с отказной задачей → строка в failed_attempts с диагнозом
     * из {GRAMMAR, NOISE, DEPTH} (live-диагноз зависит от эскалации; DATA
     * невозможна — 40 строк ≥ tMin).
     */
    public function testLiveTickRecordsFailedAttempt(): void
    {
        $hive = $this->newWiringHive([$this->makeGrammarTask()]);
        $doTick = new \ReflectionMethod(Hive::class, 'doTick');
        $doTick->setAccessible(true);

        $n = $this->tickUntilRecorded($hive, $doTick);

        $rows = Database::get()->query(
            'SELECT task_fingerprint, diagnosis FROM failed_attempts'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertGreaterThan(0, $n, 'отказ живого пути обязан оставить failed_attempts-строку');
        $row = $rows[0];
        $this->assertNotSame('', $row['task_fingerprint'], 'fingerprint непуст (S1.7-гвард)');
        $this->assertContains($row['diagnosis'], ['GRAMMAR', 'NOISE', 'DEPTH'], 'диагноз из категорий §3.3');
    }

    /**
     * Повторный тик с той же задачей (тот же fingerprint) не плодит строк:
     * живой путь соблюдает append-only дедуп.
     */
    public function testSecondTickDoesNotDuplicate(): void
    {
        $hive = $this->newWiringHive([$this->makeGrammarTask()]);
        $doTick = new \ReflectionMethod(Hive::class, 'doTick');
        $doTick->setAccessible(true);

        $n = $this->tickUntilRecorded($hive, $doTick);
        $this->assertGreaterThan(0, $n, 'первый отказ записан');

        // Второй тик: та же задача, тот же fingerprint
        $doTick->invoke($hive);
        $nAfter = $this->failedCount();
        $this->assertSame($n, $nAfter, 'dedup: повторный отказ не добавляет строку');
    }
}
