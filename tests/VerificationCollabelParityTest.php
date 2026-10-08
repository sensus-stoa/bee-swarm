<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Hive\VerificationExecutor;
use BeeSwarm\Hive\VerificationTaskSource;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * VERIF-COLLABEL-PARITY (P0-блокер, 08.10).
 *
 * Verification Executor не знает доменных имён колонок: spawnForLaw писал
 * law_shape = LawShape::of(атом С ДОМЕННЫМИ именами) — маска сворачивает
 * только xN (\bx\d+\b), доменные литералы (surf, q_hi) остаются. Executor
 * ищет на generic-матрице → LawShape::of(найденное) — xN-маска → mismatch
 * ВСЕГДА (270 refuted / 0 confirmed в sandbox.db).
 *
 * Вторая дыра той же природы: anchorGate вычисляет anchorRatio(formula,
 * law_formula) — law_formula доменный, ресемпл-срез generic → evalAtom('surf')
 * = null → inverted-задачи foraged-законов = вечный inconclusive.
 *
 * Контракт фикса:
 *  - LawShape::toGeneric(formula, labels) — перевод имён в xN по конвенции
 *    Search::testCv:1427 (longest-first str_replace);
 *  - verification_tasks.law_formula_generic — generic-канон (executor-anchor);
 *  - law_formula остаётся ДОМЕННЫМ (join-ключ EscrowStore/laws, fake-LOSS
 *    урок); law_shape = маска от generic;
 *  - без labels контракт прежний (back-compat, generic-путь V0.14 WU-5).
 */
