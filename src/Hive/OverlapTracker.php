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
                $matched = ($answer !== null && $prevAnswer !== null
                    && self::reduceAnswer($answer) === self::reduceAnswer($prevAnswer)) ? 1 : 0;
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

    /**
     * Алгебраическая редукция формулы (§1.8):
     *  - x0+0 → x0, x0×1 → x0
     *  - Коммутативность для add, mul, min, max
     *  - Удаление констант-нолей/единиц
     *
     * @param string $formula например "add(x0,0)" или "mul(x1,x0)"
     */
    public static function reduceAnswer(string $formula): string
    {
        // 1. Нормализация пробелов
        $formula = trim($formula);

        // 2. Парсинг: op(arg1,arg2)
        if (! preg_match('/^(\w+)\((.+)\)$/', $formula, $m)) {
            return $formula; // переменная или константа
        }

        $op = $m[1];
        $args = self::parseArgs($m[2]);
        if (count($args) !== 2) {
            return $formula;
        }

        [$a, $b] = $args;

        // Рекурсивная редукция аргументов
        if (preg_match('/^\w+\(/', $a)) {
            $a = self::reduceAnswer($a);
        }
        if (preg_match('/^\w+\(/', $b)) {
            $b = self::reduceAnswer($b);
        }

        // 3. Правила редукции
        // x+0 = x
        if ($op === 'add' && ($b === '0' || $b === 'x0_0')) {
            return $a;
        }
        if ($op === 'add' && ($a === '0' || $a === 'x0_0')) {
            return $b;
        }
        // x×1 = x
        if ($op === 'mul' && $b === '1') {
            return $a;
        }
        if ($op === 'mul' && $a === '1') {
            return $b;
        }

        // 4. Коммутативность: сортировка аргументов для +, ×, min, max
        if (in_array($op, ['add', 'mul', 'min', 'max'])) {
            $sorted = [$a, $b];
            sort($sorted);
            return "{$op}({$sorted[0]},{$sorted[1]})";
        }

        return "{$op}({$a},{$b})";
    }

    /**
     * @return string[] аргументы с учётом вложенных скобок
     */
    private static function parseArgs(string $s): array
    {
        $depth = 0;
        $args = [];
        $current = '';
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '(') {
                $depth++;
                $current .= $c;
            } elseif ($c === ')') {
                $depth--;
                $current .= $c;
            } elseif ($c === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';
            } else {
                $current .= $c;
            }
        }
        $args[] = trim($current);
        return $args;
    }
}
