<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;

/**
 * EXP-045 S3: бюджетный канал маршрута жёсткий, unspent учитывается,
 * базовый поиск при выключенном канале не меняется.
 */
class RouteBudgetTest extends TestCase
{
    /** @return array{0:list<list<float>>,1:list<float>} */
    private function easyTask(): array
    {
        mt_srand(42);
        $X = [];
        $y = [];
        for ($r = 0; $r < 24; $r++) {
            $row = [
                mt_rand(10, 50) / 10.0,
                mt_rand(10, 50) / 10.0,
                mt_rand(10, 50) / 10.0,
                mt_rand(10, 50) / 10.0,
                mt_rand(10, 50) / 10.0,
            ];
            $X[] = $row;
            $y[] = $row[0] * $row[3];
        }

        return [$X, $y];
    }

    public function test_channel_cap_respected(): void
    {
        [$X, $y] = $this->easyTask();
        $g = RouteGenome::create('route_budg0001', '', 0, ['kind' => 'ALWAYS'], [
            ['op' => 'FLIGHT', 'depth' => 3, 'grammar' => 'base', 'beam' => 'beam40'],
            ['op' => 'FLIGHT', 'depth' => 3, 'grammar' => 'diffprod', 'beam' => 'beam10'],
            ['op' => 'FLIGHT', 'depth' => 3, 'grammar' => 'ratio', 'beam' => 'default'],
            ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'narrow5'],
        ], 0.2, 4);
        $channel = 3.0;
        $t0 = microtime(true);
        $res = RouteExecutor::run($g, $X, $y, null, $channel, 0.10);
        $wall = microtime(true) - $t0;
        // КАП МЯГКИЙ на грануле фазы: Search::find проверяет deadline между
        // фазами перебора — одиночный вылет (beam40, depth3) перелетает канал
        // до ~1.5s (урок EXP-044: budgetSec-флайты wall-чувствительны).
        // Жёсткий кап потребовал бы proc-изоляции — вне минимального скоупа
        // EXP-045; перелёт фиксируется как метрика (route_wall_share в отчёте).
        $this->assertLessThanOrEqual($channel * 1.6 + 0.5, $wall, 'мягкий кап канала (гранула фазы)');
        $this->assertGreaterThanOrEqual(0.0, $res['unspent']);
        $this->assertTrue($res['stop'] === 'budget_exhausted' || $res['stop'] === 'actions_done');
    }

    public function test_easy_task_found_and_unspent_positive(): void
    {
        [$X, $y] = $this->easyTask();
        $g = RouteGenome::create('route_budg0002', '', 0, ['kind' => 'ALWAYS'], [
            ['op' => 'FLIGHT', 'depth' => 1, 'grammar' => 'base', 'beam' => 'default'],
        ], 0.2, 1);
        $res = RouteExecutor::run($g, $X, $y, ['c0', 'c1', 'c2', 'c3', 'c4'], 15.0, 0.10);
        $this->assertTrue($res['found'], 'pureprod y=c0×c3 обязан найтись на depth 1');
        $this->assertNotNull($res['cert_formula']);
        $this->assertGreaterThan(0.0, $res['unspent'], 'недоиспользованная квота учтена');
        // cert_formula остаётся в label-пространстве движка; независимая оценка
        // (cv_eval драйвера) делает свою конвертацию labels→x (контракт EXP-044)
        $this->assertStringContainsString('c0×c3', (string) $res['cert_formula']);
        // x-пространство — для внутренней L-оценки исполнителя
        $this->assertStringNotContainsString('c0', (string) $res['best_formula_x']);
        $this->assertStringContainsString('x0', (string) $res['best_formula_x']);
        // L на идеальной форме близок к нулю
        $this->assertNotNull($res['best_L_valid']);
        $this->assertLessThan(0.1, $res['best_L_valid']);
    }

    public function test_budget_exhaustion_stops_actions(): void
    {
        [$X, $y] = $this->easyTask();
        $g = RouteGenome::create('route_budg0003', '', 0, ['kind' => 'ALWAYS'], [
            ['op' => 'FLIGHT', 'depth' => 3, 'grammar' => 'base', 'beam' => 'beam40'],
            ['op' => 'FLIGHT', 'depth' => 3, 'grammar' => 'base', 'beam' => 'beam40'],
            ['op' => 'FLIGHT', 'depth' => 3, 'grammar' => 'base', 'beam' => 'beam40'],
        ], 0.2, 3);
        $res = RouteExecutor::run($g, $X, $y, null, 1.05, 0.10);
        $this->assertLessThanOrEqual(3, count($res['records']));
        // Канал почти исчерпан одним вылетом → остальные не стартуют
        if ($res['stop'] === 'budget_exhausted') {
            $this->assertLessThan(3, count($res['records']), 'stop-причина согласована с числом действий');
        }
        $this->assertGreaterThanOrEqual(0.0, $res['unspent']);
    }

    public function test_max_steps_truncates_actions(): void
    {
        [$X, $y] = $this->easyTask();
        $g = RouteGenome::create('route_budg0004', '', 0, ['kind' => 'ALWAYS'], [
            ['op' => 'FLIGHT', 'depth' => 1, 'grammar' => 'base', 'beam' => 'default'],
            ['op' => 'FLIGHT', 'depth' => 1, 'grammar' => 'base', 'beam' => 'default'],
            ['op' => 'FLIGHT', 'depth' => 1, 'grammar' => 'base', 'beam' => 'default'],
        ], 0.2, 2);
        $res = RouteExecutor::run($g, $X, $y, null, 30.0, 0.10);
        $this->assertSame('actions_done', $res['stop']);
        $flights = array_filter($res['records'], fn (array $r): bool => $r['op'] === 'FLIGHT');
        $this->assertCount(2, $flights, 'max_steps усекает список действий');
    }

    public function test_compute_l_sanity(): void
    {
        // L(идеальная форма) << L(константа)
        mt_srand(9);
        $X = [];
        $y = [];
        for ($r = 0; $r < 20; $r++) {
            $a = mt_rand(10, 50) / 10.0;
            $b = mt_rand(10, 50) / 10.0;
            $X[] = [$a, $b];
            $y[] = $a * $b;
        }
        $lPerfect = RouteExecutor::computeL('(x0×x1)', $X, $y);
        $lWrong = RouteExecutor::computeL('(x0+x1)', $X, $y);
        $this->assertNotNull($lPerfect);
        $this->assertLessThan(0.01, $lPerfect);
        $this->assertNotNull($lWrong);
        $this->assertGreaterThan($lPerfect, $lWrong);
    }
}
