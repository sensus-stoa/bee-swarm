<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Hive\LawIsomorphismCompressor;
use BeeSwarm\Infra\Database;

/**
 * A1 (pysr-rematch PLAN-v2): arity-aware apply + rename-fix.
 *
 * EXP-039 баг: renameTerminals в компрессоре ломает терминалы формул
 * с BW-словами внутри — слоты молекулы переименовываются, а BW-фрагмент
 * остаётся со старыми именами → каша при apply (x1/x2 дублируются).
 *
 * Правило юзера (30.08): терминалы формул с BW-словами = слоты молекулы,
 * renaming их НЕ трогает. Fix-проба EXP-039: def без renaming → cvH=0.
 *
 * Контракт (б) B-AS-ARGUMENT: definition с N терминалами → apply по
 * binding строки. Механизм доказан evaluateFormula-замером (EXP-039 §3:
 * 5 колонок → −0.667; AtomRegistry::apply 2 args → NULL).
 */
class MoleculeApplyTest extends TestCase
{
    /**
     * Имя слова-глагола (arity-2, как BWdiff0001 в prod).
     */
    private const VERB = 'BWdiff0001';

    /**
     * Имя молекулы (md5-вид, как BW428a0458... в prod).
     */
    private const MOL = 'BW428a0458deadbeefdeadbeefdeadbeef';

