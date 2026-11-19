<?php
declare(strict_types=1);

/**
 * B5.11: Рождение композиционных B-атомов с метками на дороге.
 *
 * Вопрос: можем ли складывать устойчивые термы в атлас (грамматику) с
 * метками (domain=foraged_venturelli), чтобы reuse работал между
 * видами/прогонами? Канон: birth = CANDIDATE, активация после reuse>=1
 * (registerReuse), компрессия имени (B# короче definition).
 *
 * Пилот: по 4 видам с train-данными (BH/BO/BT/BU/BV/CA/CH/DP/EL/ER)
 * фитим per-species регрессию (B5.10: predM0 + b_if*m_f) — если OOS-форма
 * на вид повторяется по структуре (например (m_f×(x_i/R+x_i)) повторно
 * у нескольких видов), рожаем B-атом = (m_f×(x_i/R+x_i)) с меткой
 * 'foraged_venturelli' и проверяем reuse: у следующего вида тот же
 * термин должен ИСПОЛЬЗОВАТЬСЯ движком (touch → registerReuse → PROMOTED).
 *
 * Пилот мал: 3 вида x 2 метаболита (cyclic-AMP, nicotinamide).
 */

require __DIR__ . '/exp070_shared.php';
require __DIR__ . '/../vendor/autoload.php';
error_reporting(E_ALL & ~E_WARNING);

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

// ЧИСТАЯ грамматика (урок B5.10): :memory: — 11 базовых опов
Database::setPath(':memory:');

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$species = ['BH', 'BO', 'BT', 'BU', 'BV', 'CA', 'CH', 'DP', 'EL', 'ER', 'FP', 'PC'];

// их T3 параметры (для residual-цели)
$xml = file_get_contents('/tmp/ven_s006/twelveSpeciesT3.xml');
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

// --- EV4 + EV3 (как B5.10) ---
$rows = openpyxl_load($VEN . '/ev4_metabolites.csv');
$header = array_map(fn ($v) => trim(str_replace(["'", "\r"], '', $v)), $rows[0]);
$speciesEV4 = array_slice($header, 1);
$metab = [];
foreach (array_slice($rows, 1) as $r) {
    $name = trim(str_replace(["'", "\r"], '', $r[0]));
    if ($name === '') { continue; }
    foreach ($speciesEV4 as $c => $sp) {
        $v = trim((string) ($r[$c + 1] ?? ''));
        if (is_numeric($v)) { $metab[$name][$sp] = (float) $v; }
    }
}
$topM = ['cyclic-AMP_positive_9p70', 'nicotinamide_positive_3p'];

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

