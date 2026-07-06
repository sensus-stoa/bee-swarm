<?php

declare(strict_types=1);

namespace BeeSwarm\Text;

class CorpusVocabulary
{
    private const MAX_WORDS = 5000;
    private const MAX_FILES = 200;
    private const MIN_WORD_LEN = 3;

    private array $wordToId = [];
    private array $idToWord = [];
    private int $nextId = 1;

    public function __construct(array $dirs)
    {
        foreach ($dirs as $dir) {
            $this->scanDir($dir);
            if ($this->nextId > self::MAX_WORDS) {
                break;
            }
        }
    }

    private function scanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $fileCount = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            if ($fileCount >= self::MAX_FILES) {
                break;
            }
            $fileCount++;
            if ($this->nextId > self::MAX_WORDS) {
                break;
            }
            $content = @file_get_contents($file->getPathname());
            if (!$content) {
                continue;
            }
            $this->indexContent($content);
        }
    }

    private function indexContent(string $text): void
    {
        $tokens = $this->tokenizeText($text);
        foreach ($tokens as $token) {
            if ($this->nextId > self::MAX_WORDS) {
                break;
            }
            $key = mb_strtolower($token);
            if (preg_match('/\d/u', $key)) {
                continue;        // нет цифр
            }
            if (mb_strlen($key) < self::MIN_WORD_LEN) {
                continue; // мин 3 символа
            }
            if (!isset($this->wordToId[$key])) {
                $this->wordToId[$key] = $this->nextId;
                $this->idToWord[$this->nextId] = $token;
                $this->nextId++;
            }
        }
    }

    private function tokenizeText(string $text): array
    {
        $text = preg_replace('/[#*_`\[\]()|>\\-]/u', ' ', $text);
        $raw = preg_split('/[\s,.;:!?«»"\'—–]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($raw, fn($w) => mb_strlen($w) >= self::MIN_WORD_LEN));
    }

    public function tokenize(string $sentence): array
    {
        $tokens = $this->tokenizeText($sentence);
        return array_map(fn($t) => $this->wordToId[mb_strtolower($t)] ?? 0, $tokens);
    }

    public function id(string $word): ?int
    {
        return $this->wordToId[mb_strtolower($word)] ?? null;
    }

    public function word(int $id): ?string
    {
        return $this->idToWord[$id] ?? null;
    }

    public function size(): int
    {
        return count($this->wordToId);
    }
}
