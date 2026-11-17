<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;

/**
 * EXP-065 B1.2 prereg: step-budget mode (TRAIL_BUDGET_MODE=steps) —
 * детерминизм траектории поиска.
 *
 * Root cause B1: wall-clock бюджет полёта (12s) контеншн-зависим — под
 * нагрузкой 8 воркеров бим успевает меньше кандидатов, траектории расходятся
 * (смоук B/502 сертил C@7, полный прогон того же слота — нет). find() уже
 * умеет тиковый бюджет (V0.18-TICK-BUDGET, budgetTicks), но RouteExecutor
 * его не прокидывал.
 *
 * Контракт: TRAIL_BUDGET_MODE=steps → тиковый лимит TRAIL_BUDGET_STEPS
 * на вылета (per-flight), wall-clock decoupled (channelBudgetSec игнорируется,
 * runaway-гвард остаётся ENV_WALLCLOCK_CAP); дефолт (флага нет) = legacy
 * wall-clock байт-в-байт.
 *
 * Детерминизм демонстрируется: (1) два вызова find в одном тесте → identical
 * records (после Database::reset — чистая дорога), (2) demo Д1/Д2 в кампании
 * (trajectory_sha в леджере).
 */
final class RouteExecutorStepBudgetTest extends TestCase
{
    private function fixture(): array
    {
        $s = 7331;
        $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
        $X = [];
        for ($i = 0; $i < 60; $i++) {
            $r = [];
            for ($c = 0; $c < 4; $c++) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; $r[] = $s / 0x7FFFFFFF * 4 - 2; }
            $X[] = $r;
        }
        $y = array_map(fn ($r) => $r[0] * $r[1] + $r[2], $X);
        $labels = array_map(fn (int $i): string => "x{$i}", range(0, 3));
        $genome = ['id' => 't1', 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'],
            'budget_fraction' => 0.30, 'max_steps' => 5,
            'actions' => [
                ['op' => 'USE_MARKS', 'k' => 3],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                ['op' => 'USE_MARKS', 'k' => 3],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ]];
        return [$genome, $X, $y, $labels];
    }

    public function testStepsModeIgnoresWallclockAndCompletes(): void
    {
        putenv('TRAIL_BUDGET_MODE=steps');
        putenv('TRAIL_BUDGET_STEPS=400');
        [$genome, $X, $y, $labels] = $this->fixture();
        // channelBudgetSec = 0.001 (wall-clock убил бы оба вылета: MIN_FLIGHT_SEC)
        $res = RouteExecutor::run($genome, $X, $y, $labels, 0.001, 0.10, [], 1);
        $flights = array_values(array_filter($res['records'], fn (array $r): bool => ($r['op'] ?? '') === 'FLIGHT'));
        $this->assertCount(2, $flights, 'steps-режим: оба вылета выполнены despite wall-clock');
        $this->assertArrayHasKey('cv_exposed', $flights[0]);
    }

    public function testStepsModeDeterministicAcrossCalls(): void
    {
        putenv('TRAIL_BUDGET_MODE=steps');
        putenv('TRAIL_BUDGET_STEPS=400');
        [$genome, $X, $y, $labels] = $this->fixture();
        Database::reset();
        $r1 = RouteExecutor::run($genome, $X, $y, $labels, 0.001, 0.10, [], 1);
        Database::reset(); // чистая дорога между вызовами — то же состояние
        $r2 = RouteExecutor::run($genome, $X, $y, $labels, 0.001, 0.10, [], 1);
        $sig = function (array $r): string {
            $parts = [];
            foreach ($r['records'] as $rec) {
                if (($rec['op'] ?? '') !== 'FLIGHT') { continue; }
                $parts[] = json_encode([
                    'found' => $rec['found'], 'cv' => $rec['cv_exposed'],
                    'written' => $rec['trail']['written'] ?? [],
                    'partial' => $rec['trail']['partial_form'] ?? null,
                    'cv_seen' => $rec['trail']['best_cv_seen'] ?? null,
                ]);
            }
            return sha1(implode('|', $parts));
        };
        $this->assertSame($sig($r1), $sig($r2), 'одинаковый сид+конфиг → идентичная траектория (шаговый бюджет)');
        $this->assertSame($r1['found'], $r2['found']);
    }

    public function testLegacyWallclockPathUnchangedWhenFlagOff(): void
    {
        putenv('TRAIL_BUDGET_MODE');
        putenv('TRAIL_BUDGET_STEPS');
        [$genome, $X, $y, $labels] = $this->fixture();
        // legacy: крошечный wall-clock → stop до вылета (MIN_FLIGHT_SEC гвард)
        $res = RouteExecutor::run($genome, $X, $y, $labels, 0.0001, 0.10, [], 1);
        $flights = array_values(array_filter($res['records'], fn (array $r): bool => ($r['op'] ?? '') === 'FLIGHT'));
        $this->assertCount(0, $flights, 'флаг off = legacy wall-clock (байт-в-байт прежнее поведение)');
    }
}
