<?php

declare(strict_types=1);

/**
 * EXP045 0h fitness-check (флаг owner, прекондиция Stage A):
 * различает ли текущий score (mean_delta + WIN_WEIGHT×wins − penalty×len)
 * геномы с разной дорожной активностью? Контрфактически: pre-fix score
 * (без wins-члена) на тех же записях.
 *
 * Реальные записи из кампании Stage A (results_*.json): 3 представителя
 * с ОДИНАКОВЫМ числом действий (изоляция penalty), разной активностью.
 */

require '/home/ninjacat/.bee_swarm/vendor/autoload.php';

use BeeSwarm\Hive\RouteLedger;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;

// --- Реальные записи из кампании (python-отбор: см. analyze_fitness_check) ---
$cases = json_decode(file_get_contents('/tmp/fitness_cases.json'), true);

// --- Конструируем геномы ОДИНАКОВОЙ длины (penalty изолирован от активности) ---
$len = $cases['fl_count'];
$actions = [];
for ($i = 0; $i < $len; $i++) {
    $actions[] = ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'];
}
$actions[0] = ['op' => 'USE_MARKS', 'k' => 5]; // M-действие для дорожных кейсов

$l = new RouteLedger();
$ids = [];
foreach (['A_winner', 'B_road_active', 'C_plain'] as $name) {
    $g = RouteGenome::create(
        'route_' . substr(sha1($name), 0, 8),
        '',
        0,
        ['kind' => 'ALWAYS'],
        $actions,
        0.10,
        count($actions)
    );
    $l->register($g);
    $ids[$name] = $g['id'];
}

// --- Записи из кампании ---
$c = $cases['cases'];
$l->recordOutcome($ids['A_winner'], 't', $c['A']['delta'], $c['A']['routeCertified'], 1.0, 0.0);
$l->recordOutcome($ids['B_road_active'], 't', $c['B']['delta'], $c['B']['routeCertified'], 1.0, 0.0);
$l->recordOutcome($ids['C_plain'], 't', $c['C']['delta'], $c['C']['routeCertified'], 1.0, 0.0);

$gw = $l->get($ids['A_winner']);
$gb = $l->get($ids['B_road_active']);
$gc = $l->get($ids['C_plain']);

echo "=== записи (реальные, из кампании Stage A) ===\n";
echo "A_winner      : wins={$gw['record']['wins']} fails={$gw['record']['fails']} delta_sum={$gw['record']['delta_L_valid_sum']} (novel-cert, road)\n";
echo "B_road_active : wins={$gb['record']['wins']} fails={$gb['record']['fails']} delta_sum={$gb['record']['delta_L_valid_sum']} (written/m3>0, не сертифицировал)\n";
echo "C_plain       : wins={$gc['record']['wins']} fails={$gc['record']['fails']} delta_sum={$gc['record']['delta_L_valid_sum']} (written=0, plain)\n";

echo "\n=== SCORE (текущий фитнес: mean_delta + WIN_WEIGHT×wins − 0.05×len) ===\n";
$sA = $l->score($gw);
$sB = $l->score($gb);
$sC = $l->score($gc);
echo "A_winner      : ", var_export($sA, true), "\n";
echo "B_road_active : ", var_export($sB, true), "\n";
echo "C_plain       : ", var_export($sC, true), "\n";
echo "B == C ? ", var_export(abs($sB - $sC) < 1e-12), " ← fitness слеп к дорожной активности?\n";
echo "A > B ? ", var_export($sA > $sB), " ← различие только через сертификацию?\n";

echo "\n=== КОНТРФАКТ: pre-fix score (БЕЗ wins-члена) на тех же записях ===\n";
$preA = $gw['record']['delta_L_valid_sum'] / max(1, $gw['record']['tasks_seen']) - 0.05 * count($actions);
$preB = $gb['record']['delta_L_valid_sum'] / max(1, $gb['record']['tasks_seen']) - 0.05 * count($actions);
$preC = $gc['record']['delta_L_valid_sum'] / max(1, $gc['record']['tasks_seen']) - 0.05 * count($actions);
echo "pre-fix A: ", var_export($preA, true), " | B: ", var_export($preB, true), " | C: ", var_export($preC, true), "\n";
echo "pre-fix: все три идентичны? ", var_export(abs($preA - $preB) < 1e-12 && abs($preB - $preC) < 1e-12), "\n";

echo "\n=== ВЕРДИКТ 0h fitness-check ===\n";
$postBlind = abs($sB - $sC) < 1e-12;
$preAllBlind = abs($preA - $preB) < 1e-12 && abs($preB - $preC) < 1e-12;
echo "Текущий фитнес различает ТОЛЬКО сертификацию (A vs B/C): ", var_export($sA > $sB && $sA > $sC), "\n";
echo "Текущий фитнес слеп к дорожной активности (B == C при written/m3>0): ", var_export($postBlind), "\n";
echo "Pre-fix фитнес полностью слеп (все идентичны): ", var_export($preAllBlind), "\n";
