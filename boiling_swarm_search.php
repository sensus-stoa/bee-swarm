<?php
/**
 * boiling_swarm_search.php — bee_swarm поиск инварианта режима на акустических фичах.
 *
 * Протокол (пре-зарегистрирован в features_manifest.json / handoff):
 *  - Данные: code/data_swarm/features_boiling.csv (4 bench, 3483 окна, 6 фич).
 *    Фичи только акустические, метки только термические (y=1 в плёнке [T2+5, T3-5]).
 *  - Шаг 1: per-bench discover() — каждая запись ищет свою формулу (CV→0 цель).
 *  - Шаг 2: уникальная форма? нормировка скелета (R-атомы → R, константы → K).
 *  - Шаг 3: LOBO-проверка: формула bench A переносится на другие bench
 *    (threshold-free: сравнение ROC-AUC направления) — если форма инвариантна,
 *    это классификатор; если нет — surface/run-specific эмпирика.
 *
 * Запуск (laptop): php boiling_swarm_search.php
 */
require_once __DIR__ . '/vendor/autoload.php';

use BeeSwarm\Hive\DiscoveryEngine;
use BeeSwarm\Core\Grammar;

$csvPath = __DIR__ . '/code/data_swarm/features_boiling.csv';
if (!is_file($csvPath)) { fwrite(STDERR, "нет $csvPath — сначала запустить extraction на desktop\n"); exit(1); }

$rows = [];
if (($fh = fopen($csvPath, 'r')) !== false) {
    $hdr = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) $rows[] = array_combine($hdr, $r);
    fclose($fh);
}
$feats = ['hf_ratio', 'mid_ratio', 'lf_ratio', 'env_skew', 'burst10', 'hf_p90p50'];
$byBench = [];
foreach ($rows as $r) {
    if ($r['excl'] == '1') continue;
    $x = []; foreach ($feats as $i => $f) $x[$i] = (float)$r[$f];
    $byBench[$r['bench']][] = ['x' => $x, 'y' => (float)$r['y']];
}
$labels = ['hf_ratio','mid_ratio','lf_ratio','env_skew','burst10','hf_p90p50'];
$ops = array_merge(Grammar::baseOpNames(), ['add','sub','mul','div','abs','sq','log2','pow2','pow3','inverse']);
$benchList = array_keys($byBench);

$eng = new DiscoveryEngine();
$results = [];
foreach ($benchList as $bk) {
    $data = $byBench[$bk];
    $X = array_column($data, 'x'); $y = array_column($data, 'y');
    echo "─── $bk (n=" . count($y) . ", pos=" . intval(array_sum($y)) . ") ───\n";
    putenv('SEARCH_DEPTH=2');
    putenv('SEARCH_DEPTH_MAX=4');
    $t0 = microtime(true);
    [$found, $cv, $formula, $cvTest, $class] = $eng->discover($X, $y, $ops, 0.35, $labels);
    $dt = round(microtime(true) - $t0, 1);
    echo "  found=" . var_export($found, true) . " cv=$cv cvTest=$cvTest class=$class (${dt}s)\n";
    if ($formula !== null) echo "  FORMULA: $formula\n";
    $results[$bk] = ['found' => $found, 'cv' => $cv, 'formula' => $formula, 'cvTest' => $cvTest, 'class' => $class, 'n' => count($y), 'pos' => intval(array_sum($y)), 'sec' => $dt];
    // сохраняем сырые данные bench для шага 3
    file_put_contents("/tmp/bench_$bk.json", json_encode(['x' => $X, 'y' => $y]));
    flush();
}
file_put_contents(__DIR__ . '/code/data_swarm/swarm_per_bench_results.json', json_encode($results, JSON_PRETTY_PRINT));
echo "saved per-bench results\n";

/* ── Шаг 3: LOBO-переносимость формул (threshold-free, ROC-AUC по направлению) */
function rocAUC(array $scores, array $y): float {
    $pos = []; $neg = [];
    foreach ($y as $i => $yi) {
        if ($yi == 1) { $pos[] = $scores[$i]; } else { $neg[] = $scores[$i]; }
    }
    if (!$pos || !$neg) return 0.5;
    $auc = 0.0;
    foreach ($pos as $p) foreach ($neg as $n) {
        $auc += ($p > $n ? 1.0 : ($p == $n ? 0.5 : 0.0));
    }
    return $auc / (count($pos) * count($neg));
}
// утилита: вычислить формулу bench A на данных bench B (подстановка в RVM-подобный парсер не нужна —
// формулы в терминах фич; исполняем через eval с безопасной подстановкой чисел)
function evalFormula(string $formula, array $x, array $labels): ?float {
    // формула вида "(f1×...)" с именами фич; заменяем имена на значения
    $expr = str_replace(['×', '−'], ['*', '-'], $formula);
    foreach ($labels as $i => $name) {
        $expr = str_replace($name, var_export((float)$x[$i], true), $expr);
    }
    if (preg_match('/[^0-9\.\+\-\*\/\(\)\s]/', $expr)) return null; // только безопасные символы
    $v = @eval("return ($expr);");
    return is_numeric($v) ? (float)$v : null;
}
echo "\n═══ LOBO TRANSFER (ROC-AUC of formula A applied to bench B) ═══\n";
$transfer = [];
foreach ($results as $src => $r) {
    if (!$r['found'] || $r['formula'] === null) continue;
    foreach ($benchList as $dst) {
        if ($dst === $src) continue;
        $data = $byBench[$dst];
        $scores = []; $ys = [];
        foreach ($data as $k => $row) {
            $v = evalFormula($r['formula'], $row['x'], $labels);
            if ($v !== null) { $scores[] = $v; $ys[] = $row['y']; }
        }
        $auc = rocAUC($scores, $ys);
        $aucDir = max($auc, 1 - $auc); // направление может инвертироваться — фиксируем оба
        $transfer["$src->$dst"] = ['auc' => round($auc, 3), 'auc_dir' => round($aucDir, 3), 'n' => count($ys)];
        echo sprintf("  %s -> %s : AUC=%.3f (|AUC|=%.3f, n=%d)\n", $src, $dst, $auc, $aucDir, count($ys));
    }
}
file_put_contents(__DIR__ . '/code/data_swarm/swarm_transfer_results.json', json_encode($transfer, JSON_PRETTY_PRINT));
echo "saved transfer results\n";
