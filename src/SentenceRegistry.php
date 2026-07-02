<?php
declare(strict_types=1);

namespace BeeSwarm;

/**
 * SentenceRegistry: хранит токенизированные предложения из корпуса.
 * Каждое предложение получает ID для быстрого доступа.
 */
class SentenceRegistry
{
    private array $sentences = [];
    
    /**
     * @param string[] $dirs Директории для сканирования
     */
    public function __construct(array $dirs, CorpusVocabulary $vocab)
    {
        foreach ($dirs as $dir) {
            $this->scanDir($dir, $vocab);
        }
    }
    
    private function scanDir(string $dir, CorpusVocabulary $vocab): void
    {
        if (!is_dir($dir)) return;
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            if (count($this->sentences) >= 1000) break; // лимит предложений
            if ($file->getExtension() !== 'md') continue;
            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;
            
            // Разбиваем текст на предложения
            $raw = preg_split('/[.!?]+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($raw as $sentence) {
                $sentence = trim($sentence);
                if (mb_strlen($sentence) < 10 || mb_strlen($sentence) > 500) continue; // слишком короткие
                
                $ids = $vocab->tokenize($sentence);
                if (count($ids) >= 3) { // минимум 3 слова
                    $this->sentences[] = [
                        'text' => $sentence,
                        'token_ids' => $ids,
                        'file' => $file->getPathname(),
                    ];
                }
            }
        }
    }
    
    public function count(): int
    {
        return count($this->sentences);
    }
    
    public function get(int $id): ?array
    {
        return $this->sentences[$id] ?? null;
    }
    
    /** Возвращает все предложения (для итерации) */
    public function all(): array
    {
        return $this->sentences;
    }
}
