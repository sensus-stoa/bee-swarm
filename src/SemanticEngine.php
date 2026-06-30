<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * Семантический движок v2: Онтология + парсер + русский рендеринг.
 * Вопрос → отношения → CV→0 поиск → русский ответ.
 */
class SemanticEngine
{
    private Ontology $ontology;
    
    public function __construct()
    {
        $this->ontology = new Ontology();
    }
    
    /**
     * Парсит русский вопрос в отношения.
     * «сколько у тебя законов» → [{s: 'рой', p: 'count', o: 'закон'}]
     */
    public function parse(string $question): array
    {
        $q = mb_strtolower(trim($question));
        $words = preg_split('/\s+/', $q);
        $resolved = array_map([$this->ontology, 'resolve'], $words);
        
        $relations = [];
        
        // Паттерн: [question_word] ... [concept] ?
        // «сколько законов» → count + закон
        // «как дела» → state + состояние
        // «что такое CV» → definition + CV
        // «что ты умеешь» → describe + пчела + умеет
        
        $qWord = null;
        $concept = null;
        $action = null;
        
        foreach ($resolved as $i => $rw) {
            if (in_array($rw, ['count', 'state', 'definition', 'describe', 'reason', 'identity'])) {
                $qWord = $rw;
            } elseif (isset($this->ontology->concepts[$rw])) {
                if ($concept === null) $concept = $rw;
            } elseif (in_array($rw, ['умеет', 'ищет', 'находит', 'знает', 'работает', 'понимает'])) {
                $action = $rw;
            }
        }
        
        // Также ищем концепты по исходным словам (до резолва)
        if ($concept === null) {
            foreach ($words as $w) {
                $cw = $this->ontology->resolve($w);
                if (isset($this->ontology->concepts[$cw])) {
                    $concept = $cw;
                    break;
                }
            }
        }
        
        // Построение отношений
        if ($qWord === 'state' || ($qWord === null && $concept === 'состояние')) {
            $relations[] = ['s' => 'рой', 'p' => 'state', 'o' => '?'];
        }
        
        if ($qWord === 'count' && $concept) {
            $relations[] = ['s' => 'рой', 'p' => 'count', 'o' => $concept];
        }
        
        if ($qWord === 'definition' && $concept) {
            $relations[] = ['s' => $concept, 'p' => 'definition', 'o' => '?'];
        }
        
        if ($qWord === 'describe' || $qWord === 'definition' || ($qWord === null && $action === 'умеет')) {
            $subj = $concept ?? 'пчела';
            $relations[] = ['s' => $subj, 'p' => 'can', 'o' => '?'];
        }
        
        if ($concept && $action && !$qWord) {
            $relations[] = ['s' => $concept, 'p' => $action, 'o' => '?'];
        }
        
        // Если ничего не нашли — пробуем прямой поиск по ключевым словам
        if (empty($relations)) {
            foreach ($words as $w) {
                $cw = $this->ontology->resolve($w);
                if (isset($this->ontology->concepts[$cw])) {
                    $relations[] = ['s' => $cw, 'p' => 'describe', 'o' => '?'];
                    break;
                }
            }
        }
        
        return $relations;
    }
    
    /**
     * CV→0 поиск ответа: находит отношения из онтологии, ближайшие к вопросу.
     */
    public function search(array $questionRels, array $swarmState): array
    {
        $candidates = [];
        
        foreach ($questionRels as $q) {
            $qS = $q['s'];
            $qP = $q['p'];
            
            // Специальные обработчики для запросов к состоянию роя
            if ($qP === 'state') {
                $e = $swarmState['energy'];
                $c = $swarmState['curiosity'];
                if ($e > 0.6) {
                    $candidates[] = ['cv' => 0.0, 'ru' => "Энергия высокая ({$e}), любопытство {$c}. Готов работать.", 'rel' => $q];
                } else {
                    $candidates[] = ['cv' => 0.0, 'ru' => "Энергия низкая ({$e}). Нужен отдых или простая задача.", 'rel' => $q];
                }
                continue;
            }
            
            if ($qP === 'count') {
                $o = $q['o'];
                $count = $this->getCount($o, $swarmState);
                if ($count !== null) {
                    $candidates[] = ['cv' => 0.0, 'ru' => $count, 'rel' => $q];
                }
                continue;
            }
            
            if ($qP === 'definition' || $qP === 'describe') {
                $info = $this->getConceptInfo($qS);
                if ($info) {
                    $candidates[] = ['cv' => 0.0, 'ru' => $info, 'rel' => $q];
                }
                continue;
            }
            
            if ($qP === 'can') {
                $info = $this->getConceptInfo($qS);
                $canDo = $this->ontology->concepts[$qS]['can'] ?? null;
                if ($canDo) {
                    $candidates[] = ['cv' => 0.0, 'ru' => "Я умею: " . implode(', ', $canDo), 'rel' => $q];
                }
                continue;
            }
            
            // Поиск по онтологии
            foreach ($this->ontology->relations as $kr) {
                $matchScore = 0.0;
                if ($kr['s'] === $qS || $this->ontology->resolve($kr['s']) === $qS) $matchScore += 0.5;
                if ($kr['p'] === $qP || $this->areRelated($kr['p'], $qP)) $matchScore += 0.5;
                
                if ($matchScore > 0) {
                    $candidates[] = [
                        'cv' => 1.0 - $matchScore,
                        'ru' => str_replace('{o}', $kr['o'], $kr['ru'] ?? "{$kr['s']} {$kr['p']} {$kr['o']}"),
                        'rel' => $q,
                    ];
                }
            }
        }
        
        usort($candidates, fn($a, $b) => $a['cv'] <=> $b['cv']);
        return $candidates;
    }
    
