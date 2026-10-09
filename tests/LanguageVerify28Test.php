<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\LanguageVerify28;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * VERIFY-2-8 (§3.8(г) v1.5, нестрогое): формализация языка — измерение.
 *
 * §3.8: система создаёт собственный идентификатор для сжатого закона,
 * и другие пчёлы используют этот идентификатор как атом грамматики
 * в новых доменах. Закон → имя → атом → участие в новых открытиях.
 *
 * Критерии (прогон verify_2_8, фикстура двухфазная — прецедент EXP-039):
 *  (a) ≥1 мета-закон сжат из ≥2 законов РАЗНЫХ доменов (компрессором);
 *  (б) атом присутствует в grammar_ops и доступен поиску (контраст:
 *      без атома домен C не решается, с атомом — решается);
 *  (в) атом применён в домене C ≠ домены-источники (reuse-запись);
 *  (г) CV_H(мета-закон) ≤ max CV_H(исходных) — на ОДНИХ heldout-срезах.
 *
 * Фикстура (детерминированные seed'ы, 09.10 пробы v4-v6):
 *  домен A (heat-градиент):  y = hi − lo, 2 колонки
 *  домен B (осмос):          y = hi − lo, 2 колонки, другой диапазон
 *  домен C (тепловой поток): y = G·(Th−Tc)·A/d, 5 колонок
 *  Сырой поиск C (beam 10) не решает 5-колоночную молекулу (beam-
 *  компетенция, проба v1/v2); через BW-атом — решает (проба v6).
 *
 * Знако-инвариантность cv (проба v5): pred=−y даёт cv=0 — ассерты
 * на формулы НЕ опираются на знак, только на cv и наличие атома.
 */
class LanguageVerify28Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        LanguageVerify28::reset();
    }

    protected function tearDown(): void
    {
        // Дублирующая чистка (прецедент deleg_3302236f): BW-атомы и законы
        // фикстуры не переживают тест даже при раннем исключении.
        $this->cleanup();
        LanguageVerify28::reset();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE domain LIKE 'v28_%'");
        $db->exec("DELETE FROM grammar_ops WHERE name LIKE 'BW%'");
    }

    /**
     * Прогон идемпотентен: report() мемоизирован, reset() для тестов.
     */
    private function report(): array
    {
        return LanguageVerify28::report();
    }

    // ═══ (a) мета-закон сжат компрессором из ≥2 законов ≥2 доменов ═══

    public function testMetaLawCompressedFromTwoDomains(): void
    {
        $rep = $this->report();

        $this->assertTrue($rep['a']['pass'], 'a: атом должен родиться от компрессора: ' . json_encode($rep['a']));
        $this->assertNotSame('', $rep['a']['atom'], 'a: имя атома (BW+hash)');
        $this->assertNotSame('', $rep['a']['definition'], 'a: definition = канон сжатой формы');
        $this->assertGreaterThanOrEqual(
            2,
            count($rep['a']['source_domains']),
            'a: законы из ≥2 разных доменов, домены: ' . json_encode($rep['a']['source_domains'])
        );
        $this->assertGreaterThanOrEqual(2, $rep['a']['source_laws'], 'a: ≥2 закона в группе изоморфов');
    }

    // ═══ (б) атом в grammar_ops и доступен поиску (контраст в домене C) ═══

    public function testAtomAvailableInForeignGrammar(): void
    {
        $rep = $this->report();

        $this->assertTrue($rep['b']['pass'], 'b: атом доступен поиску: ' . json_encode($rep['b']));
        $this->assertFalse(
            $rep['b']['raw_domain_c']['found'],
            'б-контраст: без атома домен C (5 кол) не решается (beam-компетенция)'
        );
        $this->assertGreaterThan(
            0.0,
            (float) $rep['b']['raw_domain_c']['cv'],
            'б-контраст: cv сырого поиска > 0 (RED-буква: «cv>0»)'
        );
        $this->assertTrue($rep['b']['with_atom']['found'], 'б: с атомом домен C решается');
        $this->assertLessThan(
            1e-6,
            (float) $rep['b']['with_atom']['cv'],
            'б: cv домена C с атомом = 0 (точное решение)'
        );
        // статус-таймлайн: b показывает статус НА МОМЕНТ измерения (candidate),
        // v — финальный (active после reuse); premature-activация до измерения
        // или потеря активации = дефект
        $this->assertSame('candidate', $rep['b']['status'], 'б: статус в момент контраста');
        $this->assertSame('active', $rep['v']['status_after'], 'в: статус после reuse-активации');
    }

    // ═══ (в) атом применён в домене C (reuse-запись в реальном домене) ═══

    public function testAtomAppliedInForeignDomainWithReuseRecord(): void
    {
        $rep = $this->report();

        $this->assertTrue($rep['v']['pass'], 'v: reuse-запись: ' . json_encode($rep['v']));
        $this->assertContains('v28_conduct', $rep['v']['reuse_domains'], 'в: домен C в reuse_domains');
        $this->assertGreaterThanOrEqual(1, $rep['v']['reuse_count'], 'в: reuse_count ≥ 1');
        // формула домена C обязана СОДЕРЖАТЬ атом (применение, не подстрока def)
        $this->assertStringContainsString(
            (string) $rep['a']['atom'],
            (string) $rep['b']['with_atom']['formula'],
            'в: найденная формула C содержит BW-атом'
        );
    }

    // ═══ (г) компиляция не хуже коалиции (heldout, нестрогое ≤) ═══

    public function testCompilationNotWorseThanCoalition(): void
    {
        $rep = $this->report();

        $this->assertTrue($rep['g']['pass'], 'g: ' . json_encode($rep['g']));
        $this->assertLessThanOrEqual(
            (float) $rep['g']['coalition_cv'],
            (float) $rep['g']['meta_cv'],
            'г: CV_H(мета) ≤ max CV_H(исходных) на одних heldout-срезах'
        );
        // честность сравнителя: коалиция реально посчитана (не 9.99-заглушка)
        $this->assertLessThan(0.1, (float) $rep['g']['coalition_cv'], 'г: coalition_cv — живое число, не отказ');
    }

    /**
     * Вердикт verify = конъюнкция четырёх критериев.
     */
    public function testVerdictPassWhenAllCriteriaHold(): void
    {
        $rep = $this->report();

        $this->assertSame('PASS', $rep['verdict'], 'верdict: ' . json_encode(array_map(fn ($k) => $rep[$k]['pass'] ?? null, ['a', 'b', 'v', 'g'])));
    }

    // ═══ защита: прогон уничтожает словарь birth — персистентная БД запрещена ═══

    public function testRefusesToRunOnPersistentDatabase(): void
    {
        putenv('SWARM_DB_PATH=/tmp/v28_guard_forbidden.db');
        LanguageVerify28::reset();

        $this->expectException(\RuntimeException::class);
        LanguageVerify28::report();
    }
}
