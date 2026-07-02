<?php
declare(strict_types=1);

namespace BeeSwarm;

/**
 * Словарь корпуса: слово → целочисленный ID.
 * 
 * Сканирует .md файлы, токенизирует, сопоставляет каждому уникальному слову ID.
 * ID > 0 (0 зарезервирован для UNK).
 */
class CorpusVocabulary
{
    private array $wordToId = [];
    private array $idToWord = [];
    private int $nextId = 1;
    
    /**
     * @param string[] $dirs Директории для сканирования .md файлов
     */
    public function __construct(array $dirs)
    {
        foreach ($dirs as $dir) {
            $this->scanDir($dir);
        }
    }
    
    private function scanDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            if ($file->getExtension() !== 'md') continue;
            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;
            $this->indexContent($content);
        }
    }
    
    private function indexContent(string $text): void
    {
        $tokens = $this->tokenizeText($text);
        foreach ($tokens as $token) {
            $key = mb_strtolower($token);
            if (!isset($this->wordToId[$key])) {
                $this->wordToId[$key] = $this->nextId;
                $this->idToWord[$this->nextId] = $token; // оригинальный регистр
                $this->nextId++;
            }
        }
    }
    
    /**
     * Токенизация: разбивает текст на слова.
     * Упрощённо: split по пробелам + знакам препинания.
     * Оставляем слова длиной >= 2 символа, в нижнем регистре.
     */
    private function tokenizeText(string $text): array
    {
        // Убираем markdown-разметку
        $text = preg_replace('/[#*_`\[\]()|>\\-]/u', ' ', $text);
        // Разбиваем по пробелам и знакам препинания
        $raw = preg_split('/[\s,.;:!?«»"\'—–]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        // Только слова ≥ 2 символов, сохраняем оригинальный регистр
        return array_values(array_filter($raw, fn($w) => mb_strlen($w) >= 2));
    }
    
    /**
     * Токенизирует предложение в массив ID.
     * Неизвестные слова → 0 (UNK).
     */
    public function tokenize(string $sentence): array
    {
        $tokens = $this->tokenizeText($sentence);
        return array_map(fn($t) => $this->wordToId[mb_strtolower($t)] ?? 0, $tokens);
    }
    
    /** ID слова или null */
    public function id(string $word): ?int
    {
        return $this->wordToId[mb_strtolower($word)] ?? null;
    }
    
    /** Слово по ID или null */
    public function word(int $id): ?string
    {
        return $this->idToWord[$id] ?? null;
    }
    
    public function size(): int
    {
        return count($this->wordToId);
    }
}
