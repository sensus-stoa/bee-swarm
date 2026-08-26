<?php
declare(strict_types=1);

/**
 * EXP-030: Culture as Semantic Compression (предрегистрирован 26.08).
 *
 * B templates (заморожены): SUB(a,b), MUL(a,b)
 * Instantiation: все пары (xi, xj), i<j — на TRAIN only.
 * Отбор: finite → not-almost-constant → semantic dedup (|ρ|>0.999) → top K=16.
 * z-терминалы = opaque колонки X для Search::find. Depth 3 без изменений.
 * Holdout не участвует в отборе.
 *
 * Запуск: php scripts/culture_compress.php
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

const MAX_Z_PER_TEMPLATE = 16;
const DEDUP_RHO = 0.999;
const BUDGET_SEC = 30.0;
const N_SEEDS = 20;

/** Загрузка CSV: все колонки float, последняя = target */
function loadCsvC(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) < 2) {
            continue;
        }
        $vals = array_map('floatval', $parts);
        $y[] = array_pop($vals);
        $X[] = $vals;
    }
    return [$X, $y];
}

function loadWineC(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) < 14) {
            continue;
        }
        $y[] = (float) $parts[1];
        $feat = [];
        for ($i = 2; $i < 14; $i++) {
            $feat[] = (float) $parts[$i];
        }
        $X[] = $feat;
    }
    return [$X, $y];
}

function loadMpgC(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path) as $line) {
        $parts = preg_split('/\s+/', trim($line), 9);
        if (count($parts) < 8) {
            continue;
        }
        try {
            $hp = (float) $parts[3];
        } catch (\Throwable) {
            continue;
        }
        if ($hp === 0.0 || $parts[3] === '?') {
            continue;
        }
        $y[] = (float) $parts[0];
        $X[] = [(float) $parts[2], $hp, (float) $parts[4]];
    }
    return [$X, $y];
}

function frozenSplitC(array $X, array $y, int $seed): array
{
    mt_srand($seed);
    $n = count($y);
    $idx = range(0, $n - 1);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $nTr = (int) floor($n * 0.6);
    $tr = array_slice($idx, 0, $nTr);
    $te = array_slice($idx, $nTr);
    return [
        array_map(fn ($i) => $X[$i], $tr),
        array_map(fn ($i) => $y[$i], $tr),
        array_map(fn ($i) => $X[$i], $te),
        array_map(fn ($i) => $y[$i], $te),
    ];
}

/**
 * Instantiation B-шаблонов на TRAIN + отбор top-K.
 * Возвращает [zColumns, zProvenance]:
 *   zColumns[i] = вектор значений i-го z-терминала на train rows
 *   zProvenance[i] = ['op' => 'SUB'|'MUL', 'a' => xi, 'b' => xj]
 */
function buildZTerminals(array $Xtr, int $nFeat, string $template): array
{
    $n = count($Xtr);
    $candidates = [];
    for ($i = 0; $i < $nFeat; $i++) {
        for ($j = $i + 1; $j < $nFeat; $j++) {
            $colI = array_column($Xtr, $i);
            $colJ = array_column($Xtr, $j);
            $vec = [];
            $finite = true;
            for ($k = 0; $k < $n; $k++) {
                $v = ($template === 'SUB') ? ($colI[$k] - $colJ[$k]) : ($colI[$k] * $colJ[$k]);
                if (! is_finite($v)) {
                    $finite = false;
                    break;
                }
                $vec[] = $v;
            }
            if (! $finite) {
                continue;
            }
            // почти-константные — выкинуть
            $m = array_sum($vec) / $n;
            $var = 0.0;
            foreach ($vec as $v) {
                $var += ($v - $m) ** 2;
            }
            $std = sqrt($var / $n);
            if ($std < 1e-6 || abs($m) < 1e-12) {
                continue;
            }
            // deterministic score: |correlation с y| нельзя (y не передаём —
            // train-only ок, но проще std/|mean| — масштабная устойчивость)
            $candidates[] = [
                'op' => $template,
                'i' => $i,
                'j' => $j,
                'vec' => $vec,
                'score' => $std / (abs($m) + 1e-9),
            ];
        }
    }
    // deterministic sort по score desc
    usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

    // semantic dedup: корреляция |ρ| > 0.999 → дубликат
    // (vec-и держим отдельно — после отбора не нужны)
    $selected = [];
    $selectedVecs = [];
    foreach ($candidates as $cand) {
        $dup = false;
        foreach ($selectedVecs as $sv) {
            if (abs(pearson($cand['vec'], $sv)) > DEDUP_RHO) {
                $dup = true;
                break;
            }
        }
        if (! $dup) {
            $selectedVecs[] = $cand['vec'];
            unset($cand['vec']);
            $selected[] = $cand;
            if (count($selectedVecs) >= MAX_Z_PER_TEMPLATE) {
                break;
            }
        }
    }
    return $selected;
}

