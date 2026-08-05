<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Infra\Database;

/**
 * E1.3-fix: запрет мусорных text-атомов.
 *
 * Живой баг (04.08.2026, ноутбук): улей спамил тысячи мусорных TEXT ATOM —
 * preg_match(Вывод), preg_match(397), preg_match(III), preg_match(дофамин)...
 * Любой label перед двоеточием становился «открытием».
 * Источник: Hive::doTick берёт все /(\w+):/ label'ы, а preg_match-атом
 * использует label как regex без требования захваченных групп.
 */
class TextAtomJunkFilterTest extends TestCase
{
    public function testNumericLabelRejected(): void
    {
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('397'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('2026'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('04'));
    }

    public function testRomanNumeralRejected(): void
    {
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('II'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('III'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('IV'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('X'));
    }

    public function testShortLabelRejected(): void
    {
        // Однобуквенные — мусор; но 2-буквенные метрики (GI, DQ) валидны
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('a'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('и'));
    }

    public function testMixedSymbolLabelRejected(): void
    {
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('x0:'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('label-1'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel(''));
    }

    public function testStopWordsRejected(): void
    {
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('и'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('the'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('and'));
        $this->assertFalse(AtomRegistry::isValidTextAtomLabel('для'));
    }

    public function testNormalMetricLabelAccepted(): void
    {
        $this->assertTrue(AtomRegistry::isValidTextAtomLabel('GI'));
        $this->assertTrue(AtomRegistry::isValidTextAtomLabel('sleep'));
        $this->assertTrue(AtomRegistry::isValidTextAtomLabel('energy'));
        $this->assertTrue(AtomRegistry::isValidTextAtomLabel('дофамин'));
        $this->assertTrue(AtomRegistry::isValidTextAtomLabel('Реальность'));
    }

    /**
     * Ключевая защита: безгрупповой preg_match возвращает вхождения слова,
     * но hasTextAtomData=false — это НЕ открытие. Вхождения остаются
     * доступны Forager/StreamingAccumulator (частотные foraged_txt_* задачи),
     * но Hive не спамит ими grammar_ops и не сбрасывает plateau.
     */
    public function testPregMatchWithoutGroupsHasNoData(): void
    {
        $content = "Вывод: текст без чисел\nВывод: ещё текст";
        $result = AtomRegistry::applyTextAtom('preg_match', $content, 'Вывод');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'Word occurrences are still returned to Forager');
        $this->assertFalse(
            AtomRegistry::hasTextAtomData($result),
            'Empty occurrences must not count as atom data'
        );
    }

    public function testPregMatchWithGroupsHasData(): void
    {
        $content = "GI: 7.2\nDQ: 6\nsleep: 5.5";
        $result = AtomRegistry::applyTextAtom('preg_match', $content, '(\\w+):\\s+([\\d.]+)');
        $this->assertIsArray($result);
        $this->assertCount(3, $result, 'preg_match with capture groups must still work');
        $this->assertTrue(
            AtomRegistry::hasTextAtomData($result),
            'Capture groups are real data'
        );
    }

    public function testMatchLabelHasData(): void
    {
        $result = AtomRegistry::applyTextAtom('match_label', "GI: 7.2\nDQ: 6", 'GI');
        $this->assertTrue(AtomRegistry::hasTextAtomData($result), 'Numeric values are real data');
    }

    /**
     * Защита БД: addDiscoveredTextAtom не должен персистить мусор в grammar_ops.
     */
    public function testAddDiscoveredTextAtomRejectsJunk(): void
    {
        AtomRegistry::addDiscoveredTextAtom('match_label', '397');
        AtomRegistry::addDiscoveredTextAtom('match_label', 'III');
        AtomRegistry::addDiscoveredTextAtom('match_label', 'и');

        $db = Database::get();
        $count = (int) $db->query(
            "SELECT COUNT(*) FROM grammar_ops WHERE name IN ('match_label(397)','match_label(III)','match_label(и)')"
        )->fetchColumn();
        $this->assertSame(0, $count, 'junk atoms must not be persisted to grammar_ops');
    }

    public function testAddDiscoveredTextAtomAcceptsValid(): void
    {
        // Уникальный label: static discoveredAtoms и :memory: БД общие на процесс,
        // другой тест мог оставить match_label(GI) в статике, но не в БД.
        AtomRegistry::addDiscoveredTextAtom('match_label', 'ZZMetric');

        $db = Database::get();
        $count = (int) $db->query(
            "SELECT COUNT(*) FROM grammar_ops WHERE name = 'match_label(ZZMetric)'"
        )->fetchColumn();
        $this->assertSame(1, $count, 'valid atom must be persisted');
    }

    /**
     * E1.3-fix: новизна — атом, уже существующий в grammar_ops,
     * НЕ должен регистрироваться как открытие повторно.
     */
    public function testIsDiscoveredTextAtomReturnsFalseForNew(): void
    {
        $this->assertFalse(
            AtomRegistry::isDiscoveredTextAtom('match_label(NovelMetric)'),
            'Brand-new atom must not be discovered yet'
        );
    }

    public function testIsDiscoveredTextAtomReturnsTrueAfterAdd(): void
    {
        AtomRegistry::addDiscoveredTextAtom('match_label', 'TestMetric');
        $this->assertTrue(
            AtomRegistry::isDiscoveredTextAtom('match_label(TestMetric)'),
            'Atom must be recognised as discovered after add'
        );
    }
}
