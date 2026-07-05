<?php
declare(strict_types=1);

namespace BeeSwarm\Validation;

use BeeSwarm\AtomRegistry;
use BeeSwarm\Infra\Database;

/**
 * RetrospectiveValidator — ретроспективная проверка законов.
 * Вынесено из AtomRegistry (SOLID S).
 */
class RetrospectiveValidator
{
    private const CV_TRAIN_MAX = 0.01;
    private const CV_HOLDOUT_MAX = 0.10;

    /**
     * Проверяет все законы в БД через held-out.
     * Принимает массив tasks с данными.
     * Возвращает ['passed' => [...], 'overfit' => [...]].
     * Overfit законы удаляются из БД.
     */
    public static function validate(array $tasks): array
    {
        $db = Database::get();
        $laws = $db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($laws)) return ['passed' => [], 'overfit' => []];

        $taskIndex = [];
        foreach ($tasks as $t) {
            $taskIndex[$t['name']] = $t;
        }

        $passed = [];
        $overfit = [];

        foreach ($laws as $law) {
            $name = $law['name'];
            $formula = $law['formula'];

            if (!isset($taskIndex[$name])) continue;

            $task = $taskIndex[$name];
            $data = $task['data'];
            $n = count($data);
            if ($n < 3) continue;

            $nFeat = count($data[0]) - 1;
            $X = array_map(fn($r) => array_slice($r, 0, $nFeat), $data);
            $y = array_column($data, $nFeat);

            $result = LawValidator::evaluateHeldout($formula, $X, $y);
            if ($result === null) continue;
            if ($result['cv_train'] > self::CV_TRAIN_MAX) continue;

            $key = $name . '::' . $formula;
            if ($result['cv_holdout'] <= self::CV_HOLDOUT_MAX) {
                $passed[] = $key;
            } else {
                $overfit[] = $key;
                $db->prepare("DELETE FROM laws WHERE name=? AND formula=?")
                   ->execute([$name, $formula]);
            }
        }

        return ['passed' => $passed, 'overfit' => $overfit];
    }
}
