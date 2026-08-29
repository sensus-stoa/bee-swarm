<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\LawIsomorphismCompressor;
use BeeSwarm\Infra\Database;

/**
 * FLOOR-EMERGENCE M1 (EXP-038, 29.08): LawCompressor в рантайме.
 *
 * Инсайт юзера: этажи не открываются — ИСЧЕЗАЮТ по мере роста словаря.
 * depth_потребная = depth_сырая − суммарное_сжатие_цепи.
 *
 * Механизм §3.8 (протокол CV→0):
 * 1. Детектор изоморфизма: законы L1, L2 из РАЗНЫХ доменов с одинаковой
 *    структурой expression-tree (нормализованный fingerprint) →
 * 2. Рождение атома-слова (канонизация xN→x0/x1, partialBirth-контракт)
 * 3. Атом в grammar_ops → доступен поиску → depth-потребная снижается
 *
 * Критерий M1 (verify_2_8-совместимый): heat depth-3 с 2 атомами
 * решается на depth-2. Здесь — единичный механизм: изоморфизм → атом.
 */
class LawIsomorphismCompressorTest extends TestCase
{
    private LawIsomorphismCompressor $c;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE domain LIKE 'test_iso%'");
        $db->exec("DELETE FROM grammar_ops WHERE name LIKE 'BW%'");
        $this->c = new LawIsomorphismCompressor();
    }

    protected function tearDown(): void
    {
        // Дублирующая чистка (CONCERNS deleg_3302236f п.6): BW-атомы не
        // должны переживать тест даже при раннем исключении.
        Database::get()->exec("DELETE FROM grammar_ops WHERE name LIKE 'BW%'");
        parent::tearDown();
    }

    private function addLaw(string $name, string $formula, string $domain, float $cv = 0.001): void
    {
        Database::get()->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute([$name, $formula, $cv, $domain]);
    }

    // ═══ Изоморфизм → атом ═══

    public function testIsomorphicLawsFromDifferentDomainsBirthAtom(): void
    {
        // L1: chunk×A/d (heat) и L2: rate×size/latency (сеть) — одна структура,
        // разные домены. Свёртка обязана родить атом.
        $this->addLaw('heat_law', '((x0×x1)/x2)', 'test_iso_heat');
        $this->addLaw('net_law', '((x3×x4)/x5)', 'test_iso_net');

        $born = $this->c->compress(['test_iso_heat', 'test_iso_net']);

        $this->assertSame(1, $born, 'one atom must be born from isomorphic pair');
        $ops = Database::get()->prepare("SELECT name, definition FROM grammar_ops WHERE name LIKE 'BW%'");
        $ops->execute();
        $rows = $ops->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, 'exactly one BW atom in grammar_ops');
        // B-AS-ARGUMENT контракт: definition обязан быть канонизирован x0/x1
        $this->assertSame('((x0×x1)/x2)', str_replace(' ', '', $rows[0]['definition']), 'canonical definition of first form');
    }

    public function testSameDomainIsomorphDoesNotBirth(): void
    {
        // §3.8: законы должны быть из РАЗНЫХ доменов (иначе это просто дубль)
        $this->addLaw('a', '((x0×x1)/x2)', 'test_iso_a');
        $this->addLaw('b', '((x3×x4)/x5)', 'test_iso_a');

        $this->assertSame(0, $this->c->compress(['test_iso_a']), 'same-domain pair must not compress');
    }

    public function testNonIsomorphicLawsDoNotBirth(): void
    {
        $this->addLaw('a', '((x0×x1)/x2)', 'test_iso_a');
        $this->addLaw('b', '(x0+(x1×x2))', 'test_iso_b');

        $this->assertSame(0, $this->c->compress(['test_iso_a', 'test_iso_b']), 'different tree shape = no atom');
    }

    // ═══ Fingerprint: инвариантность к переименованию переменных ═══

    public function testFingerprintInvariantToVariableRenaming(): void
    {
        // K5-урок (kill-test): grounding, не memorization. Структура
        // (x7−x3) обязана иметь тот же fingerprint, что (x0−x1).
        $this->addLaw('a', '(x7−x3)', 'test_iso_a');
        $this->addLaw('b', '(x0−x1)', 'test_iso_b');

        $this->assertSame(1, $this->c->compress(['test_iso_a', 'test_iso_b']), 'renamed variables must still be isomorphic');
    }

    public function testRenameIsomorphismCanonicalDefinition(): void
    {
        // (x7−x3) и (x0−x1): канонизация по порядку появления → (x0−x1)
        $this->addLaw('a', '(x7−x3)', 'test_iso_a');
        $this->addLaw('b', '(x0−x1)', 'test_iso_b');
        $this->c->compress(['test_iso_a', 'test_iso_b']);

        $ops = Database::get()->prepare("SELECT definition FROM grammar_ops WHERE name LIKE 'BW%'");
        $ops->execute();
        $def = (string) $ops->fetchColumn();
        $this->assertSame('(x0−x1)', $def, 'canonical order of first appearance');
    }

    public function testMirrorFormsMergeAsOneWord(): void
    {
        // §3.8: изоморфизм = переименование переменных. (x1−x0) переводится
        // в (x0−x1) заменой x1↔x0 → это ОДНО слово-шаблон «разность».
        // Ориентация (знак) — ответственность binding при использовании
        // атома (фаза M1.5), не компрессора. Здесь фиксируем склейку.
        $this->addLaw('a', '(x0−x1)', 'test_iso_a');
        $this->addLaw('b', '(x1−x0)', 'test_iso_b');

        $this->assertSame(1, $this->c->compress(['test_iso_a', 'test_iso_b']), 'mirror forms = one word per §3.8 renaming-isomorphism');
    }

    // ═══ Re-run идемпотентность ═══

    public function testSecondCompressDoesNotDuplicateAtom(): void
    {
        $this->addLaw('a', '((x0×x1)/x2)', 'test_iso_a');
        $this->addLaw('b', '((x3×x4)/x5)', 'test_iso_b');

        $first = $this->c->compress(['test_iso_a', 'test_iso_b']);
        $second = $this->c->compress(['test_iso_a', 'test_iso_b']);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'idempotent: same pair must not birth twice');

        $cnt = Database::get()->prepare("SELECT COUNT(*) FROM grammar_ops WHERE name LIKE 'BW%'");
        $cnt->execute();
        $this->assertSame(1, (int) $cnt->fetchColumn(), 'no duplicate atoms');
    }
}
