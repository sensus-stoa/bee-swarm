<?php

declare(strict_types=1);

namespace BeeSwarm\Certification;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\LawShape;

/**
 * V0.13 WU-1 (§1.11 v1.7-draft): Contradiction-Derived Certification.
 *
 * ТРИЗ-канон: устойчивое анти-предсказание — не мусор, а ЗАКОН, увиденный
 * в перевёрнутый бинокль. Противоречие не разрешается выбором стороны
 * (метрика vs физика), а снимается разделением слоёв: shape (грамматика) +
 * anchor (ансамбль) + sign (ко-гейты). Закон = тройка (shape, m̂, sign);
 * противоречие живёт в sign-слое и обрабатывается там же.
 *
 * Триггер (Demo#2): дегенерат ((AT/RminAT)−RrangeRH), corr=−0.947,
 * ресемпл-устойчив 3/3. Ансамбль (V0.11) сертифицировал бы — устойчивость
 * есть; физика (ко-гейт V0.12) говорит «знак неверный». Это TРИЗ-противоречие.
 *
 * Ветви (WU-2/3):
 *  - INVERTED-LAW: инверсионный ре-поиск (−y) нашёл закон → вердикт
 *    с пометкой пути обнаружения (прозрачность — фича метода);
 *  - ANOMALY: инверсия не помогла, противоречие устойчиво → METRIC_BLINDNESS_FLAG
 *    (данные для дизайна метрики v1.5b, не мусор);
 *  - NEW-PARADIGM-FLAG: ≥3 несвязанных домена с одинаковой shape (Стадия 2,
 *    вне минимального скоупа).
 *
 * Двойной барьер от ложных срабатываний: ensemble-устойчивость (recurrence
 * >= 0.5) AND |corr| >= 0.7. Без V0.12 (ко-гейты) и V0.11 (устойчивость)
 * класс CONTRADICTION недостижим.
 */
final class ContradictionEngine
{
    /** Минимальная зеркальная сила (|corr| ниже — не «закон в зеркале»). */
    public const MIRROR_THRESHOLD = 0.7;

    /** Минимальная ensemble-устойчивость (ниже — шум, не устойчивое противоречие). */
    public const STABILITY_GATE = 0.5;

    /**
     * Детект противоречия. Потребляет выходы V0.11 (ensemble recurrence)
     * и V0.12 (ко-гейты). Гипотеза пре-регистрируется в вердикте ДО
     * ре-поиска (прозрачность против подгонки).
     *
     * @param array<string, mixed> $candidate кандидат (atom, found, corr)
     * @param object $ensembleResult {recurrence: float, verdict: string} (V0.11)
     * @param object $coGates {sign_ok: bool, corr: float, ...} (V0.12)
     * @return object{class: string, mirror_strength: float, action: string,
     *   pre_registered_hypothesis: string}
     */
    public static function detect(array $candidate, object $ensembleResult, object $coGates): object
    {
        $corr = (float) ($candidate['corr'] ?? $coGates->corr);
        $found = (bool) ($candidate['found'] ?? false);
        $recurrence = (float) ($ensembleResult->recurrence ?? 0.0);

        return self::verdict($isMirror = $found, $corr, $recurrence, $coGates->sign_ok);
    }

    private static function verdict(bool $found, float $corr, float $recurrence, bool $signOk): object
    {
        $isMirror = $found
            && $recurrence >= self::STABILITY_GATE
            && ! $signOk
            && abs($corr) >= self::MIRROR_THRESHOLD;

        if (! $isMirror) {
            return (object) ['class' => 'NOT_CONTRADICTION', 'mirror_strength' => abs($corr),
                'action' => 'NONE', 'pre_registered_hypothesis' => '',
                'reason' => self::notContradictionReason($found, $recurrence, $signOk, $corr)];
        }

        return (object) [
            'class' => 'CONTRADICTION',
            'mirror_strength' => abs($corr),
            'action' => 'INVERT_AND_RESEARCH',
            'pre_registered_hypothesis' => sprintf(
                'Если исходный кандидат — зеркальный закон: инверсия таргета (−y) даст '
                . 'cv_инв < 0.5 × cv_исходного И corr(pred_инв, y) > +0.7. '
                . 'Инверсия ограничена 1 повтором (INVERT_MAX=1, анти-зацикливание). '
                . 'Не подтверждена → ANOMALY (METRIC_BLINDNESS_FLAG). '
                . 'Пре-регистрировано до ре-поиска (%s).',
                gmdate('c'),
            ),
        ];
    }

    /** Причина отказа от классификации (для лога — partition T1 честность). */
    private static function notContradictionReason(bool $found, float $recurrence, bool $signOk, float $corr): string
    {
        if (! $found) {
            return 'not_found';
        }
        if ($recurrence < self::STABILITY_GATE) {
            return 'unstable_recurrence_' . round($recurrence, 3);
        }
        if ($signOk) {
            return 'sign_ok_corr_' . round($corr, 3);
        }

        return 'weak_mirror_' . round(abs($corr), 3);
    }

