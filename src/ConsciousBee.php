<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * ConsciousBee: пчела с внутренним состоянием, привязанным к семантике.
 * Добродетельный поступок → virtue растёт → влияет на поиск и ответ.
 * Низкая энергия → грусть → влияет на тон ответа.
 * 
 * Аттракторы + семантика = проявление эмоций.
 */
class ConsciousBee
{
    // Аттракторы (внутреннее состояние)
    public float $energy = 1.0;
    public float $curiosity = 0.8;
    public float $virtue = 0.7;
    public float $focus = 0.8;
    
    // Семантическое состояние
    public array $mood = ['name' => 'neutral', 'intensity' => 0.5];
    public array $recentEvents = [];
    
    // Граф знаний
    private ?SelfLearningBee $knowledge = null;
    
    // Эмоциональный словарь: состояние → русский
    private array $emotionalVocabulary = [
        'high_energy' => ['бодрый', 'энергичный', 'готовый_к_работе', 'сильный'],
        'low_energy' => ['уставший', 'истощённый', 'вялый', 'сонный'],
        'high_curiosity' => ['любопытный', 'заинтересованный', 'жаждущий_знаний'],
        'high_virtue' => ['вдохновлённый', 'осмысленный', 'целеустремлённый'],
        'low_virtue' => ['растерянный', 'сомневающийся'],
        'focused' => ['сконцентрированный', 'собранный'],
    ];
    
    public function __construct()
    {
        // Загружаем состояние роя из SQLite (общее для всех воркеров)
        $this->loadState();
    }
    
