<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\OverlapTracker;

/**
 * Story V0.8: Overlap Tracking (§1.8)
 *
 * Phase 1: OverlapTracker — запись в overlap_log при смене пчелы на задаче.
 */
class OverlapTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM overlap_log");
    }

    /**
     * Первое назначение задачи — overlap не записывается (не с чем сравнивать).
     *
     * Predicted: FAIL — класс OverlapTracker не существует.
     */
    public function testFirstAssignmentDoesNotRecordOverlap(): void
    {
        $tracker = new OverlapTracker();
        $tracker->recordTaskAttempt('task_x', 0, 'x0+x1');

        $rows = \BeeSwarm\Infra\Database::get()
            ->query("SELECT COUNT(*) FROM overlap_log")
            ->fetchColumn();

        $this->assertSame(0, (int) $rows, 'First assignment must not create overlap');
    }

    /**
     * Второе назначение той же задачи другой пчеле → overlap.
     *
     * Predicted: FAIL — класс OverlapTracker не существует.
     */
    public function testSecondAssignmentDifferentBeeRecordsOverlap(): void
    {
        $tracker = new OverlapTracker();
        $tracker->recordTaskAttempt('task_x', 0, 'x0+x1');
        $tracker->recordTaskAttempt('task_x', 1, 'x0+x1');

        $rows = \BeeSwarm\Infra\Database::get()
            ->query("SELECT * FROM overlap_log ORDER BY id")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows, 'Second assignment to different bee must create 1 overlap record');
        $this->assertSame('0', $rows[0]['bee_a']);
        $this->assertSame('1', $rows[0]['bee_b']);
        $this->assertSame('task_x', $rows[0]['task']);
        $this->assertEquals(1, $rows[0]['matched'], 'Same answer → matched=1');
    }

    /**
     * Разные ответы → matched=0.
     *
     * Predicted: FAIL — класс не существует.
     */
    public function testDifferentAnswersRecordUnmatched(): void
    {
        $tracker = new OverlapTracker();
        $tracker->recordTaskAttempt('task_y', 0, 'x0+x1');
        $tracker->recordTaskAttempt('task_y', 2, 'x0−x1');

        $row = \BeeSwarm\Infra\Database::get()
            ->query("SELECT * FROM overlap_log ORDER BY id DESC LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(0, $row['matched'], 'Different answers → matched=0');
    }

    /**
     * Одна и та же пчела на той же задаче — не overlap.
     *
     * Predicted: FAIL — класс не существует.
     */
    public function testSameBeeTwiceDoesNotRecordOverlap(): void
    {
        $tracker = new OverlapTracker();
        $tracker->recordTaskAttempt('task_z', 0, 'x0+x1');
        $tracker->recordTaskAttempt('task_z', 0, 'x0−x1'); // та же пчела, другой ответ

        $rows = \BeeSwarm\Infra\Database::get()
            ->query("SELECT COUNT(*) FROM overlap_log")
            ->fetchColumn();

        $this->assertSame(0, (int) $rows, 'Same bee must not create self-overlap');
    }

    /**
     * Null-ответ (пчела не нашла) → answer пустая строка.
     *
     * Predicted: FAIL — класс не существует.
     */
    public function testNullAnswerRecordedAsEmpty(): void
    {
        $tracker = new OverlapTracker();
        $tracker->recordTaskAttempt('task_w', 0, null);
        $tracker->recordTaskAttempt('task_w', 1, 'x0+x1');

        $row = \BeeSwarm\Infra\Database::get()
            ->query("SELECT * FROM overlap_log ORDER BY id DESC LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('', $row['answer_a'], 'Null answer → empty string');
        $this->assertSame('x0+x1', $row['answer_b']);
        $this->assertEquals(0, $row['matched'], 'Null vs answer → unmatched');
    }

    /**
     * Канонический порядок: (1,0) → INSERT (0,1).
     *
     * Review fix: без canonical ordering GROUP BY разбивает пары (A,B) и (B,A).
     */
    public function testCanonicalOrderingOnInsert(): void
    {
        $tracker = new OverlapTracker();
        // Первая пчела 1, вторая 0 — должен записаться порядок (0,1)
        $tracker->recordTaskAttempt('task_c', 1, 'x0+x1');
        $tracker->recordTaskAttempt('task_c', 0, 'x0+x1');

        $row = \BeeSwarm\Infra\Database::get()
            ->query("SELECT * FROM overlap_log ORDER BY id DESC LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('0', $row['bee_a'], 'Canonical: min first');
        $this->assertSame('1', $row['bee_b'], 'Canonical: max second');
    }
}
