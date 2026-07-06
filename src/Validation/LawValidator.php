<?php
declare(strict_types=1);

namespace BeeSwarm\Validation;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Math\CvCalculator;

/**
 * LawValidator — held-out validation (HONEST_CRITERIA §1.1).
 * Вынесено из AtomRegistry (SOLID S).
 */
class LawValidator implements ValidatorInterface
{
    private const HO_SPLIT_RATIO = 5;
    private const CV_TRAIN_MAX = 0.01;
    private const CV_HOLDOUT_MAX = 0.10;
    private const CV_EXACT_TOLERANCE = 0.0001;

    /**
     * discover с held-out validation.
     */
    public static function discoverHeldout(array $X, array $y): array
    {
        $n = count($y);
        $h = max(1, (int)floor($n / self::HO_SPLIT_RATIO));
        if ($n - $h < 2) return [];

        $X_train = array_slice($X, 0, $n - $h);
        $y_train = array_slice($y, 0, $n - $h);

        $candidates = AtomRegistry::discover($X_train, $y_train);
        return self::validate($candidates, $X, $y);
    }

    /** ValidatorInterface: фильтрует кандидатов через held-out */
    #[Override]
    public static function validate(array $candidates, array $X, array $y): array
    {
        $found = [];
        foreach ($candidates as $c) {
            $result = self::evaluateHeldout($c['atom'], $X, $y);
            if ($result !== null && $result['cv_train'] <= self::CV_TRAIN_MAX
                && $result['cv_holdout'] <= self::CV_HOLDOUT_MAX) {
                $found[] = [
                    'atom' => $c['atom'],
                    'cv' => $c['cv'],
                    'cv_train' => $result['cv_train'],
                    'cv_holdout' => $result['cv_holdout'],
                    'mode' => $c['mode'],
                ];
            }
        }
        return $found;
    }

    /** Проверяет CV формулы на held-out данных. Возвращает [cv_train, cv_holdout] или null. */
    public static function evaluateHeldout(string $formula, array $X, array $y): ?array
    {
        $n = count($y);
        $h = max(1, (int)floor($n / self::HO_SPLIT_RATIO));
        if ($n - $h < 2) return null;

        $X_train = array_slice($X, 0, $n - $h);
        $y_train = array_slice($y, 0, $n - $h);
        $X_holdout = array_slice($X, $n - $h);
        $y_holdout = array_slice($y, $n - $h);
        $nFeat = count($X[0] ?? []);

        $vecTrain = [];
        foreach ($X_train as $row) {
            $v = AtomRegistry::apply($formula, (float)$row[0], $nFeat >= 2 ? (float)($row[1] ?? 0) : 0);
            if ($v === null || is_nan($v) || is_infinite($v)) { $vecTrain = []; break; }
            $vecTrain[] = $v;
        }
        if (count($vecTrain) !== count($y_train)) return null;
        $cvTrain = count($y_train) < 2
            ? (abs($vecTrain[0] - $y_train[0]) > self::CV_EXACT_TOLERANCE ? 9.99 : 0.0)
            : CvCalculator::compute($vecTrain, $y_train);

        $vecHoldout = [];
        foreach ($X_holdout as $row) {
            $v = AtomRegistry::apply($formula, (float)$row[0], $nFeat >= 2 ? (float)($row[1] ?? 0) : 0);
            if ($v === null || is_nan($v) || is_infinite($v)) { $vecHoldout = []; break; }
            $vecHoldout[] = $v;
        }
        if (count($vecHoldout) !== count($y_holdout)) return null;
        $cvHoldout = count($y_holdout) < 2
            ? (abs($vecHoldout[0] - $y_holdout[0]) > self::CV_EXACT_TOLERANCE ? 9.99 : 0.0)
            : CvCalculator::compute($vecHoldout, $y_holdout);

        return ['cv_train' => $cvTrain, 'cv_holdout' => $cvHoldout];
    }
}
