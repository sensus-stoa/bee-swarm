<?php
declare(strict_types=1);
// verify_residual.php: RESIDUAL LADDER protocol (P4 formalized).
// Flight k: find(d=2) on current target -> read marks -> re-measure corr on CURRENT
// residual -> best mark = new coordinate -> joint LSQ on basis -> residual -> next.
// Law = sum of coordinates with joint-LSQ coefficients. PASS: cv<=0.15, cvH<=0.10.
// Modes: php verify_residual.php dot 5   (fixture, nSeeds)

require '/home/ninjacat/.bee_swarm/vendor/autoload.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Infra\Database;

$fixture = $argv[1] ?? 'dot';
$nSeeds = (int) ($argv[2] ?? 5);
$csv = $fixture === 'dot' ? 'feynman_dot_product.csv' : 'feynman_kinetic_energy.csv';

putenv('SWARM_DB_PATH=/tmp/m1ext_residual_' . $fixture . '.db');
putenv('SEARCH_BEAM_K=10');
putenv('SEARCH_NO_PREREG=1');
putenv('PREFLIGHT_GATE_FACTOR=0');
putenv('TRAIL_MARKS=1');
putenv('SEARCH_RAWPAIR_POOL=1');

$db = Database::get();
$db->exec('DELETE FROM trail_marks');

function loadCsv(string $path): array {
    $X = []; $y = [];
    foreach (file($path) as $line) {
        $parts = explode(',', trim($line));
        if (count($parts) < 2) continue;
        $vals = array_map('floatval', $parts);
        $y[] = array_pop($vals);
        $X[] = $vals;
    }
    return [$X, $y];
}
function ratioCv(array $pred, array $y): float {
    $eps = 1e-9; $n = count($y); $ratio = [];
    for ($i = 0; $i < $n; $i++) { $ratio[] = abs($pred[$i]) / (abs($y[$i]) + $eps); }
    $m = array_sum($ratio) / $n;
    if (abs($m) < $eps) return INF;
    $var = 0.0;
    foreach ($ratio as $r) { $var += ($r - $m) ** 2; }
    return sqrt($var / $n) / abs($m);
}
// ORBIT COMPLETION (analyst 12.10, §2.4): accepted stair -> missing siblings
// over uncovered features. Structural prior, NO thresholds, NO engine change.
function orbitCompletions(string $stair, int $nFeat): array {
    if (!str_starts_with($stair, '((') || !str_ends_with($stair, '))')) { return []; }
    $inner = substr($stair, 1, -1);
    $parts = explode(')+(', $inner);
    if (count($parts) !== 2) { return []; }
    $terms = [trim($parts[0], '()'), trim($parts[1], '()')];
    $pairs = []; $bases = [];
    foreach ($terms as $t) {
        if (!str_contains($t, '×')) { return []; }
        $pp = explode('×', $t);
        if (count($pp) !== 2) { return []; }
        $pairs[] = $pp;
        foreach ($pp as $opnd) {
            if (preg_match('/^(x\d+)/', $opnd, $m) === 1) { $bases[$m[1]] = true; }
        }
    }
    $a1 = $pairs[0][0]; $b1 = $pairs[0][1]; $a2 = $pairs[1][0];
    $out = [];
    if ($a1 === $a2) {
        $pow = str_ends_with($b1, '²') ? '²' : '';
        for ($i = 0; $i < $nFeat; $i++) {
            $u = 'x' . $i;
            if (isset($bases[$u])) { continue; }
            $out[] = '(' . $a1 . '×' . $u . $pow . ')';
        }
    } else {
        $unc = [];
        for ($i = 0; $i < $nFeat; $i++) {
            if (!isset($bases['x' . $i])) { $unc[] = 'x' . $i; }
        }
        if (count($unc) === 2) { $out[] = '(' . $unc[0] . '×' . $unc[1] . ')'; }
    }
    return $out;
}

function rowsSub(array $v, array $rows): array {
    $out = [];
    foreach ($rows as $i) { $out[] = $v[$i]; }
    return $out;
}

