<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Infra\Database;

/**
 * EXP-045 S2: изоляция — route-local атомы не пишут в grammar_ops,
 * env-ручки восстанавливаются, условие активации семантически верно.
 */
class RouteExecutorIsolationTest extends TestCase
{
    /** @return array{0:list<list<float>>,1:list<float>} */
    private function task(): array
    {
        mt_srand(77);
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
            $y[] = $row[0] * $row[3]; // pureprod — точно выразимо на depth 1
        }

        return [$X, $y];
    }

    private function labels(): array
    {
        return ['c0', 'c1', 'c2', 'c3', 'c4'];
    }

    public function test_local_birth_accepts_two_feature_form(): void
    {
        $born = RouteExecutor::localBirth('(x0×x1)');
        $this->assertNotNull($born);
        $this->assertMatchesRegularExpression('/^RL[0-9a-f]{8}$/', $born['name']);
        $this->assertJson($born['definition']);

        // Атом вычислим как бинарный операнд (a,b)-контракт: apply == прямая формула
        $g = Grammar::fromOps(['+']);
        $this->assertTrue($g->addLocal($born['name'], $born['definition']));
        $this->assertEqualsWithDelta(6.0, $g->apply(3.0, 2.0, $born['name']), 1e-12);
    }

    public function test_local_birth_unary_sq_form(): void
    {
        $born = RouteExecutor::localBirth('((x0+x1)sq)');
        $this->assertNotNull($born, 'sq-суффикс должен парситься');
        $g = Grammar::fromOps(['+']);
        $g->addLocal($born['name'], $born['definition']);
        $this->assertEqualsWithDelta(25.0, $g->apply(3.0, 2.0, $born['name']), 1e-12);
    }

    public function test_local_birth_gates(): void
    {
        // 3 разные фичи — бинарный контракт (a,b) нарушен
        $this->assertNull(RouteExecutor::localBirth('((x0−x1)×x2)'));
        // R/K null-подгонки (урок P8)
        $this->assertNull(RouteExecutor::localBirth('(x0−Rmaxx1)'));
        // обёртка чужого атома запрещена
        $this->assertNull(RouteExecutor::localBirth('((x0−x1)RLabcd1234x2)'));
        // нет операции = бессмысленный атом
        $this->assertNull(RouteExecutor::localBirth('x0'));
        // нет формы — нет рождения
        $this->assertNull(RouteExecutor::localBirth(null));
    }

    public function test_env_hygiene_after_run(): void
    {
        putenv('SEARCH_BEAM_K=33');
        [$X, $y] = $this->task();
        $g = RouteGenome::create('route_hyge0001', '', 0, ['kind' => 'ALWAYS'], [
            ['op' => 'FLIGHT', 'depth' => 1, 'grammar' => 'base', 'beam' => 'beam10'],
            ['op' => 'USE_MARKS', 'k' => 2],
        ], 0.2, 2);
        try {
            $res = RouteExecutor::run($g, $X, $y, $this->labels(), 10.0, 0.10);
            $this->assertTrue($res['activated']);
            $this->assertSame('33', getenv('SEARCH_BEAM_K'), 'ручки восстановлены из снапшота');
            $this->assertFalse(getenv('TRAIL_MARKS'), 'TRAIL_MARKS не переживает маршрут');
        } finally {
            putenv('SEARCH_BEAM_K');
        }
    }

    public function test_grammar_ops_unchanged_by_route_run(): void
    {
        $db = Database::get();
        $before = (int) $db->query('SELECT COUNT(*) FROM grammar_ops')->fetchColumn();
        [$X, $y] = $this->task();
        $g = RouteGenome::create('route_iso00001', '', 0, ['kind' => 'ALWAYS'], [
            ['op' => 'FLIGHT', 'depth' => 1, 'grammar' => 'base', 'beam' => 'default'],
            ['op' => 'LOCAL_BIRTH'],
            ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'default'],
        ], 0.2, 3);
        $res = RouteExecutor::run($g, $X, $y, $this->labels(), 15.0, 0.10);
        $after = (int) $db->query('SELECT COUNT(*) FROM grammar_ops')->fetchColumn();
        $this->assertSame($before, $after, 'S2: маршрут не пишет в grammar_ops');
        foreach (array_keys($res['local_atoms']) as $atomName) {
            $row = $db->query("SELECT COUNT(*) FROM grammar_ops WHERE name = '{$atomName}'")->fetchColumn();
            $this->assertSame(0, (int) $row, 'route-local атом не утёк в глобальный словарь');
        }
    }

    public function test_should_activate_semantics(): void
    {
        $always = RouteGenome::create('route_alw00001', '', 0, ['kind' => 'ALWAYS'],
            [['op' => 'LOCAL_BIRTH']], 0.1, 1);
        $this->assertTrue(RouteExecutor::shouldActivate($always, ['flights_done' => 0, 'certified' => true]));

        $noCert = RouteGenome::create('route_noc00001', '', 0, ['kind' => 'NO_CERT_AFTER', 'flights' => 2],
            [['op' => 'LOCAL_BIRTH']], 0.1, 1);
        $this->assertFalse(RouteExecutor::shouldActivate($noCert, ['flights_done' => 1, 'certified' => false]));
        $this->assertFalse(RouteExecutor::shouldActivate($noCert, ['flights_done' => 3, 'certified' => true]),
            'baseline сертифицировал — маршрут не активируется');
        $this->assertTrue(RouteExecutor::shouldActivate($noCert, ['flights_done' => 2, 'certified' => false]));
    }

    public function test_expand_local_atoms_end_to_end(): void
    {
        $born = RouteExecutor::localBirth('(x0×x1)');
        $this->assertNotNull($born);
        $name = $born['name'];
        // Склеенный формат Search (без пробелов), включая вложенный операнд
        $this->assertSame('(x0×x1)', RouteExecutor::expandLocalAtoms('(x0' . $name . 'x1)', [$name => $born['definition']]));
        $this->assertSame('(((x0−x1)×x2))', RouteExecutor::expandLocalAtoms('(((x0−x1)' . $name . 'x2))', [$name => $born['definition']]));
        // Развёрнутая форма оценима внешним evaluator'ом (блокер-3 закрыт)
        mt_srand(11);
        $X = []; $y = [];
        for ($r = 0; $r < 20; $r++) {
            $a = mt_rand(10, 50) / 10.0;
            $b = mt_rand(10, 50) / 10.0;
            $X[] = [$a, $b];
            $y[] = $a * $b;
        }
        $L = RouteExecutor::computeL(
            RouteExecutor::expandLocalAtoms('(x0' . $name . 'x1)', [$name => $born['definition']]),
            $X,
            $y,
        );
        $this->assertNotNull($L, 'раскрытая формула обязана оцениваться');
        $this->assertLessThan(0.01, $L);
    }

    public function test_inherit_must_be_resolved(): void
    {
        $g = RouteGenome::create('route_inh00001', 'route_parent0', 1, ['kind' => 'INHERIT'],
            [['op' => 'LOCAL_BIRTH']], 0.1, 1);
        [$X, $y] = $this->task();
        $this->expectException(\InvalidArgumentException::class);
        RouteExecutor::run($g, $X, $y, $this->labels(), 5.0, 0.10);
    }
}