    /**
     * Генерирует финальный русский ответ.
     */
    public function respond(array $candidates, array $swarmState): string
    {
        if (empty($candidates)) {
            return $this->fallback($swarmState);
        }
        
        $best = $candidates[0];
        
        if ($best['cv'] < 0.1) {
            return $best['ru'];
        }
        
        // CV>0 — добавляем контекст
        $answer = $best['ru'] . " (CV={$best['cv']})";
        if (count($candidates) > 1 && $candidates[1]['cv'] < 0.5) {
            $answer .= ". Также: " . $candidates[1]['ru'];
        }
        return $answer;
    }
    
    private function getCount(string $concept, array $state): ?string
    {
        $map = [
            'пчела' => "{$state['bees']} пчелы",
            'рой' => "{$state['bees']} пчелы",
            'закон' => $this->plural($state['laws_count'], 'закон', 'закона', 'законов'),
            'домен' => $this->plural($state['domains_count'], 'домен', 'домена', 'доменов'),
            'грамматика' => "{$state['grammar_count']} операций",
        ];
        return $map[$concept] ?? null;
    }
    
    private function getConceptInfo(string $name): ?string
    {
        $c = $this->ontology->concepts[$name] ?? null;
        if (!$c) return null;
        
        $def = $c['definition'] ?? '';
        $extra = '';
        
        if (isset($c['examples'])) {
            $extra = ' Примеры: ' . implode(', ', array_slice($c['examples'], 0, 3));
        }
        if (isset($c['formula'])) {
            $extra = ' Формула: ' . $c['formula'];
        }
        if (isset($c['range'])) {
            $extra .= ' Диапазон: ' . $c['range'];
        }
        
        return "{$name} — {$def}.{$extra}";
    }
    
    private function fallback(array $state): string
    {
        $e = $state['energy'] ?? 1.0;
        if ($e > 0.6) {
            $opts = [
                'Я не совсем понял вопрос. Но я знаю ' . $state['laws_count'] . ' законов в ' . $state['domains_count'] . ' доменах. Спроси про законы, грамматику или CV.',
                'Не уверен что понял. Могу рассказать про свою работу: ищу инварианты через CV→0. Или дай задачу — решу.',
            ];
        } else {
            $opts = [
                'Прости, энергия низкая. Давай простой вопрос или задачу.',
                'Я немного устал. Спроси что-то конкретное — про законы, грамматику или CV.',
            ];
        }
        return $opts[array_rand($opts)];
    }
    
    private function plural(int $n, string $one, string $two, string $five): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) return "{$n} {$five}";
        $n %= 10;
        if ($n == 1) return "1 {$one}";
        if ($n >= 2 && $n <= 4) return "2 {$two}";
        return "{$n} {$five}";
    }
    
    private function areRelated(string $p1, string $p2): bool
    {
        $groups = [
            ['state', 'состояние', 'как'],
            ['count', 'сколько', 'число'],
            ['describe', 'какой', 'опиши', 'расскажи'],
            ['definition', 'что', 'определение'],
            ['can', 'умеет', 'может', 'делает'],
            ['ищет', 'searches', 'находит'],
        ];
        foreach ($groups as $g) {
            if (in_array($p1, $g) && in_array($p2, $g)) return true;
        }
        return false;
    }
}
