<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;

/**
 * EXP-065 B2.2: нишевая квота записи (TRAIL_ADMIT_NICHE=1).
 *
 * Forensics B2 (18.11): тёмные сиды (503–505) не собирают закон
 * x0·x1·x2·x3, потому что доноры без x0-префикса ((x1×x3) и др.) не
 * доходят до дороги — proxy-walk писца обрывает petal'ы (OTHER-кап ≤2,
 * низкий proxy). Пилот (positive control): одна подсаженная ступень
 * дороги с недостающим донором → серт на проходе 1 на ВСЕХ трёх сидах.
 *
 * Контракт нишевой квоты: petal-кандидат, чья пара колонок не покрыта
 * дорогой, продвигается в голову ranked (высший proxy среди непокрытых).
 * Капы/ghost/material-фильтры не тронуты. Дорога начинает нести все
 * пары колонок независимо от данных.
 */
final class TrailNicheQuotaTest extends TestCase
{
    private function runCampaign(int $seed, bool $niche): array
    {
        Database::reset();
        putenv('SWARM_DB_PATH=:memory:');
        putenv('NO_BIRTH=1');
        putenv('TRAIL_BUDGET_MODE=steps');
        putenv('TRAIL_BUDGET_STEPS=1500');
        putenv('TRAIL_READ_K=5');
        putenv('TRAIL_WRITE_DIVERSITY=1');
        putenv('TRAIL_READ_DIVERSITY=1');
        putenv('SEARCH_ROTATION_SEED=' . $seed);
        putenv('TRAIL_ADMIT_NICHE=' . ($niche ? '1' : '0'));
        putenv('SEARCH_BEAM_K=10');
        $s = $seed + 555;
        $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
        $X = [];
        for ($i = 0; $i < 200; $i++) {
            $r = [];
            for ($c = 0; $c < 6; $c++) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; $r[] = $s / 0x7FFFFFFF * 4 - 2; }
            $X[] = $r;
        }
        $y = array_map(fn ($r) => $r[0] * $r[1] * $r[2] * $r[3], $X);
        $labels = array_map(fn (int $i): string => "x{$i}", range(0, 5));
        $allWritten = [];
        $certs = 0; $certPass = null; $certForm = null;
        for ($pass = 1; $pass <= 8; $pass++) {
            $genome = ['id' => 'nq_' . $pass, 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'],
                'budget_fraction' => 0.30, 'max_steps' => 5,
                'actions' => [
                    ['op' => 'USE_MARKS', 'k' => 5],
                    ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                    ['op' => 'USE_MARKS', 'k' => 5],
                    ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                ]];
            $res = RouteExecutor::run($genome, $X, $y, $labels, 0.001, 0.10, [], 1);
            foreach ($res['records'] as $rec) {
                if (($rec['op'] ?? '') === 'FLIGHT') {
                    foreach (($rec['trail']['written'] ?? []) as $w) { $allWritten[] = $w; }
                }
            }
            if (! empty($res['found'])) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $noEval = null;
                $v2 = cert_v2($fx, $X, $y, $seed + 600, $noEval);
                if (is_array($v2) && $v2['pass']) {
                    $certs = 1; $certPass = $pass; $certForm = is_string($fx) ? $fx : (string) $res['cert_formula'];
                    break;
                }
            }
        }
        return ['certs' => $certs, 'cert_pass' => $certPass, 'cert_form' => $certForm, 'written' => $allWritten];
    }

    /**
     * ПРЕДСКАЗАНИЕ RED (до фикса): certs=0, донор (x1×x3) не written —
     * дословно как в B1.1-леджере (503/A1: certs=0, 8 проходов).
     */
    public function testNicheQuotaCertifiesDarkSeed503(): void
    {
        require_once __DIR__ . '/../scripts/exp045/cert_utils.php';
        $r = $this->runCampaign(503, true);
        $donorWritten = in_array('(x1×x3)', $r['written'], true);
        $this->assertSame(1, $r['certs'], 'нишевая квота: тёмный сид 503 должен сертифицироваться');
        $this->assertSame(1, (int) $donorWritten, 'недостающий донор (x1×x3) обязан попасть в дорогу');
    }

    public function testNicheDeterminismAcrossAmbient(): void
    {
        putenv('SEARCH_BEAM_K=10');
        mt_srand(123);
        $a = $this->runCampaign(503, true);
        Database::reset();
        mt_srand(77777);
        $b = $this->runCampaign(503, true);
        $this->assertSame($a['cert_pass'], $b['cert_pass'], 'ambient mt не должен влиять на исход при нишевой квоте');
        $this->assertSame($a['cert_form'], $b['cert_form']);
    }
}
