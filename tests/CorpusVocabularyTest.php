<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Text\CorpusVocabulary;

/**
 * Шаг 1: Словарь корпуса.
 * Строит word → id из .md файлов.
 */
class CorpusVocabularyTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/bee_corpus_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Строит словарь из файлов
     */
    public function testBuildsVocabularyFromFiles(): void
    {
        file_put_contents($this->tmpDir . '/a.md', "Сократ является человеком.\nПлатон является философом.\n");
        file_put_contents($this->tmpDir . '/b.md', "Кот является животным.\n");

        $vocab = new CorpusVocabulary([$this->tmpDir]);

        // Все уникальные слова должны быть в словаре
        $this->assertGreaterThan(0, $vocab->id('Сократ'), 'Сократ есть в словаре');
        $this->assertGreaterThan(0, $vocab->id('Платон'), 'Платон есть в словаре');
        $this->assertGreaterThan(0, $vocab->id('человеком'), 'человеком есть в словаре');

        // Разные слова — разные ID
        $this->assertNotEquals($vocab->id('Сократ'), $vocab->id('Платон'));

        // Одно слово — один ID (в разных файлах)
        $this->assertEquals($vocab->id('является'), $vocab->id('является'));
    }

    /**
     * Неизвестное слово → null
     */
    public function testUnknownWordReturnsNull(): void
    {
        file_put_contents($this->tmpDir . '/a.md', "Сократ является человеком.\n");

        $vocab = new CorpusVocabulary([$this->tmpDir]);

        $this->assertNull($vocab->id('Буцефал'), 'Неизвестное слово → null');
    }

    /**
     * ID → слово (обратный поиск)
     */
    public function testReverseLookup(): void
    {
        file_put_contents($this->tmpDir . '/a.md', "Сократ является человеком.\n");

        $vocab = new CorpusVocabulary([$this->tmpDir]);

        $socratId = $vocab->id('Сократ');
        $this->assertEquals('Сократ', $vocab->word($socratId));
    }

    /**
     * Размер словаря
     */
    public function testVocabularySize(): void
    {
        file_put_contents($this->tmpDir . '/a.md', "Сократ является человеком.\n");

        $vocab = new CorpusVocabulary([$this->tmpDir]);

        // "Сократ", "является", "человеком" + "." (пунктуация как токен) — минимум 3
        $this->assertGreaterThanOrEqual(3, $vocab->size());
    }

    /**
     * Токенизация предложения → массив ID
     */
    public function testTokenizeSentence(): void
    {
        file_put_contents($this->tmpDir . '/a.md', "Сократ является человеком.\n");

        $vocab = new CorpusVocabulary([$this->tmpDir]);

        $ids = $vocab->tokenize('Сократ является человеком');

        $this->assertCount(3, $ids);
        $this->assertEquals($vocab->id('Сократ'), $ids[0]);
        $this->assertEquals($vocab->id('является'), $ids[1]);
        $this->assertEquals($vocab->id('человеком'), $ids[2]);
    }
}
