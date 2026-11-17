<?php
// CERT-V2 калибровка: 18 cert_zone-форм из JSON -> 5 независимых сплитов
// (80/20, production ratio) -> k/5 таблица. Плюс E2-форма (0.0962) и
// E3-2term (0.1009 full) как пограничные контроли.
require __DIR__ . '/../../vendor/autoload.php';
$forms = [];
$base = getenv('CERT_FORMS_DIR') ?: '/tmp/e1lite_e2';
foreach (glob($base . '/*/results_*.json') as $p) {
    $d = json_decode((string) file_get_contents($p), true);
    if (! is_array($d)) continue;
    foreach ([$d['tasks'] ?? []] as $tasks) {
        foreach ($tasks as $t) {
            if (! is_array($t) || ! empty($t['null'])) continue;
            foreach (($t['routes'] ?? []) as $r) {
                if (! is_array($r) || ($r['found'] ?? false) !== true) continue;
                if (($r['cv_eval_found'] ?? null) === null || $r['cv_eval_found'] > 0.10) continue;
                $key = $t['id'] . '|' . ($r['cert_formula_raw'] ?? '');
                $forms[$key] = ['task' => $t['id'], 'form' => $r['cert_formula_raw'], 'cv1' => $r['cv_eval_found']];
            }
        }
    }
}
echo "unique cert-форм: " . count($forms) . "\n";

$tasks = [];
foreach (json_decode((string) file_get_contents('/tmp/e2_fam.json'), true)['tasks'] as $t) {
    $tasks[$t['id']] = $t;
}

function genTask(array $t): array
{
    $s = $t['seed'];
    $r = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
        return $s / 0x7FFFFFFF;
    };
    $u = function (float $a, float $b) use ($r): float {
        return $a + $r() * ($b - $a);
    };
    $X = []; $y = [];
    for ($i = 0; $i < 200; $i++) {
        $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
        $c[] = $u(0.5, 4);
        $yv = $c[0] * $c[3] + $c[1] * $c[4] + $c[2] * $c[5] + $c[3] * $c[5];
        $row = [];
        foreach ($t['perm'] as $src) { $row[] = $c[$src]; }
        $X[] = $row; $y[] = $yv;
    }
    return [$X, $y];
}

function cvRatio(array $f, array $y): ?float
{
    $ratios = [];
    foreach ($f as $i => $p) {
        if ($y[$i] == 0.0 || ! is_finite((float) $p)) return null;
        $ratios[] = (float) $p / $y[$i];
    }
    $mean = array_sum($ratios) / count($ratios);
    if ($mean == 0.0) return null;
    $var = 0.0;
    foreach ($ratios as $rv) { $var += ($rv - $mean) ** 2; }
    return sqrt($var / count($ratios)) / abs($mean);
}

function labels_to_x(string $formula): string
{
    $out = $formula;
    $kept = [];
    $out = (string) preg_replace_callback('/RL[0-9a-f]{8}/', function ($m) use (&$kept) {
        $key = "\x00" . count($kept) . "\x00";
        $kept[$key] = $m[0];
        return $key;
    }, $out);
    $out = (string) preg_replace_callback('/c(\d+)/', fn ($m) => 'x' . $m[1], $out);
    return strtr($out, $kept);
}

echo "task | form | k/5 (CV по сплитам, 80/20)\n";
$results = [];
foreach ($forms as $f) {
    $t = $tasks[$f['task']];
    [$X, $y] = genTask($t);
    $fx = labels_to_x($f['form']);
    $cvs = [];
    for ($sp = 0; $sp < 5; $sp++) {
        mt_srand($f['task'] === 'E3' ? 253262924 + $sp : $t['seed'] + $sp * 104729);
        $idx = range(0, 199);
        for ($i = 199; $i > 0; $i--) {
            $j = (int) floor((mt_rand() / mt_getrandmax()) * ($i + 1));
            [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
        }
        // PRODUCTION RATIO: 60/40 (split_eval в driver) — калибровка обязана
        // повторять production, иначе перенос распределения CV — гипотеза.
        $te = array_slice($idx, 120);
        $fe = []; $ye = [];
        foreach ($te as $i) { $fe[] = null; $ye[] = $y[$i]; }
        $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($fx, array_map(fn ($i) => $X[$i], $te));
        if ($vals === null) { $cvs[] = null; continue; }
        $fe = $vals;
        $cvs[] = cvRatio($fe, $ye);
    }
    $k = count(array_filter($cvs, fn ($v) => $v !== null && $v <= 0.10));
    $results[$f['task']][] = $k;
    printf("%s | %s | k=%d/5 | %s\n", $f['task'], substr($f['form'], 0, 40), $k,
        implode('/', array_map(fn ($v) => $v === null ? 'n' : number_format($v, 4), $cvs)));
}
echo "\nСводка по задачам (уникальные формы): ";
foreach ($results as $task => $ks) {
    $uniq = array_count_values($ks);
    echo "$task: " . json_encode($uniq) . " ";
}
echo "\n";
