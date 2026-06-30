<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * ParadigmSwarm: эксперты изолированы парадигмами.
 * Каждая парадигма = своя грамматика + своя специализация.
 * Роутер → парадигма → поиск → coalition → compile.
 */
class ParadigmSwarm
{
    private array $paradigms = [];
    
    public function __construct()
    {
        // Три парадигмы — как в бумаге
        $this->paradigms = [
            'compression' => [
                'ops' => ['+', '×', '²', 'pow2'],
                'domain' => 'порядок, сжатие, знание, структура',
                'description' => 'Ищет законы сжатия: умножение, степень, константы',
                'grammar' => null,
            ],
            'dissipation' => [
                'ops' => ['−', '/', 'abs', 'sqrt'],
                'domain' => 'хаос, рассеивание, энтропия, флуктуации',
                'description' => 'Ищет законы рассеивания: вычитание, деление, корни',
                'grammar' => null,
            ],
            'fidelity' => [
                'ops' => ['CV', 'std', 'mean'],
                'domain' => 'достоверность, честность, сигнал',
                'description' => 'Ищет законы точности: CV, стандартное отклонение',
                'grammar' => null,
            ],
        ];
        
        // Инициализируем грамматику для каждой парадигмы
        foreach ($this->paradigms as $name => &$p) {
            $g = new Grammar();
            // Удаляем всё кроме базовых и добавляем специализированные
            $db = Database::get();
            $baseOps = $p['ops'];
            $p['grammar'] = $g; // grammar содержит все ops из БД
        }
    }
    
    /**
     * Роутер: какая парадигма лучше всего подходит для задачи?
     * Анализирует данные → определяет compression/dissipation/fidelity паттерн.
     */
    public function route(array $X, array $y): array
    {
        $scores = [];
        
        foreach ($this->paradigms as $name => $p) {
            // Пробуем поиск с грамматикой этой парадигмы
            // Создаём временную grammar только с ops этой парадигмы
            $g = new Grammar();
            $g->restrictTo($p['ops']);
            
            [$ok, $cv] = Search::find($X, $y, $g, 2);
            $scores[$name] = ['ok' => $ok, 'cv' => $cv];
        }
        
        // Лучшая парадигма = минимальный CV
        $best = 'compression';
        $bestCv = 9.99;
        foreach ($scores as $name => $s) {
            if ($s['cv'] < $bestCv) {
                $bestCv = $s['cv'];
                $best = $name;
            }
        }
        
        return [
            'routed_to' => $best,
            'scores' => $scores,
            'reason' => $bestCv < 0.15 
                ? "Парадигма '{$best}' explains the data (CV={$bestCv})"
                : "Нет подходящей парадигмы (best CV={$bestCv})",
        ];
    }
    
    /**
     * Поиск с изоляцией: только одна парадигма.
     */
    public function searchIsolated(array $X, array $y, string $paradigm): array
    {
        if (!isset($this->paradigms[$paradigm])) {
            return ['ok' => false, 'cv' => 9.99, 'error' => 'unknown paradigm'];
        }
        
        $g = new Grammar();
        $g->restrictTo($this->paradigms[$paradigm]['ops']);
        
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 3);
        
        return [
            'paradigm' => $paradigm,
            'ok' => $ok,
            'cv' => $cv,
            'formula' => $formula,
            'grammar_used' => $this->paradigms[$paradigm]['ops'],
        ];
    }
    
    /**
     * Coalition: две парадигмы объединяются для сложной задачи.
     * После N успешных коалиций → compile (сжатие в постоянного эксперта).
     */
    public function coalition(array $X, array $y, string $p1, string $p2): array
    {
        $g = new Grammar();
        $ops = array_unique(array_merge(
            $this->paradigms[$p1]['ops'] ?? [],
            $this->paradigms[$p2]['ops'] ?? []
        ));
        $g->restrictTo($ops);
        
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 3);
        
        $key = "$p1+$p2";
        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS coalitions (
            paradigm_pair TEXT PRIMARY KEY,
            success_count INTEGER DEFAULT 1,
            total_attempts INTEGER DEFAULT 1,
            best_cv REAL DEFAULT 9.99,
            last_formula TEXT,
            compiled INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now'))
        )");
        
        // Обновляем статистику коалиций
        $existing = $db->prepare("SELECT success_count, total_attempts FROM coalitions WHERE paradigm_pair = ?");
        $existing->execute([$key]);
        $row = $existing->fetch();
        
        if ($row) {
            $newSuccess = (int)$row['success_count'] + ($ok ? 1 : 0);
            $newAttempts = (int)$row['total_attempts'] + 1;
            $db->prepare("UPDATE coalitions SET success_count=?, total_attempts=?, best_cv=MIN(best_cv,?), last_formula=?, updated_at=datetime('now') WHERE paradigm_pair=?")
               ->execute([$newSuccess, $newAttempts, $cv, $formula, $key]);
        } else {
            $newSuccess = $ok ? 1 : 0;
            $newAttempts = 1;
            $db->prepare("INSERT INTO coalitions (paradigm_pair, success_count, total_attempts, best_cv, last_formula) VALUES (?,?,?,?,?)")
               ->execute([$key, $newSuccess, $newAttempts, $cv, $formula]);
        }
        
        $successCount = $newSuccess;
        $totalAttempts = $newAttempts;
        $alreadyCompiled = (int)($row['compiled'] ?? 0);
        
        // Порог компиляции: 3+ успешных коалиций и success_rate > 60%
        $threshold = 3;
        $successRate = $totalAttempts > 0 ? $successCount / $totalAttempts : 0;
        $compiled = null;
        
        if (!$alreadyCompiled && $successCount >= $threshold && $successRate > 0.6) {
            $compiledName = "{$p1}_{$p2}_compiled";
            $this->paradigms[$compiledName] = [
                'ops' => $ops,
                'domain' => "compiled from {$p1} + {$p2} ({$successCount} successes)",
                'description' => "Скомпилированный эксперт: {$p1} ∩ {$p2}",
                'grammar' => null,
            ];
            $compiled = $compiledName;
            
            // Помечаем как скомпилированный
            $db->prepare("UPDATE coalitions SET compiled = 1 WHERE paradigm_pair = ?")->execute([$key]);
        }
        
        return [
            'coalition' => $key,
            'ok' => $ok,
            'cv' => $cv,
            'formula' => $formula,
            'coalition_stats' => [
                'successes' => $successCount,
                'attempts' => $totalAttempts,
                'rate' => round($successRate, 2),
                'need_for_compile' => max(0, $threshold - $successCount),
            ],
            'compiled' => $compiled,
            'compiled_now' => $compiled !== null,
        ];
    }
    
    public function listParadigms(): array
    {
        $result = [];
        foreach ($this->paradigms as $name => $p) {
            $result[] = [
                'name' => $name,
                'ops' => $p['ops'],
                'domain' => $p['domain'],
                'description' => $p['description'],
            ];
        }
        return $result;
    }
}