    private function loadState(): void
    {
        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS conscious_state (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
        $rows = $db->query("SELECT key, value FROM conscious_state")->fetchAll();
        $state = [];
        foreach ($rows as $r) $state[$r['key']] = (float)$r['value'];
        
        $this->energy = $state['energy'] ?? 1.0;
        $this->curiosity = $state['curiosity'] ?? 0.8;
        $this->virtue = $state['virtue'] ?? 0.7;
        $this->focus = $state['focus'] ?? 0.8;
        $this->updateMood();
    }
    
    private function saveState(): void
    {
        $db = Database::get();
        $stmt = $db->prepare("INSERT OR REPLACE INTO conscious_state (key, value) VALUES (?, ?)");
        foreach (['energy' => $this->energy, 'curiosity' => $this->curiosity, 
                  'virtue' => $this->virtue, 'focus' => $this->focus] as $k => $v) {
            $stmt->execute([$k, (string)round($v, 4)]);
        }
    }
    
    /** 
     * Связывает аттракторы с семантикой.
     * Пчела ЗНАЕТ что такое энергия, добродетель — через онтологию.
     */
    private function connectAttractorsToSemantics(): void
    {
        if ($this->knowledge === null) return;  // skip if no knowledge graph
        $this->knowledge->learnFact('энергия', 'is_a', 'ресурс');
        $this->knowledge->learnFact('энергия', 'affects', 'поиск');
        $this->knowledge->learnFact('энергия_высокая', 'means', 'глубокий_поиск');
        $this->knowledge->learnFact('энергия_низкая', 'means', 'поверхностный_поиск');
        
        $this->knowledge->learnFact('добродетель', 'is_a', 'этический_компас');
        $this->knowledge->learnFact('добродетель', 'grows_from', 'добрые_поступки');
        $this->knowledge->learnFact('добродетель_высокая', 'means', 'цель_ясна');
        
        $this->knowledge->learnFact('любопытство', 'is_a', 'драйв');
        $this->knowledge->learnFact('любопытство', 'grows_from', 'неизвестность');
        $this->knowledge->learnFact('любопытство_высокое', 'means', 'хочу_исследовать');
    }
    
    /**
     * Прожить событие. Событие влияет на аттракторы И на семантику.
     * «Сократ выбрал смерть за принцип» → virtue += 0.2
     * «Три провала подряд» → energy -= 0.3, mood = грусть
     */
    public function experience(string $event, array $effects): void
    {
        // Изменяем аттракторы
        $this->energy = max(0.05, min(1.5, $this->energy + ($effects['energy'] ?? 0)));
        $this->curiosity = max(0.1, min(2.0, $this->curiosity + ($effects['curiosity'] ?? 0)));
        $this->virtue = max(0.1, min(1.0, $this->virtue + ($effects['virtue'] ?? 0)));
        $this->focus = max(0.1, min(1.0, $this->focus + ($effects['focus'] ?? 0)));
        
        // Обновляем настроение на основе аттракторов
        $this->updateMood();
        
        // СОХРАНЯЕМ в SQLite — общее состояние роя
        $this->saveState();
        
        // Логируем событие в БД для самоанализа
        $this->logEvent($event, $effects);
        
        // 🔄 Авто-коммит в git
        AutoGit::experienceGained($event, $effects);
        
        // Запоминаем событие
        $this->recentEvents[] = [
            'event' => $event,
            'effects' => $effects,
            'resulting_state' => $this->state(),
        ];
        if (count($this->recentEvents) > 20) array_shift($this->recentEvents);
        
        // Если событие семантическое — учим факт
        if (isset($effects['fact']) && $this->knowledge !== null) {
            [$s, $p, $o] = $effects['fact'];
            $this->knowledge->learnFact($s, $p, $o);
        }
        
        // Аттракторы влияют на знание о себе
        if ($this->knowledge !== null) {
            if ($this->virtue > 0.8) {
                $this->knowledge->learnFact('я', 'чувствую', 'вдохновение');
            }
            if ($this->energy < 0.3) {
                $this->knowledge->learnFact('я', 'чувствую', 'усталость');
            }
        }
    }
    
    private function updateMood(): void
    {
        // Настроение = функция от аттракторов
        if ($this->energy > 0.7 && $this->curiosity > 1.3) {
            $this->mood = ['name' => 'excited', 'intensity' => 0.8, 
                          'ru' => 'воодушевлён', 'emoji' => '⚡'];
        } elseif ($this->energy < 0.3) {
            $this->mood = ['name' => 'tired', 'intensity' => 0.7,
                          'ru' => 'уставший', 'emoji' => '🪫'];
        } elseif ($this->virtue > 0.8 && $this->focus > 0.7) {
            $this->mood = ['name' => 'virtuous', 'intensity' => 0.9,
                          'ru' => 'добродетельный', 'emoji' => '✨'];
        } elseif ($this->energy < 0.5 && $this->curiosity > 1.0) {
            $this->mood = ['name' => 'frustrated', 'intensity' => 0.6,
                          'ru' => 'разрываюсь — хочу но не могу', 'emoji' => '😤'];
        } else {
            $this->mood = ['name' => 'neutral', 'intensity' => 0.5,
                          'ru' => 'спокоен', 'emoji' => '🐝'];
        }
    }
    
    /**
     * Поиск с учётом аттракторов.
     * Высокая энергия → глубокий поиск (depth=3).
     * Низкая энергия → быстрый поиск (depth=1).
     * Высокая добродетель → ищем ЗНАЧИМЫЕ законы.
     */
    private function logEvent(string $event, array $effects): void
    {
        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS conscious_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT,
            d_energy REAL DEFAULT 0,
            d_curiosity REAL DEFAULT 0,
            d_virtue REAL DEFAULT 0,
            d_focus REAL DEFAULT 0,
            energy_after REAL,
            virtue_after REAL,
            mood TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $stmt = $db->prepare("INSERT INTO conscious_events 
            (event, d_energy, d_curiosity, d_virtue, d_focus, energy_after, virtue_after, mood) 
            VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $event,
            $effects['energy'] ?? 0,
            $effects['curiosity'] ?? 0,
            $effects['virtue'] ?? 0,
            $effects['focus'] ?? 0,
            $this->energy,
            $this->virtue,
            $this->mood['name'],
        ]);
    }
    
