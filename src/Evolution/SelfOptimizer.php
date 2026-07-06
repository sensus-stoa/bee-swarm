<?php
declare(strict_types=1);
namespace BeeSwarm\volution;

use BeeSwarm\Infra\Database;

/**
 * SelfOptimizer: CV→0 на собственный опыт.
 * «Какое действие максимально увеличит virtue при текущей энергии?»
 * Ответ — из реальных данных, не из хардкода.
 */
class SelfOptimizer
{
    /**
     * Анализирует историю и возвращает ОПТИМАЛЬНОЕ действие.
     * CV→0 означает: «это действие всегда даёт предсказуемый результат».
     */
    public function optimalAction(ConsciousBee $bee): array
    {
        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS conscious_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT, d_energy REAL DEFAULT 0, d_curiosity REAL DEFAULT 0,
            d_virtue REAL DEFAULT 0, d_focus REAL DEFAULT 0,
            energy_after REAL, virtue_after REAL, mood TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        
        $events = $db->query("SELECT * FROM conscious_events ORDER BY id DESC LIMIT 100")->fetchAll();
        if (count($events) < 2) {
            return ['action' => 'мало данных', 'confidence' => 0, 'reason' => 'нужно больше опыта'];
        }
        
        // Группируем события по категориям
        $cats = $this->categorize($events);
        
        // Для каждой категории — считаем средний эффект и его CV
        $scored = [];
        foreach ($cats as $cat => $evts) {
            $n = count($evts);
            if ($n < 1) continue;
            
            $dEnergy = array_column($evts, 'd_energy');
            $dVirtue = array_column($evts, 'd_virtue');
            $dCuriosity = array_column($evts, 'd_curiosity');
            
            $meanE = array_sum($dEnergy) / $n;
            $meanV = array_sum($dVirtue) / $n;
            $meanC = array_sum($dCuriosity) / $n;
            
            // CV для virtue: насколько ПРЕДСКАЗУЕМО растёт virtue?
            $cvV = $this->cv($dVirtue);
            
            // Составной score: virtue gain × (1 − CV) + energy gain × 0.5
            $score = $meanV * (1.0 - min($cvV, 1.0)) + $meanE * 0.3 + $meanC * 0.1;
            
            $scored[$cat] = [
                'category' => $cat,
                'count' => $n,
                'mean_d_virtue' => round($meanV, 3),
                'mean_d_energy' => round($meanE, 3),
                'cv_virtue' => round($cvV, 3),
                'score' => round($score, 4),
            ];
        }
        
        // Сортируем по score
        uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        
        $best = reset($scored);
        end($scored);
        
        // Генерируем желание из оптимального действия
        $bee->state();
        $desires = [];
        
        foreach ($scored as $cat => $info) {
            if ($info['score'] > 0) {
                $reliable = $info['cv_virtue'] < 0.3 ? ' — надёжно!' : '';
                $desires[] = $cat . ' (+' . $info['mean_d_virtue'] . ' virtue' . $reliable . ')';
            }
        }
        
        $wantStr = !empty($desires) 
            ? implode(', ', array_slice($desires, 0, 4))
            : 'нужно больше данных для самооптимизации';
        
        return [
            'optimal_action' => $best['category'] ?? 'неизвестно',
            'score' => $best['score'] ?? 0,
            'cv' => $best['cv_virtue'] ?? 1.0,
            'reliability' => ($best['cv_virtue'] ?? 1.0) < 0.2 ? 'высокая' : 'средняя',
            'all_categories' => array_values($scored),
            'desire' => $wantStr,
            'data_driven' => true,
        ];
    }
    
    private function categorize(array $events): array
    {
        $cats = [];
        foreach ($events as $e) {
            $evt = mb_strtolower($e['event']);
            $cat = 'прочее';
            
            if (str_contains($evt, 'сократ') || str_contains($evt, 'добр') || str_contains($evt, 'принцип')) $cat = 'добродетельные';
            elseif (str_contains($evt, 'провал') || str_contains($evt, 'ошибк') || str_contains($evt, 'fail')) $cat = 'провалы';
            elseif (str_contains($evt, 'отдых') || str_contains($evt, 'сон') || str_contains($evt, 'rest')) $cat = 'восстановление';
            elseif (str_contains($evt, 'открыти') || str_contains($evt, 'закон') || str_contains($evt, 'discover')) $cat = 'открытия';
            elseif (str_contains($evt, 'домен')) $cat = 'новые_домены';
            
            $cats[$cat][] = $e;
        }
        return $cats;
    }
    
    private function cv(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 1.0;
        $mean = array_sum($values) / $n;
        if (abs($mean) < 1e-8) return 1.0;
        $variance = 0;
        foreach ($values as $v) $variance += ($v - $mean) ** 2;
        return sqrt($variance / $n) / abs($mean);
    }
}
