<?php

declare(strict_types=1);

namespace BeeSwarm\Certification;

/**
 * V0.12 WU-1 (§1.10 v1.7-draft): Metric-Domain Pre-flight.
 *
 * Декларация домена валидности ДО поиска (паттерн §1.2 pre-filter): относительная
 * CV-метрика (cv = sd(pred/y)/|mean(pred/y)|) дискриминативна только когда гейт ε
 * значимо ниже собственной вариативности таргета. Выведенная граница (спека §1.10,
 * верифицирована пересчётом): для аффинного предиктора pred = m + r·(y−m)
 * ratio-CV ≈ |1−r|·CV(y) → гейт ε допускает R² ≥ 1−(ε/CV(y))².
 *
 * При ε=0.15, CV(PE)=0.0376 (CCPP): R² вплоть до −14.9 — гейт не различает даже
 * уровень таргета (Demo Audit #2: константный предиктор и дегенерат-антипредсказание
 * прошли). Pre-flight ЧЕСТНО отказывает: домен несертифицируем rel-метрикой.
 *
 * НЕ фиксирует метрику (открытая очередь v1.5b METRIC-FAMILY: RMSE/log-loss) —
 * предотвращает выдачу пустых сертификатов, пока очередь открыта.
 *
 * Параметр (C): FACTOR=0.5 default (env PREFLIGHT_GATE_FACTOR); 0 = выключено
 * (наследие v1.6 для воспроизводимости старых прогонов).
 */
final class MetricPreflight
{
    private const DEFAULT_GATE_FACTOR = 0.5;

    /**
     * cv(y) = sd(y)/|mean(y)| — |mean| обязателен: таргеты с отрицательным
     * mean (dot: mean=−0.95) дают отрицательный CV через голый sd/mean
     * (проба 16.09, RED-кейс). CV(y)=0 (константа) → вырожденный ratio-CV,
     * отказ при любом гейте.
     *
     * @param list<float> $y
     */
    public static function cvOfTarget(array $y): ?float
    {
        $n = count($y);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($y) / $n;
        if (abs($mean) < 1e-12) {
            return null; // mean≈0: CV не определён (знакопеременный таргет)
        }
        $var = 0.0;
        foreach ($y as $v) {
            $var += ($v - $mean) ** 2;
        }
        $sd = sqrt($var / $n);

        return $sd / abs($mean);
    }

    /**
     * Pre-flight проверка домена.
     *
     * @param list<float> $y
     * @return object{passes: bool, status: string, cv_y: ?float, factor: float,
     *   r2_floor: ?float, note: string} status: PASS | METRIC_DOMAIN | DISABLED
     */
    public static function check(float $gateEps, array $y): object
    {
        $factor = self::factor();
        if ($factor <= 0.0) {
            return (object) ['passes' => true, 'status' => 'DISABLED', 'cv_y' => null,
                'factor' => $factor, 'r2_floor' => null, 'note' => 'pre-flight off (v1.6)'];
        }
        $cv = self::cvOfTarget($y);
        if ($cv === null) {
            return (object) ['passes' => false, 'status' => 'METRIC_DOMAIN', 'cv_y' => $cv,
                'factor' => $factor, 'r2_floor' => null,
                'note' => 'CV(y) не определён (константа/mean≈0/мало данных): ratio-CV вырожден'];
        }
        if ($cv <= 1e-12) {
            // Константный таргет: ratio-CV вырожден при любом гейте (проба
            // WU-1: деление на cv=0 в r2_floor). Отказ без публикации R².
            return (object) ['passes' => false, 'status' => 'METRIC_DOMAIN', 'cv_y' => 0.0,
                'factor' => $factor, 'r2_floor' => null,
                'note' => 'CV(y)=0: вырожденный ratio-CV, домен несертифицируем'];
        }

        return self::domainVerdict($gateEps, $cv, $factor);
    }

