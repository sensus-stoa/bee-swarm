<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * OverlapTracker — pairwise сравнение ответов пчёл (§1.8).
 *
 * При переназначении задачи от пчелы i к пчеле j система фиксирует
 * совпадение или расхождение их ответов. Накопление shared_tasks(i,j)
 * и matched(i,j). При shared_tasks ≥ 10 пара считается «измеренной».
 * Данные персистентны в overlap_log (SQLite).
 */
class OverlapTracker
{
    /**
     * Записать pairwise сравнение.
     */
    public function record(string $beeA, string $beeB, string $task, string $answerA, string $answerB): void
    {
        $key = $this->pairKey($beeA, $beeB);
        $matched = $this->answersMatch($answerA, $answerB) ? 1 : 0;

        $db = Database::get();
        $db->prepare('INSERT INTO overlap_log (bee_a, bee_b, task, answer_a, answer_b, matched)
                      VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$beeA, $beeB, $task, $answerA, $answerB, $matched]);
    }

    /**
     * Статистика по паре пчёл.
     *
     * @return array{shared_tasks: int, matched: int, measured: bool}
     */
    public function pairStats(string $beeA, string $beeB): array
    {
        $key = $this->pairKey($beeA, $beeB);
        [$a, $b] = explode('::', $key);

        $db = Database::get();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM overlap_log WHERE (bee_a=? AND bee_b=?) OR (bee_a=? AND bee_b=?)'
        );
        $stmt->execute([$a, $b, $b, $a]);
        $shared = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM overlap_log WHERE ((bee_a=? AND bee_b=?) OR (bee_a=? AND bee_b=?)) AND matched=1'
        );
        $stmt->execute([$a, $b, $b, $a]);
        $matched = (int) $stmt->fetchColumn();

        return [
            'shared_tasks' => $shared,
            'matched' => $matched,
            'measured' => $shared >= 10,
        ];
    }

    /**
     * Получить лог OVERLAP-записей.
     */
    public function getLog(): string
    {
        $rows = Database::get()->query(
            'SELECT bee_a, bee_b, matched FROM overlap_log ORDER BY rowid'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = sprintf('OVERLAP %s %s', $r['bee_a'], $r['bee_b']);
        }
        return implode("\n", $lines);
    }

    /**
     * OVERLAP-лог с matched/n для каждой измеренной пары.
     */
    public function getMeasuredLog(): string
    {
        $db = Database::get();
        $pairs = $db->query(
            'SELECT bee_a, bee_b, COUNT(*) as shared, SUM(matched) as matched
             FROM overlap_log GROUP BY bee_a, bee_b
             HAVING shared >= 10'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $lines = [];
        foreach ($pairs as $p) {
            $lines[] = sprintf(
                'OVERLAP %s %s %d/%d (MEASURED)',
                $p['bee_a'], $p['bee_b'], $p['matched'], $p['shared']
            );
        }
        return implode("\n", $lines);
    }

    /**
     * Сравнение ответов: совпадают если expression tree идентично
     * после алгебраической редукции (§1.4).
     */
    private function answersMatch(string $a, string $b): bool
    {
        return $this->reduce($a) === $this->reduce($b);
    }

    /**
     * Алгебраическая редукция (§1.4): итеративная, с коммутативностью.
     * add(x,0)→x, mul(x,1)→x, abs(abs(x))→abs(x).
     */
    private function reduce(string $expr): string
    {
        do {
            $prev = $expr;
            // add(x,0)→x, add(0,x)→x (infix + and prefix add)
            $expr = (string) preg_replace('/(?:\+|add)\((\w+),\s*0\)/', '$1', $expr);
            $expr = (string) preg_replace('/(?:\+|add)\(0,\s*(\w+)\)/', '$1', $expr);
            // mul(x,1)→x, mul(1,x)→x (infix × and prefix mul)
            $expr = (string) preg_replace('/(?:×|mul)\((\w+),\s*1\)/', '$1', $expr);
            $expr = (string) preg_replace('/(?:×|mul)\(1,\s*(\w+)\)/', '$1', $expr);
            // abs(abs(x))→abs(x)
            $expr = (string) preg_replace('/abs\(abs\((\w+)\)\)/', 'abs($1)', $expr);
        } while ($expr !== $prev);

        return $expr;
    }

    private function pairKey(string $beeA, string $beeB): string
    {
        return $beeA <= $beeB ? "{$beeA}::{$beeB}" : "{$beeB}::{$beeA}";
    }
}
