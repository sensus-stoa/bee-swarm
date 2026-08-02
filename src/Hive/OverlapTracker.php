<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * OverlapTracker — запись попарных сравнений пчёл на общих задачах (§1.8).
 *
 * Когда задача, ранее назначенная пчеле A, позже назначается пчеле B:
 * → записывает в overlap_log: (bee_a, bee_b, task, answer_a, answer_b, matched).
 *
 * Отслеживание: хранит последнее назначение для каждого имени задачи.
 * При смене пчелы — записывает overlap.
 */
class OverlapTracker
{
    /**
     * Последнее назначение: taskName → [beeIdx, answerFormula|null].
     * @var array<string, array{int, string|null}>
     */
    private array $lastAssignment = [];

    /**
     * Записать попытку решения задачи пчелой.
     *
     * Если задача ранее назначалась другой пчеле → overlap.
     *
     * @param string $taskName имя задачи
     * @param int $beeIdx индекс пчелы
     * @param string|null $answer формула-ответ (null если не найдена)
     */
    public function recordTaskAttempt(string $taskName, int $beeIdx, ?string $answer): void
    {
        if (isset($this->lastAssignment[$taskName])) {
            [$prevBee, $prevAnswer] = $this->lastAssignment[$taskName];

            // Другая пчела → overlap
            if ($prevBee !== $beeIdx) {
                $matched = ($answer !== null && $prevAnswer !== null && $answer === $prevAnswer) ? 1 : 0;
                $this->insertOverlap(
                    (string) $prevBee,
                    (string) $beeIdx,
                    $taskName,
                    $prevAnswer ?? '',
                    $answer ?? '',
                    $matched
                );
            }
        }

        $this->lastAssignment[$taskName] = [$beeIdx, $answer];
    }

    private function insertOverlap(
        string $beeA,
        string $beeB,
        string $task,
        string $answerA,
        string $answerB,
        int $matched,
    ): void {
        // Канонический порядок: всегда (min, max) чтобы GROUP BY не разбивал пары
        if ($beeA > $beeB) {
            [$beeA, $beeB] = [$beeB, $beeA];
            [$answerA, $answerB] = [$answerB, $answerA];
        }

        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO overlap_log (bee_a, bee_b, task, answer_a, answer_b, matched, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$beeA, $beeB, $task, $answerA, $answerB, $matched, date('Y-m-d H:i:s')]);
    }
}
