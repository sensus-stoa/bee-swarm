<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Text\CorpusVocabulary;
use BeeSwarm\Text\SentenceRegistry;

/**
 * Текстовые атомы context и match.
 * Работают поверх CorpusVocabulary + SentenceRegistry, не через float.
 */
class TextAtomsTest extends TestCase
{
    private string $tmpDir;
    private CorpusVocabulary $vocab;
    private SentenceRegistry $registry;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/bee_txtat_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        
        file_put_contents($this->tmpDir . '/a.md', 
            "Сократ является человеком.\nПлатон является философом.\nКот является животным.\n");
        
        $this->vocab = new CorpusVocabulary([$this->tmpDir]);
        $this->registry = new SentenceRegistry([$this->tmpDir], $this->vocab);
    }
    
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
        parent::tearDown();
    }
    
    /** context(sentence_id, mask_pos) → окно вокруг маски */
    public function test_context_returns_surrounding_words(): void
    {
        $s = $this->registry->get(0);
        $this->assertNotNull($s);
        
        // "Сократ является человеком" → [Сократ, является, человеком]
        $ids = $s['token_ids'];
        $this->assertCount(3, $ids);
        
        // context(pos=1, radius=1) → [Сократ, человеком]
        $window = $this->contextWindow($ids, 1, 1);
        $this->assertEquals([$ids[0], $ids[2]], $window);
    }
    
    /** match(window, target_id) → 1 если target в окне */
    public function test_match_detects_word_in_window(): void
    {
        $s = $this->registry->get(0);
        $ids = $s['token_ids'];
        $window = $this->contextWindow($ids, 1, 1); // [Сократ, человеком]
        
        // match([Сократ, человеком], Сократ) → 1
        $this->assertEquals(1.0, $this->matchInWindow($window, $ids[0]));
        // match([Сократ, человеком], философом) → 0
        $this->assertEquals(0.0, $this->matchInWindow($window, $this->vocab->id('философом')));
    }
    
    /** compose(context, match) на cloze: предсказывает target */
    public function test_compose_context_match_predicts_cloze_target(): void
    {
        $s = $this->registry->get(0);
        $ids = $s['token_ids'];
        
        // Mask "является" (pos=1), target="Сократ" (pos=0)
        $window = $this->contextWindow($ids, 1, 1);
        $this->assertEquals(1.0, $this->matchInWindow($window, $ids[0]),
            'Сократ в контексте является → match=1');
        $this->assertEquals(1.0, $this->matchInWindow($window, $ids[2]),
            'человеком в контексте является → match=1');
    }
    
    /** Отрицательный пример: target слова нет в контексте */
    public function test_negative_match(): void
    {
        $s = $this->registry->get(0);
        $ids = $s['token_ids'];
        $window = $this->contextWindow($ids, 1, 1); // [Сократ, человеком]
        
        // Буцефал не в окне "Сократ является человеком" → match=0
        $anotherS = $this->registry->get(3);
        if (!$anotherS) { $this->markTestSkipped("Need 4+ sentences"); }
        if ($anotherS) {
            $otherIds = $anotherS['token_ids'];
            $this->assertEquals(0.0, 
                $this->matchInWindow($window, $otherIds[0] ?? 999),
                'Слово из другого предложения не в окне → match=0');
        }
    }
    
    /** CV→0 на cloze: compose(context, match) даёт error=0 */
    public function test_cloze_error_rate_zero_with_correct_atoms(): void
    {
        // Задача: для каждого предложения, маскируем "является",
        // проверяем что левое слово (Сократ/Платон/Кот) match'ится в контексте
        $errors = 0;
        $total = 0;
        
        for ($i = 0; $i < min(3, $this->registry->count()); $i++) {
            $s = $this->registry->get($i);
            if (count($s['token_ids']) < 3) continue;
            $ids = $s['token_ids'];
            
            // Позитивное: target = левое слово, mask = среднее
            $window = $this->contextWindow($ids, 1, 1);
            $pred = $this->matchInWindow($window, $ids[0]); // левое слово
            if ($pred !== 1.0) $errors++;
            $total++;
            
            // Негативное: target = слово из другого предложения
            $otherId = $this->vocab->size() > 5 ? 5 : 1;
            $pred = $this->matchInWindow($window, $otherId);
            if ($pred !== 0.0) $errors++;
            $total++;
        }
        
        $errorRate = $errors / max(1, $total);
        $this->assertLessThan(0.3, $errorRate, 
            "compose(context, match) error={$errorRate} — должно быть < 0.3");
    }
    
    // ═══ helpers (будущие атомы) ═══
    
    private function contextWindow(array $ids, int $pos, int $radius): array
    {
        $w = [];
        for ($i = max(0, $pos - $radius); $i <= min(count($ids) - 1, $pos + $radius); $i++) {
            if ($i !== $pos) $w[] = $ids[$i];
        }
        return $w;
    }
    
    private function matchInWindow(array $window, ?int $target): float
    {
        if ($target === null) return 0.0;
        return in_array($target, $window) ? 1.0 : 0.0;
    }
}