    /**
     * Вердикт домена при известном CV>0: PASS (гейт различим) | METRIC_DOMAIN.
     * Float-допуск: фиксстура CV=2ε ровно даёт factor*cv=0.3000000000000002
     * (пойман прогоном) — равенство обязано трактоваться как отказ.
     */
    private static function domainVerdict(float $gateEps, float $cv, float $factor): object
    {
        $r2Floor = 1.0 - ($gateEps / $cv) ** 2;
        if ($gateEps < $factor * $cv - 1e-12) {
            // Граница R² из §1.10: лучший возможный R² при этом гейте.
            // Гейт допускает предикторы с R² вплоть до r2_floor — если оно
            // отрицательно, «сертификат» не отличает даже уровень таргета.
            return (object) ['passes' => true, 'status' => 'PASS', 'cv_y' => $cv,
                'factor' => $factor, 'r2_floor' => $r2Floor,
                'note' => 'гейт различим: R2_floor=' . round($r2Floor, 3)];
        }

        return (object) ['passes' => false, 'status' => 'METRIC_DOMAIN', 'cv_y' => $cv,
            'factor' => $factor, 'r2_floor' => $r2Floor,
            'note' => sprintf('гейт %.4f >= %.4f=FACTOR*CV(y)=%.4f: R2_floor=%.2f — сертификат-мусор зона',
                $gateEps, $factor * $cv, $cv, $r2Floor)];
    }

    /**
     * WU-3 ко-гейты пост-хок (§1.10): SIGN corr(pred,y)>0 + SCALE
     * |median(pred)| в 10× коридоре |median(y)| [0.1×..10×].
     * Инвариант co-move: закон обязан и коррелировать по знаку, и нести
     * масштаб цели (level-free shape отсеивается).
     *
     * @param list<float> $pred
     * @param list<float> $y
     * @return object{sign_ok: bool, scale_ok: bool, corr: float, scale_ratio: float}
     */
    public static function coGates(array $pred, array $y): object
    {
        $n = min(count($pred), count($y));
        if ($n < 3) {
            return (object) ['sign_ok' => false, 'scale_ok' => false, 'corr' => 0.0, 'scale_ratio' => 0.0];
        }
        // Pearson corr(pred,y) по первым n парам
        $mp = array_sum(array_slice($pred, 0, $n)) / $n;
        $my = array_sum(array_slice($y, 0, $n)) / $n;
        $sxy = 0.0;
        $sx = 0.0;
        $sy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $pred[$i] - $mp;
            $dy = $y[$i] - $my;
            $sxy += $dx * $dy;
            $sx += $dx ** 2;
            $sy += $dy ** 2;
        }
        $corr = ($sx > 1e-12 && $sy > 1e-12) ? $sxy / sqrt($sx * $sy) : 0.0;
        $median = static fn (array $a): float => self::medianOf(array_slice($a, 0, $n));
        $mpMed = abs($median($pred));
        $myMed = abs($median($y));
        $scaleRatio = $myMed > 1e-12 ? $mpMed / $myMed : 0.0;
        $signOk = $corr > 0.0;
        $scaleOk = $scaleRatio >= 0.1 && $scaleRatio <= 10.0;

        return (object) ['sign_ok' => $signOk, 'scale_ok' => $scaleOk,
            'corr' => $corr, 'scale_ratio' => $scaleRatio];
    }

    /**
     * WU-3: вердикт по ко-гейтам (совместно с V0.11: провал → UNSTABLE).
     *
     * @param array{sign_ok: bool, scale_ok: bool} $gates
     */
    public static function verdictOfCoGates(array $gates): string
    {
        return ($gates['sign_ok'] && $gates['scale_ok']) ? 'PASS' : 'UNSTABLE_CERTIFICATE';
    }

    /** Медиана (сорт-версия; n всегда ≥1 от вызывающего). @param list<float> $a */
    private static function medianOf(array $a): float
    {
        sort($a);
        $n = count($a);
        $mid = (int) floor($n / 2);

        return $n % 2 === 1 ? $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2.0;
    }

    /**
     * FACTOR из env (параметр (C)); '0' = выключено, мусор/negative → default.
     * PHP-falsy гвард: getenv строки '0' фальси — сравнение !== false.
     * F7 (agent-review): negative → DEFAULT (не DISABLED): опечатка знака
     * в env не должна молча выключать доменный гейт.
     */
    private static function factor(): float
    {
        $f = getenv('PREFLIGHT_GATE_FACTOR');
        if ($f === false || $f === '') {
            return self::DEFAULT_GATE_FACTOR;
        }
        if (! is_numeric($f) || (float) $f < 0.0) {
            return self::DEFAULT_GATE_FACTOR;
        }

        return (float) $f; // 0.0 легален = выключено
    }
}