// JOINT fold-gain: Δ_h(f|B) = 1 - RSS_h(B+f)/RSS_h(B), 2-fold; B fit on the
// fit-half only (analyst §3.4). Единственный ранг, при котором точный член
// T1 (маргинально слабый) выигрывает у монстра-смеси (joint refit).
function jointFoldGain(array $colsB, array $cand, array $y, int $n): float {
    $h = (int) ($n / 2);
    $gain = 0.0;
    $all = array_merge($colsB, [$cand]);
    for ($fold = 0; $fold < 2; $fold++) {
        $fitRows = $fold === 0 ? range(0, $h - 1) : range($h, $n - 1);
        $valRows = $fold === 0 ? range($h, $n - 1) : range(0, $h - 1);
        $yF = rowsSub($y, $fitRows);
        $ssB = 0.0;
        foreach ($valRows as $i) { $ssB += $y[$i] * $y[$i]; }
        if ($ssB < 1e-12) { return -1.0; }
        $rss1 = $ssB;
        if (count($colsB) > 0) {
            $ab = jointLsq(array_map(fn ($c) => rowsSub($c, $fitRows), $colsB), $yF);
            $rss1 = 0.0;
            foreach ($valRows as $i) {
                $p = 0.0;
                foreach ($colsB as $ci => $c) { $p += $ab[$ci] * $c[$i]; }
                $d = $y[$i] - $p;
                $rss1 += $d * $d;
            }
        }
        $ac = jointLsq(array_map(fn ($c) => rowsSub($c, $fitRows), $all), $yF);
        $rss2 = 0.0;
        foreach ($valRows as $i) {
            $p = 0.0;
            foreach ($all as $ci => $c) { $p += $ac[$ci] * $c[$i]; }
            $d = $y[$i] - $p;
            $rss2 += $d * $d;
        }
        $gain += 1.0 - $rss2 / max($rss1, 1e-12);
    }
    return $gain / 2.0;
}

// joint LSQ: min ||y - V a||; V = [col0, col1, ...]; returns coefficients
function jointLsq(array $cols, array $y): array {
    $k = count($cols); $n = count($y);
    $g = array_fill(0, $k, array_fill(0, $k, 0.0));
    $b = array_fill(0, $k, 0.0);
    for ($p = 0; $p < $k; $p++) {
        for ($q = 0; $q < $k; $q++) {
            $s = 0.0;
            for ($i = 0; $i < $n; $i++) { $s += $cols[$p][$i] * $cols[$q][$i]; }
            $g[$p][$q] = $s;
        }
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) { $s += $cols[$p][$i] * $y[$i]; }
        $b[$p] = $s;
    }
    // Gaussian elimination with partial pivot
    for ($col = 0; $col < $k; $col++) {
        $piv = $col;
        for ($row = $col + 1; $row < $k; $row++) {
            if (abs($g[$row][$col]) > abs($g[$piv][$col])) { $piv = $row; }
        }
        if (abs($g[$piv][$col]) < 1e-12) { continue; }
        if ($piv !== $col) {
            [$g[$piv], $g[$col]] = [$g[$col], $g[$piv]];
            [$b[$piv], $b[$col]] = [$b[$col], $b[$piv]];
        }
        for ($row = $col + 1; $row < $k; $row++) {
            $f = $g[$row][$col] / $g[$col][$col];
            for ($c = $col; $c < $k; $c++) { $g[$row][$c] -= $f * $g[$col][$c]; }
            $b[$row] -= $f * $b[$col];
        }
    }
    $a = array_fill(0, $k, 0.0);
    for ($row = $k - 1; $row >= 0; $row--) {
        $s = $b[$row];
        for ($c = $row + 1; $c < $k; $c++) { $s -= $g[$row][$c] * $a[$c]; }
        $a[$row] = (abs($g[$row][$row]) < 1e-12) ? 0.0 : $s / $g[$row][$row];
    }
    return $a;
}

[$X, $y] = loadCsv('/home/ninjacat/.bee_swarm/data/' . $csv);
$n = count($y);
$g = new Grammar(); $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
$MAXFLIGHTS = 4;

