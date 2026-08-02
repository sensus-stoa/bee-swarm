<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Text\CorpusVocabulary;

/**
 * Story E1-FIX Phase 1: Corpus loader.
 *
 * Корень проблемы: CorpusVocabulary сканирует ~/Documents/the_lair (0 .md),
 * а Obsidian vault (~/obsidian/, 1910 .md) не сканируется.
 */
class CorpusVocabularyTest extends TestCase
{
    /**
     * CorpusVocabulary должен загружать .md файлы из указанных директорий.
     *
     * Predicted: FAIL — не находит файлы, так как tests/fixtures/corpus не существует.
     */
    public function testCorpusLoadsMarkdownFiles(): void
    {
        $fixtureDir = __DIR__ . '/fixtures/corpus';
        if (! is_dir($fixtureDir)) {
            mkdir($fixtureDir, 0755, true);
        }

        // Создать тестовый .md файл
        $mdPath = $fixtureDir . '/test.md';
        file_put_contents($mdPath, "# Тестовый документ\n\nЭто предложение содержит слова для токенизации.\nВторое предложение с другими словами.\n");

        $vocab = new CorpusVocabulary([$fixtureDir]);

        // Должен найти слова из .md файла
        $this->assertGreaterThan(0, $vocab->size(), 'Corpus must contain words from .md files');
        $this->assertNotNull($vocab->id('тестовый'), 'Must find word "тестовый"');
        $this->assertNotNull($vocab->id('предложение'), 'Must find word "предложение"');

        // Очистить
        unlink($mdPath);
        rmdir($fixtureDir);
    }

    /**
     * Пустая директория → размер 0.
     */
    public function testEmptyDirectoryGivesZeroSize(): void
    {
        $emptyDir = sys_get_temp_dir() . '/empty_corpus_' . uniqid();
        mkdir($emptyDir);

        $vocab = new CorpusVocabulary([$emptyDir]);
        $this->assertSame(0, $vocab->size(), 'Empty directory must give 0 words');

        rmdir($emptyDir);
    }

    /**
     * Несуществующая директория → не падает.
     */
    public function testNonExistentDirectoryDoesNotCrash(): void
    {
        $vocab = new CorpusVocabulary(['/tmp/nonexistent_dir_' . uniqid()]);
        $this->assertSame(0, $vocab->size());
    }
}