function pearson(array $a, array $b): float
{
    $n = count($a);
    if ($n !== count($b) || $n === 0) {
        return 0.0;
    }
    $ma = array_sum($a) / $n;
    $mb = array_sum($b) / $n;
    $num = 0.0;
    $da = 0.0;
    $db = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $na = $a[$i] - $ma;
        $nb = $b[$i] - $mb;
        $num += $na * $nb;
        $da += $na * $na;
        $db += $nb * $nb;
    }
    $den = sqrt($da * $db);
    return $den > 0 ? $num / $den : 0.0;
}

/**
 * Раскрытие z-формулы в исходные фичи (для отчёта complexity!).
 * 'z0*Z5/x4' → '((x1-x2)*(x0*x3))/x4'
 */
function expandZ(string $formula, array $prov): string
{
    krsort($prov); // z10..z19 раньше z0..z9
    $out = $formula;
    foreach ($prov as $zName => $p) {
        $expanded = match ($p['op']) {
            'SUB' => "({$p['a']}-{$p['b']})",
            'MUL' => "({$p['a']}×{$p['b']})",
            default => $zName,
        };
        $out = str_replace($zName, $expanded, $out);
    }
    return $out;
}

function cvRatioC(array $pred, array $y): float
{
    $eps = 1e-9;
    $n = count($y);
    $ratio = [];
    for ($i = 0; $i < $n; $i++) {
        $ratio[] = abs($pred[$i]) / (abs($y[$i]) + $eps);
    }
    $m = array_sum($ratio) / $n;
    if (abs($m) < $eps) {
        return INF;
    }
    $var = 0.0;
    foreach ($ratio as $r) {
        $var += ($r - $m) ** 2;
    }
    return sqrt($var / $n) / abs($m);
}

function medianC(array $a): float
{
    sort($a);
    $n = count($a);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
}

function percentileC(array $a, int $p): float
{
    sort($a);
    $n = count($a);
    $idx = (int) ceil($p / 100 * $n) - 1;
    return $a[max(0, min($n - 1, $idx))];
}

// ═══ ОСНОВНОЙ ПРОГОН ═══

$base = __DIR__ . '/..';
$tasks = [
    'feynman_heat' => ['file' => 'data/feynman_heat_conduction.csv', 'kind' => 'csv'],
    'feynman_dot' => ['file' => 'data/feynman_dot_product.csv', 'kind' => 'csv'],
    'wine' => ['file' => 'wine.data', 'kind' => 'wine'],
    'airfoil' => ['file' => 'data/airfoil_selfnoise.csv', 'kind' => 'csv'],
    'energy' => ['file' => 'data/energy_efficiency.csv', 'kind' => 'csv'],
];

echo "=== EXP-030: Culture as Semantic Compression ===\n";
echo "templates: SUB/MUL, K=" . MAX_Z_PER_TEMPLATE . "/template, dedup ρ>" . DEDUP_RHO . "\n";

