<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\CorpusVocabulary;
use BeeSwarm\SentenceRegistry;
use BeeSwarm\Database;

/**
 * Шаги 2-5: Cloze-генератор + SentenceRegistry + error-rate CV.
 * 
 * Cloze-генератор: берёт предложения из корпуса, маскирует слово.
 * SentenceRegistry: хранит предложения для быстрого доступа по ID.
 * CV = доля ошибок (error rate), не std/mean.
 */
class ClozeGeneratorTest extends TestCase
{
    private string $tmpDir;
    private CorpusVocabulary $vocab;
    private SentenceRegistry $registry;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/bee_cloze_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        
        file_put_contents($this->tmpDir . '/a.md', "Сократ является человеком.\nПлатон является философом.\n");
        file_put_contents($this->tmpDir . '/b.md', "Кот является животным.\nБуцефал является лошадью.\n");
        
        $this->vocab = new CorpusVocabulary([$this->tmpDir]);
        $this->registry = new SentenceRegistry([$this->tmpDir], $this->vocab);
    }
    
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
        parent::tearDown();
    }
    
    /** SentenceRegistry хранит предложения с токенизацией */
    public function test_registry_stores_sentences(): void
    {
        $count = $this->registry->count();
        $this->assertGreaterThanOrEqual(4, $count, 'Минимум 4 предложения');
    }
    
    /** Cloze-генератор создаёт задачи: mask → угадать слово */
    public function test_generates_cloze_tasks(): void
    {
        $tasks = $this->generateClozeTasks();
        
        $this->assertNotEmpty($tasks, 'Cloze-генератор создаёт задачи');
        
        $task = $tasks[0];
        $this->assertArrayHasKey('sentence_id', $task);
        $this->assertArrayHasKey('mask_position', $task);
        $this->assertArrayHasKey('target_word_id', $task);
        $this->assertArrayHasKey('data', $task);  // для CV→0 engine
    }
    
    /** Задача содержит положительные И отрицательные примеры */
    public function test_cloze_tasks_are_falsifiable(): void
    {
        $tasks = $this->generateClozeTasks();
        $task = $tasks[0];
        
        // Положительный пример: target = правильное слово → 1.0
        // Отрицательные примеры: target ≠ правильное → 0.0
        $targets = array_column($task['data'], 3);  // 4-й столбец = expected (1.0/0.0)
        $uniqueTargets = array_unique($targets);
        
        $this->assertContains(1.0, $uniqueTargets, 'Должен быть target=1.0 (правильный ответ)');
        $this->assertContains(0.0, $uniqueTargets, 'Должен быть target=0.0 (неправильный ответ)');
    }
    
    /** CV = error rate: compose(context, match) на cloze-задаче */
    public function test_error_rate_cv(): void
    {
        $tasks = $this->generateClozeTasks();
        $task = $tasks[0]; // берём первую задачу
        
        // Простой "атом": всегда угадывает слово из левого контекста
        $errors = 0;
        $total = count($task['data']);
        
        $sentence = $this->registry->get($task['sentence_id']);
        
        foreach ($task['data'] as $row) {
            [$sId, $maskPos, $targetId, $expected] = $row;
            
            // "Атом": предсказывает слово из позиции maskPos-1 (левое)
            $predictedId = $maskPos > 0 ? $sentence['token_ids'][$maskPos - 1] : 0;
            $prediction = ($predictedId === (int)$targetId) ? 1.0 : 0.0;
            
            if (abs($prediction - $expected) > 0.01) {
                $errors++;
            }
        }
        
        $errorRate = $errors / max(1, $total);
        
        // Для паттерна "X является Y": левое слово от MASK=Y → target=X → совпадение!
        // Но только когда MASK=Y (pos 2), а не когда MASK=X (pos 0)
        $this->assertLessThan(1.0, $errorRate, 
            'Error rate < 1.0 (атом угадывает часть паттерна X-является-Y)');
    }
    
    /** Случайное угадывание → высокий error rate */
    public function test_random_guess_high_error(): void
    {
        $tasks = $this->generateClozeTasks();
        $task = $tasks[0];
        
        // "Атом-пессимист": всегда предсказывает 0.0 (нет совпадения)
        // На задаче где 1 positive + 3 negative → error = 1/4 = 0.25
        $errors = 0;
        $total = count($task['data']);
        
        foreach ($task['data'] as $row) {
            $prediction = 0.0; // всегда "нет"
            if (abs($prediction - $row[3]) > 0.01) {
                $errors++;
            }
        }
        
        $errorRate = $errors / max(1, $total);
        
        // Даже "умный" pessimum даёт ~0.25 ошибки (на 1 pos + 3 neg)
        // Это БАЗОВЫЙ уровень — любой атом должен быть лучше
        $this->assertGreaterThan(0.1, $errorRate, 
            'Даже пессимист ошибается на положительных примерах');
    }
    
    // ═══ helpers ═══
    
    private function generateClozeTasks(): array
    {
        $tasks = [];
        
        for ($i = 0; $i < $this->registry->count(); $i++) {
            $sentence = $this->registry->get($i);
            if (!$sentence) continue;
            
            $ids = $sentence['token_ids'];
            
            foreach ($ids as $pos => $targetId) {
                $word = $this->vocab->word($targetId);
                // Пропускаем короткие служебные слова
                if (in_array($word, ['и', 'в', 'на', 'с', 'не', 'или', 'но', 'а', 'за', 'из', 'от', 'до'])) continue;
                if (mb_strlen($word) < 2) continue;
                
                $data = [];
                // Положительный пример
                $data[] = [$i, $pos, $targetId, 1.0];
                
                // Отрицательные примеры: случайные другие слова
                $negCount = 0;
                $usedTargets = [$targetId => true];
                for ($j = 0; $j < 5 && $negCount < 3; $j++) {
                    $randomId = mt_rand(1, $this->vocab->size());
                    if (isset($usedTargets[$randomId])) continue;
                    $usedTargets[$randomId] = true;
                    $data[] = [$i, $pos, $randomId, 0.0];
                    $negCount++;
                }
                
                $tasks[] = [
                    'sentence_id' => $i,
                    'mask_position' => $pos,
                    'target_word_id' => $targetId,
                    'data' => $data,
                ];
            }
        }
        
        return $tasks;
    }
}
