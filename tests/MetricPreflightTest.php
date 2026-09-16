<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\MetricPreflight;
use PHPUnit\Framework\TestCase;

/**
 * V0.12 WU-1: MetricPreflight — декларация домена валидности ДО поиска (§1.10).
 *
 * Контракт: rel-CV метрика дискриминативна только когда гейт ε значимо ниже
 * собственной вариативности таргета. Выведенная граница (спека §1.10,
 * верифицирована verify_mechanism пересчётом): для аффинного предиктора
 * ratio-CV ≈ |1−r|·CV(y) → гейт ε допускает R² ≥ 1−(ε/CV(y))².
 *
 * GATE_DOMAIN: gateEps < FACTOR * cv(y) → PASS; иначе REFUSAL METRIC-DOMAIN.
 * FACTOR = 0.5 (env PREFLIGHT_GATE_FACTOR, параметр (C), default off = 0).
 *
 * Пробы 16.09 (до кода):
 *  - CCPP: CV(PE)=0.0376, gate 0.15 → REFUSAL (граница R²=−14.9, спека ✓);
 *  - dot: mean(y)=−0.95 → cv обязан быть |sd/mean| (иначе отрицательный CV
 *    ломает сравнение) — RED-кейс отрицательного mean;
 *  - kinetic CV=0.79 → PASS; airfoil CV=0.055 → REFUSAL (граница R²=−6.4 —
 *    честный отказ, эмпирика и не сертифицировалась).
 */
final class MetricPreflightTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PREFLIGHT_GATE_FACTOR');
    }

    /** y = a + b*x с заданным CV(y): a = sd_raw/CV − mean_raw (проба 16.09:
     *  наивная добавка sd/CV удваивала mean → фактический CV был вдвое ниже). */
    private function yWithCv(float $targetCv, int $n = 200): array
    {
        mt_srand(555);
        $b = 10.0;
        $x = [];
        for ($i = 0; $i < $n; $i++) {
            $x[] = mt_rand() / mt_getrandmax();
        }
        $raw = array_map(fn (float $v): float => $b * $v, $x);
        $mRaw = array_sum($raw) / $n;
        $sd = 0.0;
        foreach ($raw as $v) {
            $sd += ($v - $mRaw) ** 2;
        }
        $sd = sqrt($sd / $n);
        $shift = $sd / $targetCv - $mRaw;

        return array_map(fn (float $v): float => $v + $shift, $raw);
    }

    public function testHighCvTargetPasses(): void
    {
        // CV(y)=0.50, гейт 0.15: 0.15 < 0.5*0.5=0.25 → PASS
        $y = $this->yWithCv(0.50);
        $out = MetricPreflight::check(0.15, $y);
        $this->assertTrue($out->passes, json_encode($out));
        $this->assertSame('PASS', $out->status);
    }

    public function testLowCvTargetRefused(): void
    {
        // CCPP-класс: CV(y)=0.0376, гейт 0.15 → 0.15 < 0.0188 false → REFUSAL
        $y = $this->yWithCv(0.0376);
        $out = MetricPreflight::check(0.15, $y);
        $this->assertFalse($out->passes);
        $this->assertSame('METRIC_DOMAIN', $out->status);
        // r2_floor отрицателен: гейт допускает предикторы хуже константы
        // (мусор-зона). Публикуем фактическое значение — оно и есть диагноз.
        $this->assertLessThan(1.0, $out->r2_floor, 'Граница R² публикуется (ниже 1 = мусор-зона)');
    }

    public function testBoundaryIsStrictInequality(): void
    {
        // CV(y) = 2*ε ровно: 0.15 < 0.5*0.30=0.15 false → REFUSAL (строгое <)
        $y = $this->yWithCv(0.30);
        $out = MetricPreflight::check(0.15, $y);
        $this->assertFalse($out->passes, 'Граница CV=2ε — строгое неравенство');
    }

    public function testNegativeMeanTargetUsesAbsoluteCv(): void
    {
        // RED-кейс пробы: mean(y)<0 → голый sd/mean отрицателен → сравнение ломается.
        // Контракт: cv = sd/|mean|.
        mt_srand(555);
        $y = $this->yWithCv(0.50);
        $y = array_map(fn (float $v): float => -$v, $y); // отражаем: mean<0, sd тот же
        $out = MetricPreflight::check(0.15, $y);
        $this->assertTrue($out->passes, 'Отражённый таргет (mean<0) обязан вести себя как исходный: ' . json_encode($out));
    }

    public function testConstantTargetRefusedRegardlessOfGate(): void
    {
        // CV(y)=0 (константа): ratio-CV вырожден целиком — любой гейт отказывает.
        $y = array_fill(0, 100, 5.0);
        $out = MetricPreflight::check(0.15, $y);
        $this->assertFalse($out->passes);
        $this->assertSame('METRIC_DOMAIN', $out->status);
    }

    public function testFactorZeroDisablesPreflight(): void
    {
        // Параметр (C): PREFLIGHT_GATE_FACTOR=0 → выключено (наследие v1.6).
        putenv('PREFLIGHT_GATE_FACTOR=0');
        $y = $this->yWithCv(0.0376); // CCPP-класс
        $out = MetricPreflight::check(0.15, $y);
        $this->assertTrue($out->passes, 'Фактор 0 = pre-flight выключен (v1.6 поведение)');
        $this->assertSame('DISABLED', $out->status);
    }

    public function testFactorFromEnvOverridesDefault(): void
    {
        // FACTOR=0.9: 0.15 < 0.9*0.10=0.09 false → REFUSAL при CV=0.10
        putenv('PREFLIGHT_GATE_FACTOR=0.9');
        $y = $this->yWithCv(0.10);
        $out = MetricPreflight::check(0.15, $y);
        $this->assertFalse($out->passes, 'Фактор из env применяется');
    }

    public function testCcppRealDomainRefused(): void
    {
        // Боевой кейс: реальные данные CCPP, CV(PE)=0.0376 (проба 16.09)
        $raw = file(__DIR__ . '/../data/CCPP_data.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($raw);
        $y = [];
        foreach (array_slice($raw, 0, 200) as $l) {
            $v = array_map('floatval', str_getcsv($l));
            if (count($v) === 5) {
                $y[] = $v[4];
            }
        }
        $out = MetricPreflight::check(0.15, $y);
        $this->assertFalse($out->passes, 'CCPP обязан отказываться до поиска (Demo#2 триггер)');
        // Граница R²: 1-(0.15/CV(PE))² по ФАКТИЧЕСКОЙ выборке 200 строк
        // (полный датасет 0.0376 → −14.9; на срезе 200 CV чуть иной —
        // пинним к спеке с допуском выборки).
        $this->assertEqualsWithDelta(-14.9, $out->r2_floor, 1.5);
    }
}
