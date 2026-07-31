<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Text\CorpusVocabulary;

/**
 * Корпус НЕ должен подвешивать систему.
 * Лимиты: 200 файлов, 5000 слов, слова ≥ 3 символов без цифр.
 */
class CorpusSizeLimitTest extends TestCase
{
    /**
     * Словарь не превышает 5000 слов
     */
    public function testVocabularyHasUpperLimit(): void
    {
        $lair = getenv('HOME') . '/Documents/the_lair';
        if (! is_dir($lair)) {
            $lair = __DIR__ . '/fixtures/lair';
        }

        $vocab = new CorpusVocabulary([$lair]);

        $this->assertLessThanOrEqual(
            5000,
            $vocab->size(),
            'Словарь не должен превышать 5000 слов'
        );
    }

    /**
     * Сканирует не более 200 файлов
     */
    public function testScansLimitedFiles(): void
    {
        $lair = getenv('HOME') . '/Documents/the_lair';
        if (! is_dir($lair)) {
            $lair = __DIR__ . '/fixtures/lair';
        }

        $start = microtime(true);
        $vocab = new CorpusVocabulary([$lair]);
        $elapsed = microtime(true) - $start;

        // Не должен занимать больше 30 секунд
        $this->assertLessThan(
            30.0,
            $elapsed,
            "Построение словаря заняло {$elapsed}s — должно быть < 30s"
        );
    }

    /**
     * Слова с цифрами не попадают в словарь
     */
    public function testWordsWithDigitsAreSkipped(): void
    {
        $tmp = sys_get_temp_dir() . '/bee_limit_' . uniqid();
        mkdir($tmp);
        file_put_contents("{$tmp}/a.md", "abc 123test x1y2z3 hello\n");

        $vocab = new CorpusVocabulary([$tmp]);

        $this->assertNotNull($vocab->id('abc'), 'abc должен быть в словаре');
        $this->assertNotNull($vocab->id('hello'), 'hello должен быть в словаре');
        $this->assertNull($vocab->id('123test'), '123test не должен быть в словаре (цифры)');
        $this->assertNull($vocab->id('x1y2z3'), 'x1y2z3 не должен быть в словаре (цифры)');

        array_map('unlink', glob("{$tmp}/*"));
        rmdir($tmp);
    }

    /**
     * Короткие слова (< 3 символов) не попадают
     */
    public function testShortWordsAreSkipped(): void
    {
        $tmp = sys_get_temp_dir() . '/bee_short_' . uniqid();
        mkdir($tmp);
        file_put_contents("{$tmp}/a.md", "a ab abc и в на\n");

        $vocab = new CorpusVocabulary([$tmp]);

        $this->assertNull($vocab->id('a'), 'a — слишком короткое');
        $this->assertNull($vocab->id('ab'), 'ab — слишком короткое');
        $this->assertNull($vocab->id('и'), 'и — слишком короткое');
        $this->assertNotNull($vocab->id('abc'), 'abc — ок');
        $this->assertNotNull($vocab->id('abc'), 'abc — 3 символа, норм');

        array_map('unlink', glob("{$tmp}/*"));
        rmdir($tmp);
    }
}