    /**
     * WU-2: инверсионный ре-поиск. Инверсия меняет ЗАДАЧУ (−y), не данные (§0.4).
     * Гипотеза пре-регистрируется в лог СТРОГО до ре-поиска. INVERT_MAX=1.
     *
     * @param list<list<float>> $X
     * @param list<float> $y
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $cfg gate, depth, budget
     * @return object{class: string, inverted_cv: ?float, inverted_corr: ?float,
     *   inverted_formula: ?string, verdict_line: string}
     */
    public static function invertAndResearch(array $X, array $y, array $candidate, string $logFile, array $cfg): object
    {
        // Анти-зацикливание (INVERT_MAX=1): кандидат уже из инверсии → skip.
        if (! empty($candidate['inverted'])) {
            $out = (object) ['class' => 'SKIP_ALREADY_INVERTED', 'inverted_cv' => null,
                'inverted_corr' => null, 'inverted_formula' => null, 'verdict_line' => 'already inverted'];
            self::log($logFile, 'INVERT_RESULT SKIP_ALREADY_INVERTED');

            return $out;
        }
        $research = self::researchInverted($X, $y, $candidate, $logFile, $cfg);
        self::log($logFile, 'INVERT_RESULT ' . $research->class
            . ' cv_инв=' . ($research->inverted_cv !== null ? round($research->inverted_cv, 4) : 'null')
            . ' corr=' . ($research->inverted_corr !== null ? round($research->inverted_corr, 3) : 'null')
            . ' формула=' . ($research->inverted_formula ?? 'none'));

        return $research;
    }

    /**
     * Тело инверсии: pre-регистрация → find(−y) → corr с y_ориг.
     * H5: cfg['grammar'] приоритетнее базовой. F3: depth/gate из cfg
     * (параметры исходного прогона), не дефолты — асимметрия давала ложную ANOMALY.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $cfg
     */
    private static function researchInverted(array $X, array $y, array $candidate, string $logFile, array $cfg): object
    {
        self::log($logFile, 'PRE_REGISTER: гипотеза инверсии — если кандидат зеркальный закон, '
            . 'find(−y) пере-находит структуру с cv_инв ≈ cv_исход (' . ($candidate['cv'] ?? 'n/a') . ') '
            . 'И |corr(g, y_ориг)| >= 0.7 со ЗНАКОМ МИНУС (г аппроксимирует −y). '
            . 'Подтверждение: зеркальная сила сохраняется; m̂ = −g переворачивает знак. ' . gmdate('c'));

        $negY = array_map(static fn (float $v): float => -$v, $y);
        $grammar = isset($cfg['grammar']) && $cfg['grammar'] instanceof Grammar ? $cfg['grammar'] : new Grammar();
        $res = Search::find($X, $negY, $grammar, (int) ($cfg['depth'] ?? 2), null, 0.0,
            (float) ($cfg['gate'] ?? 0.15), (float) ($cfg['budget'] ?? 15.0), null);
        $invertedCv = (bool) $res[0] ? (float) $res[1] : null;
        $invertedFormula = (bool) $res[0] ? (string) $res[2] : null;
        $invertedCorr = $invertedFormula !== null
            ? self::corrAgainstOriginalY($invertedFormula, $X, $y)
            : null;

        return self::inversionVerdict($candidate, $invertedCv, $invertedCorr, $invertedFormula, $logFile);
    }

    /**
     * corr(pred_инв, y) — предсказания инвертированного закона против
     * ИСХОДНОГО y (не −y): зеркало должно «перевернуться» в ко-движение.
     *
     * @param list<list<float>> $X
     * @param list<float> $y
     */
    private static function corrAgainstOriginalY(string $formula, array $X, array $y): ?float
    {
        $pred = self::predictOnTrain($formula, $X);

        return $pred !== null ? MetricPreflight::coGates($pred, $y)->corr : null;
    }

    /**
     * Вердикт инверсии: гипотеза (cv_инв < 0.5·cv_исход И corr > +0.7) →
     * INVERTED_LAW; иначе ANOMALY (противоречие устойчиво, метрика слепа).
     *
     * @param array<string, mixed> $candidate
     */
    private static function inversionVerdict(array $candidate, ?float $invertedCv, ?float $invertedCorr, ?string $invertedFormula, string $logFile): object
    {
        // F1+F2 (agent-review deleg_3f35dc3a, ретро): знака и порог были
        // инвертированы. find(−y) по построению возвращает g ≈ −y → corr(g, y)
        // ОТРИЦАТЕЛЕН (CCPP: −0.943); зеркало пере-находит структуру с
        // cv_инв ≈ cv_исход (порог 0.5 был недостижим). Корректная гипотеза:
        // зеркальная сила сохраняется |corr(g, y)| >= MIRROR_THRESHOLD
        // (знак минус согласован с m̂ = −g в buildLawTriple).
        if (! isset($candidate['cv']) || ! is_numeric($candidate['cv'])) {
            return self::anomalyVerdict($candidate, $invertedCv, $invertedCorr, $invertedFormula, $logFile, 'missing_source_cv');
        }
        $confirmed = $invertedCv !== null && $invertedCorr !== null
            && abs($invertedCorr) >= self::MIRROR_THRESHOLD
            && $invertedCorr < 0.0; // g аппроксимирует −y: corr с y_ориг отрицателен

        return $confirmed
            ? self::invertedLawVerdict($invertedCv, $invertedCorr, $invertedFormula, $logFile)
            : self::anomalyVerdict($candidate, $invertedCv, $invertedCorr, $invertedFormula, $logFile, 'hypothesis_refuted');
    }

