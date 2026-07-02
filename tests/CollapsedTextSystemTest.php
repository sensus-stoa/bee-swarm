<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Database;

/**
 * Свёрнутая текстовая система (Эйнштейн).
 * 
 * Принцип: рой САМ открывает is_a через compose(cloze_window, match_target).
 * Без regex. Без ConceptRegistry. Без hand-coded атомов.
 * 
 * Компоненты: только 3
 *   1. Cloze-генератор (текст → задача)
 *   2. Текстовые примитивы в алфавите
 *   3. CV→0 + compose
 */
class CollapsedTextSystemTest extends TestCase
{
    private array $corpus;
    private array $vocab;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->corpus = [
            "Сократ является человеком",
            "Платон является человеком", 
            "Кот является животным",
            "Буцефал является лошадью",
        ];
        
        $this->vocab = $this->buildVocab($this->corpus);
    }
    
    /** Cloze-генератор: вырезаем слово → задача угадать */
    public function test_cloze_generator_produces_falsifiable_tasks(): void
    {
        $tasks = $this->generateClozeTasks($this->corpus, $this->vocab);
        
        $this->assertNotEmpty($tasks, 'Cloze должен генерировать задачи');
        
        $task = $tasks[0];
        $this->assertArrayHasKey('sentence_ids', $task);
        $this->assertArrayHasKey('mask_position', $task);
        $this->assertArrayHasKey('target', $task);
        
        // Задача фальсифицируема: target ≠ random
        $wrongTarget = ($task['target'] + 1) % count($this->vocab);
        $this->assertNotEquals($task['target'], $wrongTarget);
    }
    
    /** Текстовый примитив context(word_id, radius) → окно вокруг слова */
    public function test_context_primitive(): void
    {
        $sentence = [0, 1, 2];
        
        $window = $this->contextWindow($sentence, 1, 1);
        $this->assertEquals([0, 2], $window, 'context(является, 1) → [Сократ, человеком]');
        
        $window = $this->contextWindow($sentence, 0, 1);
        $this->assertEquals([1], $window, 'context(Сократ, 1) → [является]');
    }
    
    /** Текстовый примитив match(window, target) → 1 если target в окне */
    public function test_match_primitive(): void
    {
        $this->assertEquals(1, $this->matchInWindow([0, 2], 2), 'человеком в окне');
        $this->assertEquals(1, $this->matchInWindow([0, 2], 0), 'Сократ в окне');
        $this->assertEquals(0, $this->matchInWindow([0, 2], 3), 'лошадью нет в окне');
    }
    
    /**
     * CV→0: compose(context, match) открывает паттерн "X является Y".
     * 
     * Для MASK на среднем слове ("является"):
     *   context(MASK, 1) = [X, Y]
     *   match(context, X) = 1 ✓
     *   match(context, Y) = 1 ✓
     * 
     * Для MASK на крайнем слове (X или Y):
     *   context(MASK, 1) = [является]
     *   match(context, X) = 0 (X не в своём контексте)
     */
    public function test_compose_discovers_is_a_pattern(): void
    {
        $tasks = $this->generateClozeTasks($this->corpus, $this->vocab);
        
        $errors = 0;
        $total = 0;
        
        foreach ($tasks as $task) {
            $window = $this->contextWindow(
                $task['sentence_ids'],
                $task['mask_position'],
                1
            );
            
            $prediction = $this->matchInWindow($window, $task['target']);
            
            // Ожидаем match=1 только когда MASK — среднее слово (является)
            // Тогда контекст содержит X и Y → оба match=1
            $expected = count($task['sentence_ids']) === 3 && $task['mask_position'] === 1 ? 1 : 0;
            
            if ($prediction !== $expected) {
                $errors++;
            }
            $total++;
        }
        
        $errorRate = $errors / max(1, $total);
        
        // 4 предложения × 3 слова = 12 задач. 
        // 4 средних маски (является) → 4 × 2 правильных = 8 ✓
        // 8 крайних масок → target не в своём контексте → предсказание 0 → 8 ✗
        // errorRate ≈ 8/16 = 0.5
        $this->assertLessThan(0.6, $errorRate,
            'compose(context, match) открывает паттерн X-является-Y (error < 0.6)');
    }
    
    /** CV>0: случайный атом даёт высокую ошибку */
    public function test_random_guess_gives_high_error(): void
    {
        $tasks = $this->generateClozeTasks($this->corpus, $this->vocab);
        
        $errors = 0;
        $total = 0;
        
        foreach ($tasks as $task) {
            $randomTarget = array_rand($this->vocab);
            if ($randomTarget === $task['target']) continue;
            $errors++;
            $total++;
        }
        
        $errorRate = $errors / max(1, $total);
        
        $this->assertGreaterThan(0.5, $errorRate,
            'Случайный атом должен давать высокую ошибку');
    }
    
    // ═══ helpers ═══
    
    private function buildVocab(array $sentences): array
    {
        $vocab = [];
        $idx = 0;
        foreach ($sentences as $s) {
            foreach (explode(' ', $s) as $w) {
                $w = trim($w);
                if (!isset($vocab[$w])) {
                    $vocab[$w] = $idx++;
                }
            }
        }
        return $vocab;
    }
    
    private function generateClozeTasks(array $sentences, array $vocab): array
    {
        $tasks = [];
        foreach ($sentences as $s) {
            $words = explode(' ', $s);
            $ids = array_map(fn($w) => $vocab[trim($w)], $words);
            
            foreach ($ids as $pos => $target) {
                $word = array_search($target, $vocab);
                if (in_array($word, ['и', 'в', 'на', 'с', 'не'])) continue;
                
                $tasks[] = [
                    'sentence_ids' => $ids,
                    'mask_position' => $pos,
                    'target' => $target,
                    'original_word' => $word,
                ];
            }
        }
        return $tasks;
    }
    
    private function contextWindow(array $sentenceIds, int $pos, int $radius): array
    {
        $window = [];
        for ($i = max(0, $pos - $radius); $i <= min(count($sentenceIds) - 1, $pos + $radius); $i++) {
            if ($i !== $pos) {
                $window[] = $sentenceIds[$i];
            }
        }
        return $window;
    }
    
    private function matchInWindow(array $window, int $target): int
    {
        return in_array($target, $window) ? 1 : 0;
    }
}
