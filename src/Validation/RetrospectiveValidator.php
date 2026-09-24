<?php

declare(strict_types=1);

namespace BeeSwarm\Validation;

use BeeSwarm\Core\AtomRegistry;
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
     * RETRO-QUARANTINE (24.09): порог алерта removed_ratio за проход
     * (premortem И-1): >20% предъявленных законов ушло в карантин —
     * вероятный дрейф корпуса, оператору сигнал.
     */
    private const ALERT_RATIO = 0.2;

    /**
     * Проверяет все законы в БД через held-out.
     * Принимает массив tasks с данными.
     * Возвращает ['passed' => [...], 'overfit' => [...]].
     * Overfit законы удаляются из БД.
     */
    public static function validate(array $tasks): array
    {
        $db = Database::get();
        $laws = $db->query('SELECT name, formula FROM laws')
            ->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($laws)) {
            return [
                'passed' => [],
                'overfit' => [],
            ];
        }

        $taskIndex = [];
        foreach ($tasks as $t) {
            $taskIndex[$t['name']] = $t;
        }

        $passed = [];
        $overfit = [];

        foreach ($laws as $law) {
            $name = $law['name'];
            $formula = $law['formula'];

            if (! isset($taskIndex[$name])) {
                continue;
            }

            $task = $taskIndex[$name];
            $data = $task['data'];
            $n = count($data);
            if ($n < 3) {
                continue;
            }

            $nFeat = count($data[0]) - 1;
            $X = array_map(fn ($r) => array_slice($r, 0, $nFeat), $data);
            $y = array_column($data, $nFeat);

            $result = LawValidator::evaluateHeldout($formula, $X, $y);
            if ($result === null) {
                continue;
            }
            if ($result['cv_train'] > self::CV_TRAIN_MAX) {
                continue;
            }

            $key = $name . '::' . $formula;
            if ($result['cv_holdout'] <= self::CV_HOLDOUT_MAX) {
                $passed[] = $key;
            } else {
                // RETRO-QUARANTINE (24.09, premortem И-1): удаление необратимо
                // и теряло закон при дрейфе корпуса → перенос в карантин
                // (полная строка + quarantined_at) — восстановимо.
                // Атомарная пара (criterion-audit RQ#1): крэш между INSERT и
                // DELETE оставил бы закон в обеих таблицах. REPLACE: повторное
                // выучивание + повторный overfit обновляет строку (unique
                // index idx_quarantine_name_formula), дублей нет.
                $db->beginTransaction();
                try {
                    $db->prepare('INSERT OR REPLACE INTO laws_quarantine
                        (name, formula, cv, domain, source_path, content_sample, col_labels, law_class, escrow_status, found_at, usage_count, confirmed_count, last_fingerprint, seen_fingerprints, quarantined_at)
                        SELECT name, formula, cv, domain, source_path, content_sample, col_labels, law_class, escrow_status, found_at, usage_count, confirmed_count, last_fingerprint, seen_fingerprints, datetime(\'now\')
                        FROM laws WHERE name = ? AND formula = ?')
                        ->execute([$name, $formula]);
                    $db->prepare('DELETE FROM laws WHERE name=? AND formula=?')
                        ->execute([$name, $formula]);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
                $overfit[] = $key;
            }
        }

        // RETRO-QUARANTINE: метрика прохода + алерт (premortem И-1).
        // Знаменатель = ВСЕ законы, предъявленные проходу (validated +
        // skipped) — массовость удаления видна даже при неполном матче задач.
        $total = count($laws);
        $removed = count($overfit);
        // float-контракт return-shape: PHP `0/2` даёт int(0) — assertSame(0.0)
        // валиден только при явном касте (поймано RetroQuarantineTest).
        $ratio = $total > 0 ? (float) $removed / (float) $total : 0.0;
        $alert = $ratio > self::ALERT_RATIO;

        return [
            'passed' => $passed,
            'overfit' => $overfit,
            'removed_ratio' => $ratio,
            'alert' => $alert,
        ];
    }
}