    /** @param array<string, mixed> $candidate */
    private static function anomalyVerdict(array $candidate, ?float $invertedCv, ?float $invertedCorr, ?string $invertedFormula, string $logFile, string $reason): object
    {
        $out = (object) ['class' => 'ANOMALY', 'inverted_cv' => $invertedCv,
            'inverted_corr' => $invertedCorr, 'inverted_formula' => $invertedFormula,
            'verdict_line' => $reason === 'missing_source_cv'
                ? 'ANOMALY: исходный cv неизвестен — гипотеза невыразима (метрическая слепота)'
                : 'ANOMALY: противоречие устойчиво, инверсия не подтвердила гипотезу (метрическая слепота)'];
        self::flagMetricBlindness($candidate, abs((float) ($candidate['corr'] ?? 0)), (string) ($candidate['domain'] ?? 'unknown'), $logFile);
        self::log($logFile, 'INVERT_RESULT ANOMALY reason=' . $reason);

        return $out;
    }

    private static function invertedLawVerdict(?float $invertedCv, ?float $invertedCorr, ?string $invertedFormula, string $logFile): object
    {
        $out = (object) ['class' => 'INVERTED_LAW', 'inverted_cv' => $invertedCv,
            'inverted_corr' => $invertedCorr, 'inverted_formula' => $invertedFormula,
            'verdict_line' => 'INVARIANT (via inversion): исходный кандидат был зеркалом закона'];
        self::log($logFile, 'INVERT_RESULT INVERTED_LAW cv_инв=' . ($invertedCv !== null ? round($invertedCv, 4) : 'null')
            . ' corr=' . ($invertedCorr !== null ? round($invertedCorr, 3) : 'null')
            . ' формула=' . ($invertedFormula ?? 'none'));

        return $out;
    }

    /**
     * Предсказания формулы на train-строках (для corr против исходного y).
     *
     * @param list<list<float>> $X
     * @return list<float>|null
     */
    private static function predictOnTrain(string $formula, array $X): ?array
    {
        try {
            $stats = ExpressionEvaluator::collectStats($formula, $X, [], []);
            $pred = ExpressionEvaluator::evaluateFormula($formula, $X, $stats, [], []);
        } catch (\Throwable) {
            return null;
        }

        return is_array($pred) && count($pred) === count($X) ? $pred : null;
    }

    private static function log(string $logFile, string $line): void
    {
        if ($logFile === '') {
            return;
        }
        file_put_contents($logFile, '[' . gmdate('c') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    /**
     * WU-3: ANOMALY-ветвь + METRIC_BLINDNESS_FLAG (идемпотентно).
     *
     * @param array<string, mixed> $candidate
     */
    public static function flagMetricBlindness(array $candidate, float $mirrorStrength, string $domain, string $logFile): void
    {
        $key = 'METRIC_BLINDNESS:' . ($candidate['atom'] ?? '?') . ':' . $domain;
        if (isset(self::$blindnessFlags[$key])) {
            return; // idempotent: повторный вызов не дублирует флаг
        }
        self::$blindnessFlags[$key] = true;
        self::log($logFile, 'METRIC_BLINDNESS_FLAG shape=' . ($candidate['atom'] ?? '?')
            . ' |corr|=' . round($mirrorStrength, 3) . ' domain=' . $domain
            . ' → METRIC-FAMILY очередь (v1.5b): данные содержат зеркальную структуру, не выразимую прямо');
    }

    /** Сброс реестра флагов (для тестов — static state = test poison; прецедент EnvPressure::resetAdmission). */
    public static function resetBlindnessFlags(): void
    {
        self::$blindnessFlags = [];
    }

    /**
     * WU-4: двухслойная запись закона — тройка (shape, m̂, sign).
     *
     * Инверсия меняет ЗНАК задачи: инвертированный закон предсказывает −y,
     * его anchor считается на −y. Финальный anchor на ОРИГИНАЛЬНОМ y:
     * m̂_final = −m̂_inv (pred_orig = −pred_inv ⇒ y/pred_orig = −(y/pred_inv)).
     * Движок остаётся parameter-free: anchor — статистика сертификационного
     * слоя (как V0.11 ensemble_anchor), не грамматик-константа.
     *
     * @return array{shape: string, m_hat: float, sign: string}
     */
    public static function buildLawTriple(string $shape, float $anchorOnTaskY, string $signChannel): array
    {
        $mHat = $signChannel === 'inverted' ? -$anchorOnTaskY : $anchorOnTaskY;

        return ['shape' => $shape, 'm_hat' => $mHat, 'sign' => $signChannel];
    }

    /** @var array<string, true> */
    private static array $blindnessFlags = [];
}
