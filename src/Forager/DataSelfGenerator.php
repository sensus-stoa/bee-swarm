<?php

declare(strict_types=1);

namespace BeeSwarm\Forager;

use BeeSwarm\Infra\Database;

/**
 * DataSelfGenerator: рой САМ генерирует данные.
 */
class DataSelfGenerator
{
    private string $metricsPath;

    public function __construct(
        ?string $metricsPath = null
    ) {
        $this->metricsPath = $metricsPath
            ?? (getenv('METRICS_PATH') ?: getenv('HOME') . '/metrics.jsonl');
    }
    /**
     * Генерирует задачи из metrics.jsonl — все комбинации метрик.
     * Исправлено: пары с реальными данными.
     */
    public function fromMetrics(): array
    {
        $path = $this->metricsPath;
        if (! file_exists($path)) {
            return [];
        }

        $lines = file($path);
        $metrics = [];
        foreach ($lines as $l) {
            if (trim($l)) {
                $metrics[] = json_decode(trim($l), true);
            }
        }
        if (empty($metrics)) {
            return [];
        }

        // Берём ВСЕ числовые ключи из ВСЕХ записей
        $available = [];
        foreach ($metrics as $m) {
            foreach ($m as $k => $v) {
                if (is_numeric($v) && ! isset($available[$k])) {
                    $available[$k] = true;
                }
            }
        }
        $available = array_keys($available);

        $tasks = [];

        // ПАРЫ метрик
        for ($i = 0; $i < count($available); $i++) {
            for ($j = $i + 1; $j < count($available); $j++) {
                $k1 = $available[$i];
                $k2 = $available[$j];
                $pairs = [];
                foreach ($metrics as $m) {
                    $v1 = $m[$k1] ?? null;
                    $v2 = $m[$k2] ?? null;
                    if (is_numeric($v1) && is_numeric($v2)) {
                        $pairs[] = [(float) $v1, (float) $v2];
                    }
                }
                if (count($pairs) >= 10) {
                    $tasks[] = [
                        'name' => $k1 . '→' . $k2,
                        'domain' => 'metrics',
                        'data' => $pairs,
                        'points' => count($pairs),
                        'source' => 'self_generated_metrics',
                    ];
                }
            }
        }

        return $tasks;
    }

    /**
     * Генерирует КОНКРЕТНЫЕ синтетические данные из законов.
     * Если ADD = x0 + x1 в arithmetic → создаёт данные для physics.
     */
    public function fromLaws(): array
    {
        $db = Database::get();
        $laws = $db->query('SELECT name, formula, domain FROM laws WHERE cv < 0.05 LIMIT 10')
            ->fetchAll();
        $domains = $db->query('SELECT DISTINCT domain FROM laws')
            ->fetchAll();
        $domainList = array_column($domains, 'domain');

        $tasks = [];

        foreach ($laws as $law) {
            foreach ($domainList as $targetDomain) {
                if ($targetDomain === $law['domain']) {
                    continue;
                }

                // Генерируем synthetic data: 5 точек
                $synthetic = [];
                for ($x = 1; $x <= 5; $x++) {
                    $val = $this->evaluateSimpleFormula($law['formula'], $x, $x * 1.5);
                    $synthetic[] = [$x, $x * 1.5, $val];
                }

                if (! empty($synthetic)) {
                    $tasks[] = [
                        'name' => "{$law['name']}_in_{$targetDomain}",
                        'domain' => $targetDomain,
                        'data' => $synthetic,
                        'source' => 'law_transfer',
                        'original_formula' => $law['formula'],
                        'original_domain' => $law['domain'],
                    ];
                }
            }
        }

        return $tasks;
    }

    private function evaluateSimpleFormula(string $formula, float $a, float $b): float
    {
        // Простые случаи
        if ($formula === '(x0+x1)') {
            return $a + $b;
        }
        if ($formula === '(x0×x1)') {
            return $a * $b;
        }
        if ($formula === '(x0−x1)') {
            return $a - $b;
        }
        if (str_contains($formula, '/K2')) {
            return $a / 2;
        }
        if (str_contains($formula, '×K2')) {
            return $a * 2;
        }
        // fallback: random-ish but deterministic
        return $a + $b * 0.5;
    }
}