final class VerificationCollabelParityTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::get();
    }

    protected function tearDown(): void
    {
        Database::setPath(':memory:');
        Database::reset();
    }

    // ---------------------------------------------------------------
    // LawShape::toGeneric — переводчик домен→generic
    // ---------------------------------------------------------------

    public function testToGenericTranslatesDomainNamesToXN(): void
    {
        $labels = ['surf', 'q_lo', 'log_sigma_ratio_next'];
        $law = '((surf−q_lo)+R+log_sigma_ratio_next)';

        self::assertSame(
            '((x0−x1)+R+x2)',
            LawShape::toGeneric($law, $labels),
            'доменные имена переводятся в xN по позиции label'
        );
    }

    public function testToGenericIsLongestFirst(): void
    {
        // 'q' — префикс 'q_lo': без longest-first 'q_lo' испортился бы в 'x0_lo'
        $labels = ['q', 'q_lo'];

        self::assertSame(
            '(x1+x0)',
            LawShape::toGeneric('(q_lo+q)', $labels),
            'замена longest-first (конвенция Search::testCv:1432)'
        );
    }

    public function testToGenericTranslatesRStatAtoms(): void
    {
        // Rminsurf — R-статистика имени колонки: переводится той же заменой
        self::assertSame(
            '((x0/Rminx0)+K2)',
            LawShape::toGeneric('((surf/Rminsurf)+K2)', ['surf'])
        );
    }

    public function testToGenericIdentityWithoutLabels(): void
    {
        self::assertSame('(K2×x0)', LawShape::toGeneric('(K2×x0)', null));
        self::assertSame('(K2×x0)', LawShape::toGeneric('(K2×x0)', []));
    }

    public function testToGenericThenMaskEqualsGenericPathShape(): void
    {
        // Ключевой инвариант: маска переведённого foraged-закона == маска
        // того же закона в generic-мире (паритет имён = паритет масок).
        $labels = ['surf', 'q_hi'];
        $law = '((surf/Rminsurf)+K2)';
        $generic = '(x0/Rminx0)+K2';

        self::assertSame(
            LawShape::of($generic),
            LawShape::of(LawShape::toGeneric(ExpressionNormalizer::normalize($law), $labels)),
            'toGeneric+of == of(generic-эквивалент)'
        );
    }

    // ---------------------------------------------------------------
    // VTS: spawnForLaw принимает col_labels
    // ---------------------------------------------------------------

    public function testSpawnForLawWithLabelsWritesGenericShapeAndDomainFormula(): void
    {
        $labels = ['surf', 'q_hi'];
        $law = '((surf/Rminsurf)+K2)';

        $tasks = (new VerificationTaskSource())
            ->spawnForLaw($law, 'dom_clp', 'fp_clp', [], null, $labels);

        self::assertCount(6, $tasks);
        $canon = ExpressionNormalizer::normalize($law);
        $expectedGeneric = '((x0/Rminx0)+K2)';
        $this->assertLawTaskContract($tasks[0], $canon, $expectedGeneric);

        // Анти-weak (питфолл 08.10: in-memory массив ≠ строка в БД —
        // persist() c рассинхроном плейсхолдеров тихо глотался catch'ем).
        $row = Database::get()->query(
            "SELECT law_formula, law_formula_generic, law_shape FROM verification_tasks
             WHERE domain = 'dom_clp' LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'задачи записаны в verification_tasks');
        $this->assertLawTaskContract($row, $canon, $expectedGeneric);
    }

    /**
     * Единый контракт полей закона задачи: доменный law_formula, generic-канон,
     * generic-маска law_shape (in-memory spec и строка БД — один контракт).
     */
    private function assertLawTaskContract(array $task, string $canon, string $generic): void
    {
        self::assertSame($canon, $task['law_formula'], 'law_formula остаётся доменным (join-ключ laws/escrow)');
        self::assertSame($generic, $task['law_formula_generic'], 'generic-канон в задаче');
        self::assertSame(LawShape::of($generic), $task['law_shape'], 'law_shape — маска GENERIC-формы');
    }

    public function testSpawnForLawWithoutLabelsKeepsLegacyContract(): void
    {
        $tasks = (new VerificationTaskSource())
            ->spawnForLaw('(x0×K2)', 'dom_clp_legacy', 'fp_clp');

        $first = $tasks[0];
        self::assertSame('(K2×x0)', $first['law_formula']);
        self::assertSame('(K2×x0)', $first['law_formula_generic'], 'без labels generic = канон');
        self::assertSame(LawShape::of('(K2×x0)'), $first['law_shape']);
    }

    // ---------------------------------------------------------------
    // VTS: spawnInvertedResearch (второй писатель law_shape, WU-5)
    // ---------------------------------------------------------------

    public function testSpawnInvertedResearchWithLabelsWritesGenericShape(): void
    {
        (new VerificationTaskSource())->spawnInvertedResearch(
            '(surf−q_hi)',
            '(surf+K2)',
            'dom_clp_res',
            ['surf', 'q_hi']
        );

        $row = Database::get()->query(
            "SELECT law_formula, law_shape FROM verification_tasks WHERE kind = 'inverted_research' AND domain = 'dom_clp_res'"
        )->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($row);
        self::assertSame(
            ExpressionNormalizer::normalize('(surf−q_hi)'),
            $row['law_formula'],
            'formula_a остаётся доменным'
        );
        self::assertSame(
            LawShape::of('(x0−x1)'),
            $row['law_shape'],
            'law_shape research-задачи — generic-маска'
        );
    }

    // ---------------------------------------------------------------
    // Миграция: verification_tasks.law_formula_generic
    // ---------------------------------------------------------------

    public function testVerificationTasksHasLawFormulaGenericColumn(): void
    {
        $cols = Database::get()->query(
            'PRAGMA table_info(verification_tasks)'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');

        self::assertContains('law_formula_generic', $names, 'колонка law_formula_generic есть');
    }

    // ---------------------------------------------------------------
    // Executor: anchor-гейт читает law_formula_generic
    // ---------------------------------------------------------------

    public function testInvertedForagedLawAnchorsOnGenericFormula(): void
    {
        // foraged-закон: law_formula доменный, resample-срез generic.
        // y = −2x (инверсия); ресемпл на −y найдёт (K2×x0) → shape match
        // → anchor-гейт. До фикса: evalAtom('surf')=null → ratio null →
        // inconclusive. После фикса: anchor по generic → confirmed.
        $rows = [];
        foreach (range(1, 10) as $i) {
            $rows[] = [(float) $i, -2.0 * $i];
        }
        $this->insertVTask('dom_clp_exec', '(K2×surf)', '(K2×x0)', 'inverted', json_encode($rows));

        $executed = (new VerificationExecutor())->runPendingVerificationTasks('dom_clp_exec', 1);
        self::assertSame(1, $executed, 'задача исполнена');
        self::assertSame(
            'confirmed',
            $this->vTaskStatus('dom_clp_exec'),
            'anchor-гейт обязан считать по law_formula_generic, не по доменному law_formula'
        );
    }

    private function insertVTask(string $domain, string $lawFormula, string $generic, string $kind, string $dataJson): void
    {
        Database::get()->prepare(
            'INSERT INTO verification_tasks
             (law_formula, law_shape, law_formula_generic, kind, resample_seed, target_sign, fingerprint, domain, data_json)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $lawFormula,
            LawShape::of($generic),
            $generic,
            $kind,
            0,
            -1,
            '',
            $domain,
            $dataJson,
        ]);
    }

    private function vTaskStatus(string $domain): string
    {
        return (string) Database::get()->query(
            "SELECT status FROM verification_tasks WHERE domain = '{$domain}'"
        )->fetchColumn();
    }

    /**
     * Пин premortem H1 (08.10): если продюсер col_labels когда-нибудь отдаст
     * строку вместо массива, is_array-гвард в Hive молча вернёт null → тихий
     * откат к P0-багу (all-VREFUTED, невидимому на фоне старой нормы 270/0).
     * Round-trip через JSON (форма хранения fd.col_labels / laws.col_labels)
     * обязан сохранять массив — и спавн обязан писать non-null generic.
     */
    public function testColLabelsSurviveStorageRoundTrip(): void
    {
        $labels = json_decode(json_encode(['surf', 'q_hi']), true);
        self::assertIsArray($labels, 'round-trip JSON сохраняет массив (пререквизит пина)');

        (new VerificationTaskSource())
            ->spawnForLaw('(surf+q_hi)', 'dom_clp_rt', 'fp_rt', [], null, $labels);

        $generic = Database::get()->query(
            "SELECT law_formula_generic FROM verification_tasks WHERE domain = 'dom_clp_rt' LIMIT 1"
        )->fetchColumn();
        // Канон сортирует операнды (q_hi+surf) → generic (x1+x0); суть пина:
        // generic non-null И без доменных литералов (маска от generic-референса).
        self::assertSame('(x1+x0)', (string) $generic, 'generic записан non-null после round-trip');
        self::assertSame(
            LawShape::of('(x0+x1)'),
            LawShape::of((string) $generic),
            'маска generic-референса — доменные литералы не просочились'
        );
    }
}
