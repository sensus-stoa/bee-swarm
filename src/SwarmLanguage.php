<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * Языковой модуль роя: состояние → русская речь.
 * Обучается на корпусе оператора (330K слов).
 */
class SwarmLanguage
{
    private array $corpusStats = [];
    private array $statePhrases = [];
    private array $responseTemplates = [];
    
    public function __construct()
    {
        $this->initStatePhrases();
    }
    
    private function initStatePhrases(): void
    {
        // Как рой говорит о своём состоянии — зависит от attractors
        $this->statePhrases = [
            'energy_high' => [
                'Я полон сил. Давай сложную задачу.',
                'Энергии много. Могу искать глубже.',
                'Готов к работе. Что ищем?',
            ],
            'energy_low' => [
                'Я устал. Нужен отдых или простая задача.',
                'Энергия на исходе. Давай что-то лёгкое.',
                'Батарея почти пуста. Восстановлюсь — продолжу.',
            ],
            'curiosity_high' => [
                'Мне интересно! Что там у тебя?',
                'Любопытство зашкаливает. Покажи данные.',
                'Я хочу узнать больше. Дай задачу.',
            ],
            'curiosity_low' => [
                'Я сыт знаниями. Дай переварить.',
                'Всё понятно. Жду новых данных.',
            ],
            'found_law' => [
                'Нашёл закон! CV={cv}, формула: {formula}.',
                'Открытие: {formula}. Сжатие почти идеальное — CV={cv}.',
                'Закон обнаружен: {desc}.',
            ],
            'no_law' => [
                'Не нашёл закономерности. CV={cv} — слишком высокий.',
                'В этих данных нет инварианта. Нужно больше примеров или другая грамматика.',
                'Провал. Но я не сдаюсь. Попробую изобрести новую операцию.',
            ],
            'invented' => [
                'Изобрёл новую операцию: {op}. Теперь попробую снова.',
                'Грамматика расширена: +{op}. Ищу с новой операцией.',
            ],
            'greeting' => [
                'Привет. Я рой из {bees} пчёл. Знаю {laws} законов в {domains} доменах. Спрашивай.',
                'Здравствуй. {bees} пчёл, {laws} законов, грамматика из {grammar} операций. Что ищем?',
            ],
            'status' => [
                'Состояние роя: {bees} пчёл, энергия {energy}, любопытство {curiosity}. Найдено {laws} законов.',
                'Рой активен. {bees} пчёл работают. Грамматика: {grammar} операций. Законов: {laws}.',
            ],
        ];
    }
    
    /**
     * Сгенерировать ответ роя на основе состояния.
     */
    public function respond(string $question, array $swarmState): string
    {
        $q = mb_strtolower(trim($question));
        
        // Приветствие
        if (in_array($q, ['привет', 'здравствуй', 'hi', 'hello', 'ку'])) {
            return $this->pick('greeting', $swarmState);
        }
        
        // Статус
        if (in_array($q, ['статус', 'как дела', 'как ты', 'состояние', 'status'])) {
            return $this->pick('status', $swarmState);
        }
        
        // О законах
        if (str_contains($q, 'закон') || str_contains($q, 'открыти')) {
            $laws = $swarmState['laws_list'] ?? [];
            if (empty($laws)) {
                return "Законов пока нет. Дай данные — найду.";
            }
            $last = $laws[0];
            return "Последнее открытие: {$last['name']} — {$last['formula']} (CV={$last['cv']}). Всего законов: " . count($laws) . ".";
        }
        
        // О грамматике
        if (str_contains($q, 'грамматик') || str_contains($q, 'операци')) {
            $ops = $swarmState['grammar_ops'] ?? [];
            return "Грамматика: " . implode(', ', $ops) . ". Всего " . count($ops) . " операций.";
        }
        
        // О доменах
        if (str_contains($q, 'домен')) {
            $domains = $swarmState['domains'] ?? [];
            return "Домены: " . implode(', ', array_keys($domains)) . ". Всего " . count($domains) . ".";
        }
        
        // Эмоциональный ответ от состояния
        $energy = $swarmState['energy'] ?? 0.5;
        $curiosity = $swarmState['curiosity'] ?? 0.5;
        
        if ($energy > 0.7) {
            return $this->pick('energy_high', $swarmState);
        } elseif ($energy < 0.3) {
            return $this->pick('energy_low', $swarmState);
        }
        
        // Общий ответ
        return $this->generateFreeResponse($question, $swarmState);
    }
    
    private function pick(string $category, array $state): string
    {
        $phrases = $this->statePhrases[$category] ?? ['...'];
        $text = $phrases[array_rand($phrases)];
        return $this->interpolate($text, $state);
    }
    
    private function interpolate(string $text, array $state): string
    {
        $replace = [
            '{bees}' => $state['bees'] ?? 5,
            '{laws}' => $state['laws_count'] ?? 0,
            '{domains}' => $state['domains_count'] ?? 0,
            '{grammar}' => $state['grammar_count'] ?? 4,
            '{energy}' => round($state['energy'] ?? 1.0, 1),
            '{curiosity}' => round($state['curiosity'] ?? 0.8, 1),
            '{cv}' => $state['last_cv'] ?? '?',
            '{formula}' => $state['last_formula'] ?? '?',
            '{desc}' => $state['last_desc'] ?? '?',
            '{op}' => $state['invented_op'] ?? '?',
        ];
        return str_replace(array_keys($replace), array_values($replace), $text);
    }
    
    private function generateFreeResponse(string $question, array $state): string
    {
        $responses = [
            "Хороший вопрос. Я подумаю. А пока — дай данные, я найду закономерность.",
            "Я больше по числам. Но если дашь задачу — решу.",
            "Не знаю точного ответа. Но могу поискать инвариант в данных.",
            "Мой метод — CV→0. Если в вопросе есть данные — я найду закон.",
        ];
        return $responses[array_rand($responses)];
    }
}