foreach ($tasks as $name => $t) {
    $file = $base . '/' . $t['file'];
    if (! file_exists($file)) {
        echo "SKIP {$name}\n";
        continue;
    }
    [$X, $y] = match ($t['kind']) {
        'wine' => loadWineC($file),
        'mpg' => loadMpgC($file),
        default => loadCsvC($file),
    };

    echo "\n--- {$name} (" . count($y) . " rows) ---\n";
    $cvs = [];
    $expComplexities = [];
    for ($s = 1; $s <= N_SEEDS; $s++) {
        [$Xtr, $ytr, $Xte, $yte] = frozenSplitC($X, $y, $s);

        // z-терминалы на TRAIN
        $nFeat = count($Xtr[0]);
        $subTerms = buildZTerminals($Xtr, $nFeat, 'SUB');
        $mulTerms = buildZTerminals($Xtr, $nFeat, 'MUL');

        // Собираем z-колонки
        $prov = [];
        $zCols = [];
        $zi = 0;
        foreach (['SUB' => $subTerms, 'MUL' => $mulTerms] as $tpl => $terms) {
            foreach ($terms as $term) {
                $vec = [];
                $ci = array_column($Xtr, $term['i']);
                $cj = array_column($Xtr, $term['j']);
                foreach ($ci as $k => $_) {
                    $vec[] = ($tpl === 'SUB') ? ($ci[$k] - $cj[$k]) : ($ci[$k] * $cj[$k]);
                }
                $prov["z{$zi}"] = ['op' => $tpl, 'a' => "x{$term['i']}", 'b' => "x{$term['j']}"];
                $zCols["z{$zi}"] = $vec;
                $zi++;
            }
        }

        // X с z-терминалами (opaque!)
        $XtrZ = [];
        foreach ($Xtr as $k => $row) {
            $r = $row;
            foreach ($zCols as $zc) {
                $r[] = $zc[$k];
            }
            $XtrZ[] = $r;
        }
        $XteZ = [];
        // holdout z: те же пары, формулы из provenance
        foreach ($Xte as $k => $row) {
            $r = $row;
            foreach ($prov as $zn => $p) {
                $ai = (int) substr($p['a'], 1);
                $bi = (int) substr($p['b'], 1);
                $va = $row[$ai];
                $vb = $row[$bi];
                $r[] = ($p['op'] === 'SUB') ? ($va - $vb) : ($va * $vb);
            }
            $XteZ[] = $r;
        }

        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
        $t0 = microtime(true);
        $res = Search::find($XtrZ, $ytr, $g, 3, null, 0.0, 0.15, BUDGET_SEC);

        if (! $res[0] || $res[1] >= 9.0) {
            $cvs[] = 9.99;
            continue;
        }

        // Подстановка z→x-индексы для holdout-eval:
        // формула ссылается на z как x{nFeat+zi}
        $zNames = array_keys($prov);
        $xNames = array_map(fn ($zn) => 'x' . ($nFeat + (int) substr($zn, 1)), $zNames);
        $formula = str_replace($xNames, $zNames, $res[2]);
        // Теперь формула содержит z0..zN — вычисляем через evaluator с подстановкой
        // Проще: пересобираем holdout pred напрямую из provenance
        // Формула вида ((z0×z7)/x4)... — парсим вручную? НЕТ — используем
        // evaluateFormula с z-колонками как x-индексами:
        $XteFull = [];
        foreach ($Xte as $k => $row) {
            $r = $row;
            foreach ($zCols as $zn => $zc) {
                // z-значение на holdout row k — нужен тот же порядок что в train
                $zi2 = (int) substr($zn, 1);
                $p = $prov[$zn];
                $ai = (int) substr($p['a'], 1);
                $bi = (int) substr($p['b'], 1);
                $va = $row[$ai];
                $vb = $row[$bi];
                $r[$nFeat + $zi2] = ($p['op'] === 'SUB') ? ($va - $vb) : ($va * $vb);
            }
            $XteFull[] = $r;
        }
        $stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($res[2], $XtrZ);
        $predTe = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($res[2], $XteFull, $stats);
        $cvTe = ($predTe !== null && count($predTe) === count($yte)) ? cvRatioC($predTe, $yte) : 9.99;
        $cvs[] = $cvTe;

        // expanded complexity (после раскрытия z!)
        $expanded = expandZ($formula, $prov);
        $expComplexities[] = strlen(preg_replace('/[()]/', '', $expanded));
    }

    $accepted = array_filter($cvs, fn ($c) => $c <= 0.10);
    echo '  CV_H med=' . round(medianC($cvs), 4)
        . ' q05=' . round(percentileC($cvs, 5), 4)
        . ' q95=' . round(percentileC($cvs, 95), 4)
        . '  success=' . count($accepted) . '/' . N_SEEDS . "\n";
    if ($expComplexities) {
        echo '  expanded-complexity median=' . round(medianC($expComplexities)) . "\n";
    }
}
echo "\nDONE\n";
