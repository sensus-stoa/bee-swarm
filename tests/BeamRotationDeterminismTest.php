<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;
use BeeSwarm\Infra\RngIsolation;

/**
 * EXP-065 B1.2: beam-rotation shuffle под RngIsolation-гвардом.
 *
 * Пойманный баг (17.11, кампания B1.1): RouteExecutor-путь недетерминирован
 * между процессами — beam-ротация делает shuffle($rest) на ГЛОБАЛЬНОМ
 * Mt19937 (Search.php:728), который без mt_srand автосеется из энтропии
 * ОС. Доказательство: mt_srand(777) → 3/3 побайтово идентичные
 * траектории (traj_sha), 778 → другой путь; несессионированные прогоны —
 * 4 аттрактора на 6 прогонов. php.net function.shuffle: «uses the global
 * Mt19937 instance ... shares its state with all other functions».
 *
 * Контракт: ambient mt-состояние процесса НЕ должно влиять на траекторию.
 * Сид ротации = SEARCH_ROTATION_SEED (харнес задаёт = task seed).
 *
 * Условия воспроизведения — кампанийные: beam10nl + TRAIL_MARKS (write-on)
 * + writer-флаги; расхождение проявляется в writer-входе (candidates
 * 77 vs 78) и admitted-наборе (written 2 vs 3) первого полёта.
 */
final class BeamRotationDeterminismTest extends TestCase
{
    private function fixture(): array
    {
        $s = 502 + 555;
        $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
        $X = [];
        for ($i = 0; $i < 200; $i++) {
            $r = [];
            for ($c = 0; $c < 6; $c++) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; $r[] = $s / 0x7FFFFFFF * 4 - 2; }
            $X[] = $r;
        }
        $y = array_map(fn ($r) => $r[0] * $r[1] * $r[2] * $r[3], $X);
        $labels = array_map(fn (int $i): string => "x{$i}", range(0, 5));
        $genome = ['id' => 't1', 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'],
            'budget_fraction' => 0.30, 'max_steps' => 5,
            'actions' => [
                ['op' => 'USE_MARKS', 'k' => 5],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                ['op' => 'USE_MARKS', 'k' => 5],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ]];
        return [$genome, $X, $y, $labels];
    }

    private function runCampaign(): array
    {
        putenv('TRAIL_BUDGET_MODE=steps');
        putenv('TRAIL_BUDGET_STEPS=1500');
        putenv('TRAIL_READ_K=5');
        putenv('TRAIL_WRITE_DIVERSITY=1');
        putenv('TRAIL_READ_DIVERSITY=1');
        putenv('SEARCH_ROTATION_SEED=502');
        [$genome, $X, $y, $labels] = $this->fixture();
        $res = RouteExecutor::run($genome, $X, $y, $labels, 0.001, 0.10, [], 1);
        $sig = ['found' => $res['found'], 'cert' => $res['cert_formula']];
        foreach ($res['records'] as $rec) {
            if (($rec['op'] ?? '') !== 'FLIGHT') { continue; }
            $sig[] = [
                'written' => $rec['trail']['written'] ?? [],
                'injected' => $rec['trail']['read_injected'] ?? [],
                'wi' => ($rec['trail']['writer_input'] ?? [])['candidates'] ?? null,
            ];
        }
        return $sig;
    }

    public function testAmbientMtStateDoesNotAffectTrajectory(): void
    {
        // ПРЕДСКАЗАНИЕ RED (до фикса): AssertionError «ambient mt-состояние...» —
        // два вызова с разным ambient mt дают разные writer-входы/пулы
        // (кампания: candidates 77 vs 78, written 2 vs 3 на первом полёте).
        putenv('NO_BIRTH=1');
        mt_srand(111); // ambient-состояние процесса А
        $a = $this->runCampaign();
        Database::reset();
        mt_srand(98765); // ambient-состояние процесса Б
        $b = $this->runCampaign();
        $this->assertSame(json_encode($a), json_encode($b), 'ambient mt-состояние процесса не должно влиять на траекторию');
        RngIsolation::assertClean();
    }
}
