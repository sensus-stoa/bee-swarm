<?php

// EXP-066 телескоп: где умирает ((x0×x1)+(x2×x3)) в L2-BEAM на I_18_4.
// Воспроизводит beam-отбор (L2_BEAM_K=40, cv по 30 строкам) и меряет ранг.

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Search;

$lines = file(__DIR__ . '/../Benchmarks/feynman20/feynman_I_18_4.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$h = str_getcsv(array_shift($lines));
$X = []; $y = [];
foreach (array_slice($lines, 0, 200) as $l) { $v = str_getcsv($l); $X[] = $v; $y[] = (float) end($v); }
$k = count($h) - 1;
$xs = [];
for ($c = 0; $c < $k; $c++) { $col = []; foreach ($X as $r) { $col[] = (float) $r[$c]; } $xs[] = $col; }

$m = 30; // как в beam: array_slice(..., min($n,30))
$yt = array_slice($y, 0, $m);

// L1: сырые + pairwise 4 ops
$l1 = [];
for ($a = 0; $a < $k; $a++) { $l1["x$a"] = array_slice($xs[$a], 0, $m); }
for ($a = 0; $a < $k; $a++) {
    for ($b = $a + 1; $b < $k; $b++) {
        foreach (['+', '×', '−', '/'] as $op) {
            $v = [];
            for ($i = 0; $i < $m; $i++) {
                $p = $xs[$a][$i]; $q = $xs[$b][$i];
                $v[] = $op === '+' ? $p + $q : ($op === '×' ? $p * $q : ($op === '−' ? $p - $q : ($q != 0 ? $p / $q : 0.0)));
            }
            $l1["({$h[$a]}$op{$h[$b]})"] = $v;
        }
    }
}

// полная имитация pair-loop (7 ops × C(n,2)) с cv-скорингом как в L2-BEAM
$ops = ['+', '×', '−', '/', 'min', 'max'];
$keys = array_keys($l1);
$scored = [];
foreach ($keys as $a) {
    foreach ($keys as $b) {
        if ($a === $b) { continue; }
        foreach ($ops as $op) {
            $va = $l1[$a]; $vb = $l1[$b];
            $v = [];
            for ($i = 0; $i < $m; $i++) {
                $p = $va[$i]; $q = $vb[$i];
                $v[] = $op === '+' ? $p + $q : ($op === '×' ? $p * $q : ($op === '−' ? $p - $q : ($op === '/' ? ($q != 0 ? $p / $q : 0.0) : ($op === 'min' ? min($p, $q) : max($p, $q)))));
            }
            $name = "({$a}{$op}{$b})";
            $scored[$name] = cvf2($v, $yt);
        }
    }
}
asort($scored);
$rank = array_search('((x0×x1)+(x2×x3))', array_keys($scored), true);
echo "L2 форм всего: ", count($scored), "\n";
echo "ранг ((x0×x1)+(x2×x3)): ", $rank === false ? 'НЕ В СПИСКЕ' : $rank + 1, " (beam держит топ-40 по МЕНЬШЕМУ cv)\n";
$top = array_slice(array_keys($scored), 0, 8);
foreach ($top as $i => $n) { echo '  топ', $i + 1, ": $n cv=", round($scored[$n], 4), "\n"; }
if ($rank !== false) {
    echo "  cv целевой: ", round($scored['((x0×x1)+(x2×x3))'], 4),
        " | cv #40 (порог): ", round(array_values($scored)[39], 4), "\n";
}
// сигнальная квота: |corr| формы среди rest2 (не-kept)
function corrf(array $a, array $b): float
{
    $ma = array_sum($a) / count($a); $mb = array_sum($b) / count($b);
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < count($a); $i++) {
        $num += ($a[$i] - $ma) * ($b[$i] - $mb);
        $da += ($a[$i] - $ma) ** 2;
        $db += ($b[$i] - $mb) ** 2;
    }
    return $num / sqrt($da * $db + 1e-30);
}
$cvTarget = $scored['((x0×x1)+(x2×x3))'] ?? null;
if ($cvTarget !== null) {
    $better = 0; $corrTarget = abs(1.0 - $cvTarget > 0 ? sqrt(1.0 - $cvTarget) : 0.0);
    foreach ($scored as $n => $cv) {
        if ($cv < $cvTarget) { $better++; }
    }
    echo "форм с cv ЛУЧШЕ целевой: $better (все они тоже в beam-конкурсе)\n";
    // ранг по |corr| — критерий сигнальной квоты (top-10 среди не-kept)
    $corrRanked = [];
    foreach ($scored as $n => $cv) {
        $c2 = max(0.0, min(1.0, 1.0 - $cv));
        $corrRanked[$n] = abs(sqrt($c2));
    }
    arsort($corrRanked);
    $corrRank = array_search('((x0×x1)+(x2×x3))', array_keys($corrRanked), true);
    echo "ранг кирпича по |corr|: ", $corrRank === false ? 'НЕ НАЙДЕН' : $corrRank + 1,
        " (квота спасает топ-10 rest2 по |corr|)\n";
    echo "  |corr| кирпича: ", round($corrRanked['((x0×x1)+(x2×x3))'], 4),
        " | |corr| #10: ", round(array_values($corrRanked)[9], 4), "\n";
}

function cvf2(array $a, array $b): float
{
    $ma = array_sum($a) / count($a); $mb = array_sum($b) / count($b);
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < count($a); $i++) {
        $num += ($a[$i] - $ma) * ($b[$i] - $mb);
        $da += ($a[$i] - $ma) ** 2;
        $db += ($b[$i] - $mb) ** 2;
    }
    return 1.0 - $num / sqrt($da * $db + 1e-30);
}
