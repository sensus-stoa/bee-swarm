<?php
declare(strict_types=1);

/**
 * B5.9: Пересчёт one-step в КОМПОЗИЦИОННЫХ координатах (cLV, иллитовский
 * log-ratio) — проверка, переживает ли провал pairwise-переносимости
 * устранение артефакта относительных обилий (поправка ИИ-аналитика).
 *
 * Мера: dz_ij = dlog(p_i / p_j) = log p_i(t+1) - log p_i(t) - (log p_j(t+1)-log p_j(t))
 * Предсказатель их T3 в cLV: (u_i-u_j) + sum_k (a_ik - a_jk) p_k
 * (разность производных логов).
 *
 * Три вопроса:
 * 1. их T3 в cLV-мере на EV3: corr/R2 (против 0.015 в дельта-мере)
 * 2. наш per-pair фит в cLV-мере
 * 3. persistence baseline (dz=0) — тривиальный предсказатель
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$SBML = '/tmp/ven_s006/twelveSpeciesT3.xml';

// их T3 параметры
$xml = file_get_contents($SBML);
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

$raw = file($VEN . '/ev3_communities.csv');
$communities = [];
$cur = null;
foreach ($raw as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (preg_match('/^([A-Z]{2}-|NONE)/', $line)) { $cur = rtrim($line); $communities[$cur] = []; continue; }
    if ($cur !== null && preg_match('/^[\d\s.eE+-]+$/', $line)) {
        $communities[$cur][] = array_map('floatval', preg_split('/\s+/', trim($line)));
    }
}
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];

// --- сборка cLV-записей: для каждой пары (i,j) присутствующих видов
// dz_ij = наблюдаемая, dzPred_ij = их T3, dzOurs = наш per-pair ---
// floor: доли < 0.001 (4 знака, ниже — шум) не участвуют
$FLOOR = 0.001;

$obsDz = []; $predTheirDz = []; $meta = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        $row = []; $rowN = [];
        foreach ($ev3order as $c => $sp) { $row[$sp] = $mat[$t][$c]; $rowN[$sp] = $mat[$t + 1][$c]; }
        foreach ($ev3order as $c1 => $i) {
            foreach ($ev3order as $c2 => $j) {
                if ($i >= $j) { continue; } // пары (i<j)
                if ($row[$i] < $FLOOR || $row[$j] < $FLOOR) { continue; }
                if ($rowN[$i] < $FLOOR || $rowN[$j] < $FLOOR) { continue; }
                $zi = log($row[$i]); $zj = log($row[$j]);
                $ziN = log($rowN[$i]); $zjN = log($rowN[$j]);
                $dz = ($ziN - $zi) - ($zjN - $zj);
                // их T3: dlog p_i = u_i + sum_k (a_ik - sum_l a_lk p_l?) —
                // аккуратно: dp_i/dt = p_i(u_i + sum_k a_ik p_k) => d log p_i/dt = u_i + sum_k a_ik p_k
                // => dz_ij_pred = (u_i - u_j) + sum_k (a_ik - a_jk) p_k
                $sI = $params['u' . $i]; $sJ = $params['u' . $j];
                foreach ($ev3order as $k2) {
                    $pk = $row[$k2];
                    $sI += $params['a' . $i . $k2] * $pk;
                    $sJ += $params['a' . $j . $k2] * $pk;
                }
                $obsDz[] = $dz;
                $predTheirDz[] = $sI - $sJ;
                $meta[] = ['comm' => $label, 'i' => $i, 'j' => $j, 't' => $t, 'pi' => $row[$i], 'pj' => $row[$j]];
            }
        }
    }
}
$n = count($obsDz);
echo "cLV-записей (пары i<j, обе доли >= 0.001): $n\n";

$stat = function (array $obs, array $pred): array {
    $n = count($obs);
    $mo = array_sum($obs) / $n; $mp = array_sum($pred) / $n;
    $cov = 0.0; $va = 0.0; $vb = 0.0; $res = 0.0; $tot = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $cov += ($obs[$i] - $mo) * ($pred[$i] - $mp);
        $va += ($obs[$i] - $mo) ** 2; $vb += ($pred[$i] - $mp) ** 2;
        $res += ($obs[$i] - $pred[$i]) ** 2; $tot += ($obs[$i] - $mo) ** 2;
    }
    return [
        'corr' => ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0,
        'r2' => $tot > 1e-15 ? 1 - $res / $tot : NAN,
        'mae' => array_sum(array_map(fn ($k) => abs($obs[$k] - $pred[$k]), range(0, $n - 1))) / $n,
        'sd_obs' => sqrt($va), 'sd_pred' => sqrt($vb),
    ];
};

// 1. их T3
$s = $stat($obsDz, $predTheirDz);
printf("ИХ T3 в cLV-мере: corr=%+.3f R2=%+.3f MAE=%.3f (sd_obs=%.3f sd_pred=%.3f)\n", $s['corr'], $s['r2'], $s['mae'], $s['sd_obs'], $s['sd_pred']);

// 2. persistence baseline (dz=0)
$s0 = $stat($obsDz, array_fill(0, $n, 0.0));
printf("PERSISTENCE (dz=0):          MAE=%.3f (этоbaseline без модели)\n", $s0['mae']);

// 3. наш per-pair в cLV-мере: dlog p_i - dlog p_j предсказать из парной модели
// В паре x_i+x_j=1: dlog p_i = y_i (уже вычисляли); dlog p_j из данных не знаем
// (EV1 не измеряет второй вид) => per-pair фит даёт только dlog p_i. cLV-предсказание
// пары из EV1 невозможна по одному виду — честная граница измерена. Пропускаем.
echo "наш per-pair в cLV-мере: НЕПРИМЕНИМ (EV1 не измеряет второй вид пары — нет dlog p_j)\n";

// 4. разбивка их T3 по композициям (где лучше/хуже)
$byComm = [];
foreach ($obsDz as $k => $v) { $byComm[$meta[$k]['comm']][] = [$v, $predTheirDz[$k]]; }
echo "\nпо композициям (corr их T3):\n";
foreach ($byComm as $comm => $rows) {
    $o = array_column($rows, 0); $p = array_column($rows, 1);
    $st = $stat($o, $p);
    printf("  %-5s corr=%+.3f R2=%+.3f (n=%d)\n", $comm, $st['corr'], $st['r2'], count($rows));
}
