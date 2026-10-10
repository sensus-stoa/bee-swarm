<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\FailedAttemptRegistry;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * S2.3-IGNORANCE (§3.3): failed_attempts — персистентная память отказов.
 *
 * Append-only audit trail (S1.10 дух): каждый НОВЫЙ fingerprint пишется
 * один раз (UNIQUE + INSERT OR IGNORE); повторы того же отказа не плодят
 * строки. Retention нет, история не переписывается.
 */
final class FailedAttemptRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::reset();
        Database::get();
    }

    protected function tearDown(): void
    {
        Database::reset();
        parent::tearDown();
    }

    /**
     * Запись отказа создаёт строку с диагнозом и cv.
     */
    public function testRecordWritesRow(): void
    {
        FailedAttemptRegistry::record('fp_a', 'task_a', 'dom1', 'GRAMMAR', 0.42, '(x0+x1)', 7);

        $row = Database::get()->query(
            'SELECT task_fingerprint, task_name, domain, diagnosis, cv_best, evidence, tick FROM failed_attempts'
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'строка отказа записана');
        $this->assertSame('fp_a', $row['task_fingerprint']);
        $this->assertSame('task_a', $row['task_name']);
        $this->assertSame('dom1', $row['domain']);
        $this->assertSame('GRAMMAR', $row['diagnosis']);
        $this->assertEqualsWithDelta(0.42, (float) $row['cv_best'], 1e-12);
        $this->assertSame('(x0+x1)', $row['evidence']);
        $this->assertSame(7, (int) $row['tick']);
    }

    /**
     * Тот же fingerprint дважды → одна строка (INSERT OR IGNORE).
     */
    public function testDedupByFingerprint(): void
    {
        FailedAttemptRegistry::record('fp_d', 'task_d', 'dom1', 'DEPTH', 9.99, null, 1);
        FailedAttemptRegistry::record('fp_d', 'task_d', 'dom1', 'DEPTH', 9.99, null, 2);

        $n = (int) Database::get()->query('SELECT COUNT(*) FROM failed_attempts')->fetchColumn();
        $this->assertSame(1, $n, 'append-only dedup: первый отказ фиксируется, повтор нет');

        // Другой fingerprint → своя строка
        FailedAttemptRegistry::record('fp_e', 'task_e', 'dom1', 'NOISE', 0.8, null, 3);
        $n = (int) Database::get()->query('SELECT COUNT(*) FROM failed_attempts')->fetchColumn();
        $this->assertSame(2, $n);
    }

    /**
     * Пустой fingerprint НЕ пишется (без идентификатора dedup не имеет
     * смысла; S1.7-прецедент: пустой ключ не «новый»).
     */
    public function testEmptyFingerprintSkipped(): void
    {
        FailedAttemptRegistry::record('', 'task_x', 'dom1', 'GRAMMAR', 0.3, null, 1);

        $n = (int) Database::get()->query('SELECT COUNT(*) FROM failed_attempts')->fetchColumn();
        $this->assertSame(0, $n);
    }

    /**
     * Registry — наблюдатель: ошибка записи не рвёт discovery-путь
     * (критично: вызов стоит в живом тике ДО partialBirth).
     */
    public function testObserverDoesNotThrow(): void
    {
        // Рвём таблицу: DROP → record обязан молча пережить
        Database::get()->exec('DROP TABLE failed_attempts');
        FailedAttemptRegistry::record('fp_z', 'task_z', 'dom1', 'NOISE', 0.9, null, 1);
        $this->assertTrue(true, 'record пережил отсутствие таблицы без исключения');
    }
}
