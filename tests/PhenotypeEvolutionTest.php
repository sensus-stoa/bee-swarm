<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

class PhenotypeEvolutionTest extends TestCase
{
    private string $tmpPhenotypeFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPhenotypeFile = sys_get_temp_dir() . '/test_phenotype_' . getmypid() . '.json';
        @unlink($this->tmpPhenotypeFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpPhenotypeFile);
    }

    // ═══ 1. ФЕНОТИП: ЗАГРУЗКА/СОХРАНЕНИЕ ═══

    /**
     * Фенотип загружается из файла
     */
    public function testPhenotypeLoadDefaults(): void
    {
        $p = $this->loadPhenotype();
        $this->assertArrayHasKey('compose_min_grammar', $p);
        $this->assertArrayHasKey('task_regen_interval', $p);
        $this->assertArrayHasKey('starvation_timeout', $p);
        $this->assertEquals(3, $p['compose_min_grammar']);
        $this->assertEquals(100, $p['task_regen_interval']);
    }

    /**
     * Фенотип сохраняется и загружается
     */
    public function testPhenotypeSaveAndLoad(): void
    {
        $p = $this->loadPhenotype();
        $p['compose_min_grammar'] = 7;
        $this->savePhenotype($p);

        $loaded = $this->loadPhenotype();
        $this->assertEquals(7, $loaded['compose_min_grammar']);
    }

    // ═══ 2. МУТАЦИЯ ═══

    /**
     * Мутация меняет один параметр на ±30%
     */
    public function testMutateChangesOneParameter(): void
    {
        $p = $this->loadPhenotype();
        $original = $p;
        $mutated = $this->mutate($p);

        // Ровно один параметр изменился
        $changes = 0;
        foreach ($original as $key => $val) {
            if ($mutated[$key] !== $val) {
                $changes++;
            }
        }
        $this->assertEquals(1, $changes, 'Exactly one parameter should change');
    }

    /**
     * Мутация не выходит за границы
     */
    public function testMutateStaysInBounds(): void
    {
        $p = $this->loadPhenotype();
        for ($i = 0; $i < 50; $i++) {
            $p = $this->mutate($p);
        }
        $this->assertGreaterThanOrEqual(1, $p['compose_min_grammar']);
        $this->assertLessThanOrEqual(5000, $p['task_regen_interval']);
    }

    // ═══ 3. SELF-LAW → PHENOTYPE ═══

    /**
     * Self-law: grammar растёт логарифмически → увеличить compose_min_grammar
     */
    public function testSelfLawAdjustsPhenotype(): void
    {
        $p = $this->loadPhenotype();

        // Логарифмический рост: grammar от 5 до 25 за 500 тиков (rate < 0.05)
        $grammarSizes = [];
        for ($i = 0; $i < 30; $i++) {
            $grammarSizes[] = 5.0 + 20.0 * log(1 + $i) / log(30);
        }
        $ticks = range(1, count($grammarSizes));

        // Применяем self-law напрямую (log обнаруживается через discover или статистику)
        $p = $this->applySelfLaw($p, $grammarSizes, $ticks);

        // log обнаружил логарифмический рост → увеличить порог compose
        $this->assertGreaterThan(
            3,
            $p['compose_min_grammar'],
            'Self-law should increase compose threshold when grammar grows logarithmically'
        );
    }

    // ═══ 4. FITNESS + ОТБОР ═══

    /**
     * Мутация с ростом fitness закрепляется
     */
    public function testSelectionKeepsBeneficialMutation(): void
    {
        $p = $this->loadPhenotype();
        $oldFitness = $this->measureFitness($p);

        $mutated = $this->mutate($p);
        $newFitness = $this->measureFitness($mutated);

        $result = $this->select($p, $oldFitness, $mutated, $newFitness);

        if ($newFitness > $oldFitness) {
            $this->assertEquals($mutated, $result, 'Beneficial mutation should be kept');
        } else {
            $this->assertEquals($p, $result, 'Harmful mutation should be reverted');
        }
    }

    // ═══ HELPERS (будущие методы PhenotypeManager) ═══

    private function loadPhenotype(): array
    {
        if (file_exists($this->tmpPhenotypeFile)) {
            return json_decode(file_get_contents($this->tmpPhenotypeFile), true);
        }
        return [
            'compose_min_grammar' => 3,
            'task_regen_interval' => 100,
            'starvation_timeout' => 600,
            'forager_max_files' => 30,
            'self_metrics_interval' => 200,
            'mutation_interval' => 1000,
        ];
    }

    private function savePhenotype(array $p): void
    {
        file_put_contents($this->tmpPhenotypeFile, json_encode($p, JSON_PRETTY_PRINT));
    }

    private function mutate(array $p): array
    {
        $keys = array_keys($p);
        $key = $keys[array_rand($keys)];
        $old = $p[$key];
        // Мутация: +1 или ×1.5/÷1.5 — гарантирует изменение
        if (mt_rand(0, 1)) {
            $p[$key] = min(5000, max(1, (int) ($old * 1.5)));
        } else {
            $p[$key] = max(1, (int) ($old / 1.5));
        }
        return $p;
    }

    private function measureFitness(array $p): float
    {
        // Фитнес = баланс между discoveries и resource usage
        // Высокий regen + высокий compose = больше discoveries, но больше CPU
        // Штраф за слишком частый regen (CPU) и слишком редкий (starvation)
        $regenScore = 1000.0 / max(1, $p['task_regen_interval']);
        $starveScore = $p['starvation_timeout'] / 600.0;
        $composeScore = 10.0 / max(1, $p['compose_min_grammar']);
        return $regenScore * 0.4 + $starveScore * 0.3 + $composeScore * 0.3;
    }

    private function select(array $old, float $oldFit, array $new, float $newFit): array
    {
        return $newFit > $oldFit ? $new : $old;
    }

    private function applySelfLaw(array $p, array $grammarSizes, array $ticks): array
    {
        $lastSize = (float) end($grammarSizes);
        $firstSize = (float) reset($grammarSizes);
        $growthRate = count($ticks) > 1 ? ($lastSize - $firstSize) / count($ticks) : 0;

        if ($growthRate < 1.0 && $lastSize > 20) {
            // Логарифмический рост: грамматика большая, рост медленный
            // → увеличить порог (меньше compose, грамматика уже насыщена)
            $p['compose_min_grammar'] = (int) ($p['compose_min_grammar'] * 1.5);
        }

        return $p;
    }
}
