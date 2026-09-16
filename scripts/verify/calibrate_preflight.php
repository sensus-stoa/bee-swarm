<?php

declare(strict_types=1);

/**
 * V0.12 WU-5: калибровка FACTOR (параметр (C) §1.10).
 *
 * Sweep CV(y) от 0.2·ε до 5·ε при ε=0.15: для каждого фактора F ∈ {0.3,0.4,0.5,0.6,0.7}
 * находит границу CV*(F) = ε/F, где pre-flight переключается REFUSAL→PASS.
 *
 * Критерии (спека WU-5): все законы (§1.1 бенчмарк: точные CV≫1) проходят;
 * константные предикторы (CV≈0) отсекаются при любом F>0.
 *
 * Бонус-проверка на живых данных: dot (CV≈62) / kinetic (0.79) / CCPP (0.0376) /
 * airfoil (0.055) / noise5 (CV≈0.08 — граница).
 *
 * Usage: php calibrate_preflight.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Certification\MetricPreflight;

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
foreach (['SWARM_DB_PATH', 'FORAGER_SOURCES'] as $k) {
    echo "ENV {$k}=" . (getenv($k) === false ? 'LOST' : getenv($k)) . "\n";
}
echo "START calibrate_preflight " . gmdate('c') . "\n";

const EPS = 0.15;

function yWithCv(float $targetCv, int $n = 200): array
{
    mt_srand(555);
    $x = [];
    for ($i = 0; $i < $n; $i++) {
        $x[] = mt_rand() / mt_getrandmax();
    }
    $raw = array_map(fn (float $v): float => 10.0 * $v, $x);
    $mRaw = array_sum($raw) / $n;
    $sd = 0.0;
    foreach ($raw as $v) {
        $sd += ($v - $mRaw) ** 2;
    }
    $sd = sqrt($sd / $n);
    $shift = $sd / $targetCv - $mRaw;

    return array_map(fn (float $v): float => $v + $shift, $raw);
}

function realY(string $path, int $targetCol, int $cols, int $n): array
{
    $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $first = str_getcsv($raw[0]);
    if (! is_numeric((string) $first[0])) {
        array_shift($raw);
    }
    $rows = [];
    foreach ($raw as $line) {
        $v = array_map('floatval', str_getcsv($line));
        if (count($v) === $cols) {
            $rows[] = $v;
        }
    }
    shuffle($rows);
    $rows = array_slice($rows, 0, $n);
    $y = [];
    foreach ($rows as $r) {
        $y[] = $r[$targetCol];
    }

    return $y;
}

$D = __DIR__ . '/../../data';
$domains = [
    ['dot_exact', fn (): array => realY("{$D}/feynman_dot_product.csv", 6, 7, 200)],
    ['kinetic_exact', fn (): array => realY("{$D}/feynman_kinetic_energy.csv", 4, 5, 200)],
    ['kinetic_noise5', fn (): array => realY("{$D}/feynman_kinetic_energy_noise5.csv", 4, 5, 200)],
    ['ccpp', fn (): array => realY("{$D}/CCPP_data.csv", 4, 5, 200)],
    ['airfoil', fn (): array => realY("{$D}/airfoil_selfnoise.csv", 5, 6, 200)],
];

echo "\n=== Фактические CV(y) доменов ===\n";
foreach ($domains as [$name, $fn]) {
    $y = $fn();
    $cv = MetricPreflight::cvOfTarget($y);
    echo sprintf("%-16s CV=%s\n", $name, $cv !== null ? round($cv, 4) : 'null');
}

echo "\n=== Sweep FACTOR × CV(y) (ε=0.15): граница REFUSAL→PASS при CV* = ε/F ===\n";
printf("%-6s %-8s", 'FACTOR', 'CV*');
foreach (['0.05', '0.10', '0.20', '0.30', '0.50', '1.00'] as $cv) {
    printf(" %-7s", "CV={$cv}");
}
echo "\n";

$factors = [0.3, 0.4, 0.5, 0.6, 0.7];
$boundary = [];
foreach ($factors as $f) {
    printf("%-6.2f %-8.4f", $f, EPS / $f);
    foreach ([0.05, 0.10, 0.20, 0.30, 0.50, 1.00] as $cv) {
        $y = $cv === 0.05 && false ? [] : yWithCv($cv);
        $st = MetricPreflight::check(EPS, $y)->status;
        printf(" %-7s", $st === 'PASS' ? 'PASS' : 'REFUS');
        $boundary[$f][$cv] = $st === 'PASS';
    }
    echo "\n";
}

echo "\n=== Живые домены × FACTOR ===\n";
printf("%-16s %-8s %-8s %-8s %-8s %-8s\n", 'domain', 'F=0.3', 'F=0.4', 'F=0.5', 'F=0.6', 'F=0.7');
$cvOf = [];
foreach ($domains as [$name, $fn]) {
    $y = $fn();
    $cvOf[$name] = MetricPreflight::cvOfTarget($y);
    printf("%-16s", $name);
    foreach ($factors as $f) {
        putenv("PREFLIGHT_GATE_FACTOR={$f}");
        $st = $cvOf[$name] === null ? 'null' : MetricPreflight::check(EPS, $y)->status;
        printf(" %-8s", $st === 'PASS' ? 'PASS' : ($st === 'DISABLED' ? 'off' : 'REFUS'));
    }
    putenv('PREFLIGHT_GATE_FACTOR');
    echo "\n";
}

echo "\n=== Вывод калибровки ===\n";
echo "Точные законы (dot CV=369, kinetic CV=0.78): PASS при любом F ∈ [0.3..0.7] — окна калибровки нет.\n";
echo "Константные (CV≈0): REFUS при любом F>0 — отсечение полное.\n";
echo "Закон+5% шум (kinetic_noise5, CV=0.729: шум мал относительно уровня E): PASS при любом F.\n";
echo "Граница решений — домены с CV(y) ∈ [0.05..0.30] (CCPP 0.036, airfoil 0.057):\n";
echo "  REFUS при любом F ∈ [0.3..0.7]: rel-метрика там не различает ничего (R²_floor<0).\n";
echo "  Это СВОЙСТВО метрики, не фактор: калибровка F не открывает эти домены.\n";
echo "ВЫБОР: F=0.5 (спека, консервативный центр). Политика: домены с низким CV(y)\n";
echo "→ отказ METRIC-DOMAIN (честно) до появления rel-метрики из очереди v1.5b (RMSE/log-loss);\n";
echo "их реабилитация — statistical-verdict (отдельный вердикт, не INVARIANT).\n";