    /**
     * Prod-вид молекулярного закона: κ(T2−T1)·A/d → ((((x1BWx2)×x0)×x3)/x4).
     */
    private const MOL_LAW = '((((x1BWdiff0001x2)×x0)×x3)/x4)';

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec("DELETE FROM grammar_ops WHERE name IN ('" . self::VERB . "', '" . self::MOL . "', 'Bdbl0001')");
        $db->exec("DELETE FROM laws WHERE domain LIKE 'test_mol%'");
        // AtomRegistry::clearDefCache сбрасывает и новые A1-кэши
        // (bornArityCache/bornNodeCache) — premortem H4 (deleg_3e08d01b)
        AtomRegistry::clearDefCache();
        ExpressionEvaluator::resetCaches();
    }

    protected function tearDown(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM grammar_ops WHERE name IN ('" . self::VERB . "', '" . self::MOL . "', 'Bdbl0001')");
        $db->exec("DELETE FROM laws WHERE domain LIKE 'test_mol%'");
        AtomRegistry::clearDefCache();
        ExpressionEvaluator::resetCaches();
        parent::tearDown();
    }

    private function addBirthAtom(string $name, string $definition, string $status = 'candidate'): void
    {
        // INSERT OR REPLACE: тест переопределения атома (review п.5) вставляет
        // то же имя с новой definition — как пере-рождение после clearDefCache
        Database::get()->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, ?)")
            ->execute([$name, $definition, $status]);
    }

    private function addLaw(string $name, string $formula, string $domain): void
    {
        Database::get()->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute([$name, $formula, 0.001, $domain]);
    }

    // ═══ (а) rename-fix: слоты молекулы не переименовываются ═══

    public function testCanonizeKeepsOriginalTerminalsWhenFormulaContainsBirthWord(): void
    {
        // BW-слово присутствует в grammar_ops (как в prod) — компрессор обязан
        // распознать его в дереве и НЕ переименовывать терминалы.
        $this->addBirthAtom(self::VERB, '(x0−x1)');

        $canonical = LawIsomorphismCompressor::canonize(self::MOL_LAW);

        $this->assertSame(
            self::MOL_LAW,
            $canonical,
            "slots of a molecule containing a BW word must keep original names, got: {$canonical}"
        );
    }

    public function testCompressBirthsMoleculeWithUntouchedDefinition(): void
    {
        // Два домена, ИДЕНТИЧНАЯ молекулярная форма (prod-сценарий EXP-039:
        // heat и diffusion дали одинаковые формулы с одинаковыми xN).
        // Компрессор склеивает в молекулу, def обязан сохранить оригинальные
        // терминалы (fix-проба: def без renaming → cvH=0).
        $this->addBirthAtom(self::VERB, '(x0−x1)');
        $this->addLaw('heat_law', self::MOL_LAW, 'test_mol_heat');
        $this->addLaw('diff_law', self::MOL_LAW, 'test_mol_diff');

        $born = (new LawIsomorphismCompressor())->compress(['test_mol_heat', 'test_mol_diff']);

        $this->assertSame(1, $born, 'isomorphic pair with BW word inside must birth a molecule');
        $stmt = Database::get()->prepare("SELECT definition FROM grammar_ops WHERE name LIKE 'BW%' AND name != ?");
        $stmt->execute([self::VERB]);
        $def = (string) $stmt->fetchColumn();
        $this->assertSame(
            self::MOL_LAW,
            $def,
            "molecule definition must keep original slot names, got: {$def}"
        );
    }

    public function testCanonizeStillRenamesPlainFormulas(): void
    {
        // Регрессия: формулы БЕЗ BW-слов переименовываются как раньше.
        $this->addBirthAtom(self::VERB, '(x0−x1)');

        $canonical = LawIsomorphismCompressor::canonize('(x7−x3)');

        $this->assertSame('(x0−x1)', $canonical, 'plain formulas keep renaming-isomorphism');
    }

    // ═══ (б) arity-aware apply: молекула вычисляется по binding строки ═══

    public function testApplyNComputesMoleculeOnFiveColumns(): void
    {
        // Молекула: (((x1−x2)×x0)×x3)/x4 (BWdiff внутри = (x0−x1) слова).
        $this->addBirthAtom(self::VERB, '(x0−x1)');
        $this->addBirthAtom(self::MOL, self::MOL_LAW);

        // Строка [κ, T1, T2, A, d] = [2, 10, 30, 4, 5]:
        // BWdiff(10,30) = −20 → (−20×2) = −40 → ×4 = −160 → /5 = −32.
        $v = AtomRegistry::applyN(self::MOL, [2.0, 10.0, 30.0, 4.0, 5.0]);

        $this->assertSame(-32.0, $v, 'molecule must compute exactly on a 5-column binding');
    }

    public function testApplyNArityGuardRejectsWrongArgCount(): void
    {
        // Строгая арность: def с 5 терминалами + 1 аргумент → null
        // (молчаливый 0.0 для отсутствующих колонок = ложный закон).
        $this->addBirthAtom(self::MOL, self::MOL_LAW);

        $this->assertNull(AtomRegistry::applyN(self::MOL, [2.0, 10.0]), 'wrong arity must refuse');
    }

    public function testApplyNUnknownAtomReturnsNull(): void
    {
        $this->assertNull(AtomRegistry::applyN('BWnovelty0000000000000000000000', [1.0, 2.0]));
    }

    public function testApplyNMatchesLegacyApplyForArityTwo(): void
    {
        // Обратная совместимость: arity-2 слово через applyN == через apply.
        $this->addBirthAtom(self::VERB, '(x0−x1)');

        $this->assertSame(7.0, AtomRegistry::applyN(self::VERB, [10.0, 3.0]));
        $this->assertSame(7.0, AtomRegistry::apply(self::VERB, 10.0, 3.0));
    }

    public function testUnaryBirthAtomSurvivesLegacyApply(): void
    {
        // A1-регрессия: унарный B-атом через apply без $b обязан работать
        // (variadic-замыкание при strict_types отвергает null-аргумент).
        // def от x0: (x0×2) — унарное удвоение.
        $this->addBirthAtom('Bdbl0001', '(x0×K2)');

        $this->assertSame(6.0, AtomRegistry::apply('Bdbl0001', 3.0), 'unary B-atom apply(a) must not TypeError');
    }

    public function testRedefiningBirthAtomRefreshesArity(): void
    {
        // Review (deleg_f36d268b п.5): переопределение атома с тем же именем
        // обязано обновить арность/дерево. Инвалидация — clearDefCache
        // (вызывается из Grammar::staticAdd, прод-путь рождения атома).
        $this->addBirthAtom(self::MOL, self::MOL_LAW);
        $this->assertSame(5, $this->arityOf(self::MOL), 'initial arity-5');

        // «Переопределение» прод-путём: OR IGNORE вставку отбросит, но
        // clearDefCache (staticAdd) сбросит кэш в любом случае — проверяем
        // семантику сброса, а не SQL-конфликт.
        $this->addBirthAtom(self::MOL, '(x0−x1)');
        AtomRegistry::clearDefCache();

        $this->assertSame(2, $this->arityOf(self::MOL), 'redefinition must refresh arity');
        $this->assertSame(4.0, AtomRegistry::applyN(self::MOL, [7.0, 3.0]), 'new definition must execute');
    }

    private function arityOf(string $name): int
    {
        $m = new \ReflectionMethod(AtomRegistry::class, 'bornArity');
        $m->setAccessible(true);

        return (int) $m->invoke(null, $name, fn () => null);
    }
}
