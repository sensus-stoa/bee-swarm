<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

class AutonomousDecisionsTest extends TestCase
{
    private string $tmpConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpConfig = sys_get_temp_dir() . '/test_decisions_' . getmypid() . '.json';
        @unlink($this->tmpConfig);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpConfig);
    }

    // ═══ 1. DOMAIN EXPANSION: расширение радиуса поиска ═══

    /** При голоде рой расширяет зону поиска */
    public function test_starvation_expands_search_radius(): void
    {
        $state = $this->freshState();
        $this->assertCount(1, $state['scan_dirs']);

        // Голод 3 раза подряд
        for ($i = 0; $i < 3; $i++) {
            $state = $this->handleStarvation($state, 0); // 0 новых законов
        }

        $this->assertGreaterThan(1, count($state['scan_dirs']), 'Should expand to parent dirs');
        $this->assertArrayHasKey('/home/user/Documents', $state['scan_dirs'], 'Parent added');
    }

    /** Успешная директория остаётся, пустая удаляется */
    public function test_successful_dir_kept_empty_pruned(): void
    {
        $state = $this->freshState();
        $state['scan_dirs']['/tmp/empty_dir'] = 0.3;

        // Пустая директория не дала законов
        $state = $this->handleForageResult($state, '/tmp/empty_dir', 0);
        $this->assertArrayNotHasKey('/tmp/empty_dir', $state['scan_dirs'], 'Empty dir should be pruned');

        // Успешная — остаётся
        $state['scan_dirs']['/tmp/rich_dir'] = 0.5;
        $state = $this->handleForageResult($state, '/tmp/rich_dir', 5);
        $this->assertArrayHasKey('/tmp/rich_dir', $state['scan_dirs'], 'Productive dir kept');
        $this->assertGreaterThan(0.5, $state['scan_dirs']['/tmp/rich_dir'], 'Priority increased');
    }

    // ═══ 2. SELF-DIRECTED GOALS: выбор цели ═══

    /** Рой выбирает цель на основе self-метрик */
    public function test_goal_selection_based_on_metrics(): void
    {
        $state = $this->freshState();

        // Сценарий 1: много starvation → приоритет на поиск доменов
        $metrics = ['starvation_rate' => 5, 'discovery_rate' => 0.1, 'grammar_diversity' => 0.8];
        $goal = $this->selectGoal($metrics);
        $this->assertEquals('explore_domains', $goal, 'High starvation → explore');

        // Сценарий 2: много открытий, grammar однообразна → diversify
        $metrics = ['starvation_rate' => 0.1, 'discovery_rate' => 10, 'grammar_diversity' => 0.2];
        $goal = $this->selectGoal($metrics);
        $this->assertEquals('diversify_grammar', $goal, 'Low diversity → diversify');

        // Сценарий 3: всё в норме → compress (H1-style)
        $metrics = ['starvation_rate' => 0.5, 'discovery_rate' => 2, 'grammar_diversity' => 0.6];
        $goal = $this->selectGoal($metrics);
        $this->assertEquals('compress', $goal, 'Balanced → compress');
    }

    /** Цель сохраняется и восстанавливается */
    public function test_goal_persistence(): void
    {
        $state = $this->freshState();
        $state['current_goal'] = 'explore_domains';

        $this->saveState($state);
        $loaded = $this->loadState();

        $this->assertEquals('explore_domains', $loaded['current_goal']);
    }

    // ═══ 3. ПОЛНЫЙ ЦИКЛ: метрики → цель → действие → результат ═══

    /** Полный цикл автономного решения */
    public function test_full_autonomous_cycle(): void
    {
        $state = $this->freshState();

        // Шаг 1: собираем метрики
        $metrics = ['starvation_rate' => 3.0, 'discovery_rate' => 0.2, 'grammar_diversity' => 0.5];

        // Шаг 2: выбираем цель
        $goal = $this->selectGoal($metrics);
        $this->assertNotEmpty($goal);

        // Шаг 3: выполняем действие (несколько раз для expansion)
        for ($i = 0; $i < 3; $i++) {
            $state = $this->executeGoal($state, $goal);
        }

        // Шаг 4: проверяем результат
        if ($goal === 'explore_domains') {
            $this->assertGreaterThan(1, count($state['scan_dirs']));
        } elseif ($goal === 'compress') {
            $this->assertEquals('compress', $state['last_action']);
        }
    }

    // ═══ HELPERS ═══

    private function freshState(): array
    {
        return [
            'scan_dirs' => ['/home/user/Documents/the_lair' => 0.5],
            'current_goal' => 'discover',
            'starvation_count' => 0,
            'last_action' => null,
        ];
    }

    private function handleStarvation(array $state, int $newLaws): array
    {
        $state['starvation_count']++;
        if ($newLaws === 0 && $state['starvation_count'] >= 2) {
            // Расширяем: добавляем родительскую директорию
            $current = array_keys($state['scan_dirs']);
            $last = end($current);
            $parent = dirname($last);
            if ($parent !== $last && !isset($state['scan_dirs'][$parent])) {
                $state['scan_dirs'][$parent] = 0.3;
            }
            $state['starvation_count'] = 0;
        }
        return $state;
    }

    private function handleForageResult(array $state, string $dir, int $lawsFound): array
    {
        if ($lawsFound > 0) {
            $state['scan_dirs'][$dir] = min(1.0, ($state['scan_dirs'][$dir] ?? 0.3) + 0.1);
        } elseif (($state['scan_dirs'][$dir] ?? 0) < 0.4) {
            unset($state['scan_dirs'][$dir]);
        }
        return $state;
    }

    private function selectGoal(array $metrics): string
    {
        // Простой multi-objective выбор
        if ($metrics['starvation_rate'] > 2.0) return 'explore_domains';
        if ($metrics['grammar_diversity'] < 0.3) return 'diversify_grammar';
        if ($metrics['discovery_rate'] < 0.5) return 'explore_domains';
        return 'compress';
    }

    private function executeGoal(array $state, string $goal): array
    {
        $state['last_action'] = $goal;
        switch ($goal) {
            case 'explore_domains':
                return $this->handleStarvation($state, 0);
            case 'diversify_grammar':
                $state['last_action'] = 'diversify';
                break;
            case 'compress':
                $state['last_action'] = 'compress';
                break;
        }
        return $state;
    }

    private function saveState(array $state): void
    {
        file_put_contents($this->tmpConfig, json_encode($state));
    }

    private function loadState(): array
    {
        if (file_exists($this->tmpConfig)) {
            return json_decode(file_get_contents($this->tmpConfig), true);
        }
        return $this->freshState();
    }
}