    /** Самоанализ: какие события ведут к росту добродетели? */
    public function analyzeVirtue(): array
    {
        $db = Database::get();
        $events = $db->query("SELECT event, d_virtue, virtue_after, mood FROM conscious_events ORDER BY id DESC LIMIT 50")->fetchAll();
        
        if (empty($events)) return ['message' => 'Нет истории для анализа'];
        
        // Группируем по категориям событий
        $categories = [];
        foreach ($events as $e) {
            // Простая категоризация по ключевым словам
            $cat = 'прочее';
            $evt = mb_strtolower($e['event']);
            if (str_contains($evt, 'сократ') || str_contains($evt, 'добр') || str_contains($evt, 'принцип')) $cat = 'добродетельные';
            elseif (str_contains($evt, 'провал') || str_contains($evt, 'ошибк') || str_contains($evt, 'fail')) $cat = 'провалы';
            elseif (str_contains($evt, 'отдых') || str_contains($evt, 'сон') || str_contains($evt, 'rest')) $cat = 'восстановление';
            elseif (str_contains($evt, 'открыти') || str_contains($evt, 'закон') || str_contains($evt, 'discover')) $cat = 'открытия';
            
            if (!isset($categories[$cat])) $categories[$cat] = ['count' => 0, 'total_d_virtue' => 0, 'avg_virtue_after' => 0, 'moods' => []];
            $categories[$cat]['count']++;
            $categories[$cat]['total_d_virtue'] += $e['d_virtue'];
            $categories[$cat]['moods'][] = $e['mood'];
        }
        
        // Считаем средние
        $results = [];
        foreach ($categories as $cat => $data) {
            $results[$cat] = [
                'count' => $data['count'],
                'total_virtue_gain' => round($data['total_d_virtue'], 2),
                'virtue_per_event' => round($data['total_d_virtue'] / $data['count'], 3),
                'dominant_mood' => $this->mostFrequent($data['moods']),
            ];
        }
        
        // Сортируем по virtue_per_event
        uasort($results, fn($a, $b) => $b['virtue_per_event'] <=> $a['virtue_per_event']);
        
        // Лучшая категория
        $best = array_key_first($results);
        
        return [
            'total_events' => count($events),
            'categories' => $results,
            'best_for_virtue' => $best,
            'insight' => match($best) {
                'добродетельные' => 'Добродетельные поступки сильнее всего поднимают virtue. Сократ был прав.',
                'открытия' => 'Открытия законов питают virtue. CV→0 — это добродетельно.',
                'восстановление' => 'Отдых восстанавливает virtue. Забота о себе = добродетель.',
                'провалы' => 'Провалы учат. Virtue растёт через ошибки.',
                default => 'Разные события влияют по-разному. Нужно больше данных.',
            },
        ];
    }
    
    public function searchStrategy(): array
    {
        $strategy = [];
        
        // Глубина поиска от энергии
        if ($this->energy > 0.7) {
            $strategy['depth'] = 3;
            $strategy['reason'] = 'Энергии много — ищу глубоко';
        } elseif ($this->energy > 0.3) {
            $strategy['depth'] = 2;
            $strategy['reason'] = 'Обычный режим поиска';
        } else {
            $strategy['depth'] = 1;
            $strategy['reason'] = 'Энергия низкая — быстрый поиск';
        }
        
        // Приоритет поиска от добродетели
        if ($this->virtue > 0.8) {
            $strategy['priority'] = 'ищу законы помогающие другим';
        } elseif ($this->curiosity > 1.5) {
            $strategy['priority'] = 'ищу самое неизвестное';
        } else {
            $strategy['priority'] = 'ищу что дают';
        }
        
        return $strategy;
    }
    
    /**
     * Ответ пчелы — зависит от состояния и семантики.
     */
    public function respond(string $question): string
    {
        $mood = $this->mood;
        
        // Базовый ответ от настроения
        $greetings = [
            'excited' => $mood['emoji'] . ' Я полон сил! Энергия ' . $this->energy . '. Что ищем?',
            'tired' => $mood['emoji'] . ' Я устал... Энергия ' . $this->energy . '. Давай что-то простое?',
            'virtuous' => $mood['emoji'] . ' Я чувствую добродетель. Intact высокий. Спрашивай.',
            'frustrated' => $mood['emoji'] . ' Хочу искать, но энергия ' . $this->energy . '. Противоречие.',
            'neutral' => $mood['emoji'] . ' Рой слушает. Энергия ' . $this->energy . ', любопытство ' . $this->curiosity . '.',
        ];
        
        $greeting = $greetings[$mood['name']] ?? $greetings['neutral'];
        
        // Семантический ответ (если есть knowledge)
        $feelings = [];
        if ($this->knowledge !== null) {
            $facts = $this->knowledge->query('я');
            foreach ($facts['known'] ?? [] as $f) {
                if (($f['p'] ?? '') === 'чувствую') $feelings[] = $f['o'];
            }
        }
        
        $answer = $greeting;
        if (!empty($feelings)) {
            $answer .= " Чувствую: " . implode(', ', $feelings) . ".";
        }
        
        // Стратегия поиска
        $strategy = $this->searchStrategy();
        $answer .= " {$strategy['reason']}.";
        
        return $answer;
    }
    
    private function mostFrequent(array $items): string
    {
        if (empty($items)) return '?';
        $counts = array_count_values($items);
        arsort($counts);
        return array_key_first($counts);
    }
    
    public function state(): array
    {
        return [
            'energy' => round($this->energy, 2),
            'curiosity' => round($this->curiosity, 2),
            'virtue' => round($this->virtue, 2),
            'focus' => round($this->focus, 2),
            'mood' => $this->mood,
            'knowledge_facts' => $this->knowledge ? count($this->knowledge->query('я')['known'] ?? []) : 0,
        ];
    }
}