$found = 0; $flightSum = 0;
for ($seed = 1; $seed <= $nSeeds; $seed++) {
    mt_srand($seed);
    $idx = range(0, $n - 1);
    for ($i = $n - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
    $nTr = (int) floor($n * 0.8);
    $Xtr = array_map(fn($i) => $X[$i], array_slice($idx, 0, $nTr));
    $ytr = array_map(fn($i) => $y[$i], array_slice($idx, 0, $nTr));
    $Xte = array_map(fn($i) => $X[$i], array_slice($idx, $nTr));
    $yte = array_map(fn($i) => $y[$i], array_slice($idx, $nTr));
    $db->exec('DELETE FROM preregistrations');

    $basis = [];      // [name => vec]
    $yCur = $ytr;
    $varY = 0.0;
    foreach ($ytr as $v) { $varY += ($v - array_sum($ytr) / $nTr) ** 2; }
    $assembled = false; $flightsUsed = 0;
    $t0 = microtime(true);
    $varRPrev = $varY;
    $orbitSeen = []; $orbitCands = [];
    $acceptGate = 0.95;   // EXPANDING GATE: расширяется при каждом отказе (0.95→0.98→0.992), сброс при приёме

    for ($flight = 1; $flight <= $MAXFLIGHTS; $flight++) {
        $flightsUsed = $flight;
        $res = Search::find($Xtr, $yCur, $g, 2, null, 0.2, 0.15, 90.0);
        $candName = null; $candVec = null; $candVecTe = null;
        // candidate 1: the flight's own found formula (a law on the current target)
        if ($res[0]) {
            $v = ExpressionEvaluator::evaluateFormula($res[2], $Xtr);
            $vTe = ExpressionEvaluator::evaluateFormula($res[2], $Xte);
            if ($v !== null && $vTe !== null && ! isset($basis['FLIGHT:' . $res[2]])) { $candName = 'FLIGHT:' . $res[2]; $candVec = $v; $candVecTe = $vTe; }
        }
        // candidate 2: stigmergy — re-measure ALL marks on the CURRENT residual,
        // gate+rank: corr > 0.5 на полном train (состояние 18/20; CV-ранжирование
        // эксперимент 12.10: убирает монстров на flight-1, но лестница голодает
        // дальше — T1-лепестки corr 0.22 не проходят сигнальный гейт 0.5)
        if ($candName === null) {
            $stmt = $db->prepare('SELECT formula FROM trail_marks');
            $stmt->execute();
            $bestRed = 0.0; $bestName = null; $bestVec = null; $bestVecTe = null;
            $diag = [];
            $colsB = array_map(fn (array $p): array => $p['tr'], array_values($basis));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mf) {
                if (isset($basis[$mf])) { continue; }
                $mv = ExpressionEvaluator::evaluateFormula($mf, $Xtr);
                if ($mv === null) { continue; }
                $ok = true;
                foreach ($mv as $mvv) { if ($mvv === null || ! is_finite($mvv)) { $ok = false; break; } }
                if (! $ok) { continue; }
                // prefilter: corr with current target on full train
                $meanV = array_sum($mv) / $nTr;
                $meanY = array_sum($yCur) / $nTr;
                $cov = 0.0; $vv = 0.0; $vy = 0.0;
                for ($i = 0; $i < $nTr; $i++) { $dv = $mv[$i] - $meanV; $dy = $yCur[$i] - $meanY; $cov += $dv * $dy; $vv += $dv * $dv; $vy += $dy * $dy; }
                $corr = ($vv > 1e-12 && $vy > 1e-12) ? abs($cov / sqrt($vv * $vy)) : 0.0;
                // rank: JOINT fold-gain — точный член обгоняет монстра-смесь
                $red = jointFoldGain($colsB, $mv, $yCur, $nTr);
                $diag[] = sprintf("%.3f/%.2f %s", $red, $corr, $mf);
                if ($red > $bestRed) {
                    $mvTe = ExpressionEvaluator::evaluateFormula($mf, $Xte);
                    if ($mvTe === null) { continue; }
                    $bestRed = $red; $bestName = $mf; $bestVec = $mv; $bestVecTe = $mvTe;
                }
            }
            foreach ($orbitCands as $ocName => $ocPair) {
                if (isset($basis[$ocName])) { continue; }
                $mv = $ocPair['tr'];
                $meanV = array_sum($mv) / $nTr;
                $meanY = array_sum($yCur) / $nTr;
                $cov = 0.0; $vv = 0.0;
                for ($i = 0; $i < $nTr; $i++) { $dv = $mv[$i] - $meanV; $dy = $yCur[$i] - $meanY; $cov += $dv * $dy; $vv += $dv * $dv; }
                $red = jointFoldGain($colsB, $mv, $yCur, $nTr);
                $diag[] = sprintf("%.3f ORBIT %s", $red, $ocName);
                if ($red > $bestRed) {
                    $bestRed = $red; $bestName = 'ORBIT:' . $ocName; $bestVec = $mv; $bestVecTe = $ocPair['te'];
                }
            }
            rsort($diag);
            foreach (array_slice($diag, 0, 4) as $d) { echo "      cand $d\n"; }
            if ($bestName !== null) { $candName = $bestName; $candVec = $bestVec; $candVecTe = $bestVecTe; }
        }
        if ($candName === null) {
            echo sprintf("    [flight %d] NO CANDIDATE (found=%s, marks re-measured: none > gate)\n", $flight, $res[0] ? 'true' : 'false');
            break;
        }

        // ACCEPT only if the candidate REDUCES residual variance (joint refit)
        $trial = $basis;
        $trial[$candName] = ['tr' => $candVec, 'te' => $candVecTe];
        $cols = array_map(fn (array $p): array => $p['tr'], array_values($trial));
        $a = jointLsq($cols, $ytr);
        $fit = array_fill(0, $nTr, 0.0);
        foreach ($cols as $ci => $cvv) { for ($i = 0; $i < $nTr; $i++) { $fit[$i] += $a[$ci] * $cvv[$i]; } }
        $resid = [];
        for ($i = 0; $i < $nTr; $i++) { $resid[] = $ytr[$i] - $fit[$i]; }
        $varR = 0.0;
        foreach ($resid as $r) { $varR += $r * $r; }
        if ($varR >= $varRPrev * 0.95) { break; }   // 5%-гейт (состояние 18/20); расширение гейта — следующая сторю
        echo sprintf("    [flight %d] ACCEPT %s (varR %.4f -> %.4f)\n", $flight, substr((string) $candName, 0, 60), $varRPrev, $varR);
        $basis = $trial; $varRPrev = $varR;
        $bareName = str_starts_with($candName, 'FLIGHT:') ? substr($candName, 7) : (str_starts_with($candName, 'ORBIT:') ? substr($candName, 6) : $candName);
        foreach (orbitCompletions($bareName, count($Xtr[0] ?? [])) as $oc) {
            if (isset($basis[$oc]) || isset($orbitSeen[$oc])) { continue; }
            $ovTr = ExpressionEvaluator::evaluateFormula($oc, $Xtr);
            $ovTe = ExpressionEvaluator::evaluateFormula($oc, $Xte);
            if ($ovTr === null || $ovTe === null) { continue; }
            $okO = true;
            foreach ($ovTr as $ovv) { if ($ovv === null || ! is_finite($ovv)) { $okO = false; break; } }
            if (! $okO) { continue; }
            $orbitSeen[$oc] = true;
            $orbitCands[$oc] = ['tr' => $ovTr, 'te' => $ovTe];
            echo "    [orbit] + $oc\n";
        }
        if ($varR / max($varY, 1e-12) < 1e-10) { $assembled = true; break; }
        $yCur = $resid;
    }

    // final joint fit on train basis -> cv + cvH (holdout vectors are computed
    // on Xte SEPARATELY — bug 12.10: индексация train-векторов как holdout)
    $colsTr = []; $colsTe = [];
    foreach ($basis as $bname => $pair) {
        $colsTr[] = $pair['tr'];
        $colsTe[] = $pair['te'];
    }
    $a = jointLsq($colsTr, $ytr);
    $fitTr = array_fill(0, count($ytr), 0.0);
    foreach ($colsTr as $ci => $cvv) { for ($i = 0; $i < count($ytr); $i++) { $fitTr[$i] += $a[$ci] * $cvv[$i]; } }
    $cv = ratioCv($fitTr, $ytr);
    $fitTe = array_fill(0, count($yte), 0.0);
    foreach ($colsTe as $ci => $cvv) { for ($i = 0; $i < count($yte); $i++) { $fitTe[$i] += $a[$ci] * $cvv[$i]; } }
    $cvH = ratioCv($fitTe, $yte);
    $ok = ($cv <= 0.15 && $cvH <= 0.10);
    if ($ok) { $found++; $flightSum += $flightsUsed; }
    echo sprintf("seed %d: %s flights=%d basis=%d cv=%.4f cvH=%.4f %s\n    basis: %s\n",
        $seed, $ok ? 'PASS' : 'fail', $flightsUsed, count($basis), $cv, $cvH, $ok ? '+1' : '',
        implode(' | ', array_map(fn($k) => strlen($k) > 60 ? substr($k, 0, 57) . '...' : $k, array_keys($basis))));
}
echo sprintf("RESIDUAL RESULT [%s]: %d/%d PASS, avg flights %.1f\n", $fixture, $found, $nSeeds, $nSeeds > 0 ? $flightSum / max(1, $found) : 0);
if (($argv[3] ?? '') === 'debug') {
$stmt = $db->prepare('SELECT formula FROM trail_marks');
$stmt->execute();
$allMarks = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "MARKS DUMP (" . count($allMarks) . "):\n";
foreach ($allMarks as $mf) { echo "  " . $mf . "\n"; }
}
echo "RESIDUAL DONE\n";
