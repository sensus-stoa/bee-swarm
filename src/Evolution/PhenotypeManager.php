<?php
declare(strict_types=1);

namespace BeeSwarm\volution;

// ~/.bee_swarm/src/PhenotypeManager.php
// Мутация фенотипа + отбор по fitness + self-law применение

class PhenotypeManager
{
    private string $file;
    private array $defaults = [
        'compose_min_grammar'   => 3,
        'task_regen_interval'   => 100,
        'starvation_timeout'    => 600,
        'forager_max_files'     => 30,
        'self_metrics_interval' => 200,
        'mutation_interval'     => 1000,
    ];

    private array $history = []; // [gen => [param_changed, old, new, fitness]]

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? (getenv('HOME') ? getenv('HOME') . '/.bee_swarm/data/phenotype.json' : sys_get_temp_dir() . '/phenotype.json');
    }

    public function load(): array
    {
        if (file_exists($this->file)) {
            $loaded = json_decode(file_get_contents($this->file), true);
            if (is_array($loaded)) return array_merge($this->defaults, $loaded);
        }
        return $this->defaults;
    }

    public function save(array $p): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($this->file, json_encode($p, JSON_PRETTY_PRINT));
    }

    /** Мутировать один случайный параметр на ×1.5 или ÷1.5 */
    public function mutate(array $p): array
    {
        $keys = array_keys($p);
        $key = $keys[array_rand($keys)];
        $old = $p[$key];
        if (mt_rand(0, 1)) {
            $p[$key] = min(5000, max(1, (int)($old * 1.5)));
        } else {
            $p[$key] = max(1, (int)($old / 1.5));
        }
        return $p;
    }

    /** Применить self-law к фенотипу */
    public function applySelfLaw(array $p, array $selfMetrics): array
    {
        $changes = 0;

        // Self-law 1: grammar растёт логарифмически → увеличить порог compose
        if (isset($selfMetrics['grammar_size_log'])) {
            $logCV = $selfMetrics['grammar_size_log']; // CV логарифмической модели
            $grammarSize = $selfMetrics['grammar_size'] ?? 0;
            if ($logCV < 0.15 && $grammarSize > 15) {
                $p['compose_min_grammar'] = min(50, (int)($p['compose_min_grammar'] * 1.5));
                $changes++;
            }
        }

        // Self-law 2: discoveries падают → уменьшить task_regen_interval (чаще генерить)
        if (isset($selfMetrics['discovery_rate'])) {
            $rate = $selfMetrics['discovery_rate'];
            if ($rate < 0.01) { // меньше 1 открытия на 100 тиков
                $p['task_regen_interval'] = max(10, (int)($p['task_regen_interval'] * 0.7));
                $p['forager_max_files'] = min(100, (int)($p['forager_max_files'] * 1.3));
                $changes++;
            }
        }

        if ($changes > 0) {
            $this->history[] = [
                'gen' => count($this->history) + 1,
                'time' => date('Y-m-d H:i:s'),
                'changes' => $changes,
                'metrics' => $selfMetrics,
            ];
        }

        return $p;
    }

    /** Отбор: оставить мутацию если fitness вырос */
    public function select(array $oldPhenotype, float $oldFitness, array $newPhenotype, float $newFitness): array
    {
        if ($newFitness > $oldFitness) {
            return $newPhenotype;
        }
        return $oldPhenotype;
    }

    /** Измерить fitness фенотипа */
    public function measureFitness(array $p, int $discoveriesLastPeriod, int $starvationsLastPeriod): float
    {
        $regenScore = 1000.0 / max(1, $p['task_regen_interval']);
        $starvePenalty = $starvationsLastPeriod > 0 ? 1.0 / $starvationsLastPeriod : 1.0;
        $composeScore = 10.0 / max(1, $p['compose_min_grammar']);
        $raw = $regenScore * 0.3 + $starvePenalty * 0.4 + $composeScore * 0.3;
        // Бонус за discoveries
        $raw += $discoveriesLastPeriod * 0.1;
        return $raw;
    }

    public function getHistory(): array
    {
        return $this->history;
    }
}