// per-species выборки: X=[x_i, m1, m2], y — ПАРАЛЛЕЛЬНО через pcntl_fork
// (12 ядер, у нас 4 вида; каждый вид = отдельный процесс, своё :memory:)
$TARGETS = ['BH', 'BO', 'ER', 'CH'];
if (isset($argv[1])) {
    $target = $argv[1]; // ребёнок: один вид
} else {
    $children = [];
    foreach ($TARGETS as $t) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            passthru(PHP_BINARY . ' -d memory_limit=4G ' . escapeshellarg(__FILE__) . ' ' . $t, $code);
            exit($code);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $st); }
    echo "--- атлас after pilot ---\n";
    $db = Database::get();
    try {
        $rows2 = $db->query("SELECT name, definition, status, birth_domain FROM grammar_ops WHERE source='birth'")->fetchAll();
        foreach ($rows2 as $r) { printf("  %s = %s [%s] (%s)\n", $r['name'], $r['definition'], $r['status'], $r['birth_domain']); }
        if (!$rows2) { echo "  (пусто — ни одного кандидата не родилось)\n"; }
    } catch (\Throwable $e) { echo '  (db: ' . $e->getMessage() . ")\n"; }
    exit(0);
}
$target = $argv[1];
{

    $X = []; $y = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            $xRow = [];
            foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
            $xi = $xRow[$target]; $xin = $mat[$t + 1][array_search($target, $ev3order, true)] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $feats = [$xi];
            foreach ($topM as $mn) {
                $pool = 0.0;
                foreach ($xRow as $j => $xj) { if ($j !== $target) { $pool += ($metab[$mn][$j] ?? 0.0) * $xj; } }
                $feats[] = $pool;
            }
            $X[] = $feats;
            $yRaw[] = ($xin - $xi) / $xi;
            $yRows[] = ['xi' => $xi, 'xRow' => $xRow, 'target' => $target];
        }
    }
    if (count($yRaw) < 20) { exit(0); } // ребёнок одного вида — не continue
    // цель = residual M0 (парная часть уже объяснена; движок ищет ТОЛЬКО
    // композиционную добавку — меньше вариация, честный терм)
    // M0-приращение вида i в композиции: u_i + sum_k a_ik p_k (cLV-форма их T3)
    $y = [];
    foreach ($yRows as $k => $rr) {
        $s = $params['u' . $rr['target']] ?? 0.0;
        foreach ($rr['xRow'] as $j => $pj) { $s += ($params['a' . $rr['target'] . $j] ?? 0.0) * $pj; }
        $y[] = $yRaw[$k] - $s;
    }
    $labels = array_merge(['x_i'], array_map(fn ($n) => 'm_' . substr($n, 0, 10), $topM));
    $g = new Grammar();
    $res = Search::find($X, $y, $g, 2, $labels, 0.3, 0.15, 300.0);
    $found = $res[0] ?? false;
    $form = $res[2] ?? 'none';
    $diag = $res[4] ?? '?';
    $bestSeen = (new \ReflectionClass('\BeeSwarm\Core\Search'))->getStaticProperties()['lastBestCvSeen'] ?? null;
    printf("%-3s (n=%d): found=%s form=%s cv=%s best_seen=%s diag=%s\n",
        $target, count($y), $found ? 'YES' : 'no', $form,
        is_numeric($res[3] ?? null) ? round($res[3], 3) : 'NA',
        is_numeric($bestSeen) ? round($bestSeen, 3) : '?', $diag);

    // Рождение: если форма не тривиальна и содержит m_ (композиционный терм)
    if ($found && !str_contains($form, 'R+') && strlen($form) >= 5 && str_contains($form, 'm_')) {
        // нормализуем метки фич к x0/x1/x2 (атом должен быть переносим между видами!)
        $norm = str_replace(['m_cyclic-AMP_p', 'm_nicotinamid'], ['x1', 'x2'], $form);
        $norm = preg_replace('/x_i/', 'x0', $norm);
        // компрессия: имя короче definition
        $name = 'BW' . substr(md5($norm), 0, 4);
        $db = Database::get();
        $exists = $db->prepare("SELECT name FROM grammar_ops WHERE definition = ?");
        $exists->execute([$norm]);
        $existing = $exists->fetchColumn();
        if ($existing) {
            echo "  reuse-кандидат: $existing уже есть (= $norm)\n";
            Grammar::registerReuse($existing, 'foraged_venturelli');
            echo "  registerReuse($existing) — reuse++\n";
        } else {
            Grammar::staticAdd($name, 'birth', $norm, 'foraged_venturelli');
            echo "  РОЖДЕНО: $name = $norm (domain=foraged_venturelli, candidate)\n";
        }
    }
}

echo "\n--- атлас after pilot ---\n";
$db = Database::get();
try {
    $rows2 = $db->query("SELECT name, definition, status, birth_domain FROM grammar_ops WHERE source='birth'")->fetchAll();
    foreach ($rows2 as $r) {
        printf("  %s = %s [%s] (%s)\n", $r['name'], $r['definition'], $r['status'], $r['birth_domain']);
    }
    if (!$rows2) { echo "  (пусто — ни одного кандидата не родилось)\n"; }
} catch (\Throwable $e) {
    echo '  (таблица недоступна: ' . $e->getMessage() . ")\n";
}
