<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\GrammarMutator;

/**
 * Story S1.6-GRADIENT WU-1: Signal-gradient mutation.
 *
 * Сигнал (ε < CV ≤ null_floor) не понижает порог закона — он НАПРАВЛЯЕТ
 * мутацию: операции из последней signal-формы получают повышенный вес
 * при выборе add/replace.
 *
 * Фикстуры на языке роя — инфикс '(x0maxx1)', НЕ функциональная нотация.
 */
class SignalGradientMutationTest extends TestCase
{
    /**
     * RED: Bee::signalHint не существует.
     *
     * Predicted: Error — call to undefined method.
     */
    public function testSignalHintMethodExists(): void
    {
        $bee = new Bee(['+', '×'], 10.0);

        $this->assertTrue(
            method_exists($bee, 'signalHint'),
            'Bee must have signalHint() to record the last signal formula'
        );
    }

    /**
     * RED: GrammarMutator::mutate не принимает 5-й параметр $preferred.
     *
     * Predicted: ReflectionException при доступе к параметру индекса 4.
     */
    public function testMutateAcceptsPreferredParameter(): void
    {
        $ref = new \ReflectionMethod(GrammarMutator::class, 'mutate');

        $this->assertGreaterThan(
            4,
            $ref->getNumberOfParameters(),
            'mutate() must accept a 5th parameter $preferred (after $weights, $p)'
        );

        $preferred = $ref->getParameters()[4] ?? null;
        $this->assertNotNull($preferred, '5th parameter must exist');
        $this->assertTrue(
            $preferred->allowsNull() || $preferred->isOptional(),
            '$preferred must be nullable/optional for backward compatibility'
        );
    }

    /**
     * Главный статистический тест WU-1: после signalHint формы с op 'max'
     * мутации add/replace выбирают 'max' чаще базовой доли (запас ≥5%).
     *
     * mt_srand фикс — детерминизм под -p8.
     */
    public function testMutationPrefersSignalOpsOverBaseline(): void
    {
        mt_srand(42);

        $bee = new Bee(['+', '×'], 10.0);
        // Signal-форма на языке роя: max — искомый оператор
        $bee->signalHint('(x0maxx1)');

        $available = ['+', '×', 'max', 'min', 'sq'];
        $base = $this->measurePreferredShare(null, $available);
        $hinted = $this->measurePreferredShare($bee, $available);

        $this->assertGreaterThan(
            $base + 0.05,
            $hinted,
            "Share of 'max' in add/replace must exceed baseline by >=5%: "
            . sprintf('base=%.3f hinted=%.3f', $base, $hinted)
        );
    }

    /**
     * WU-2 RED→GREEN: TTL-затухание сигнала. Хинт в тике 5, TTL=2:
     * в тике 7 (возраст == TTL) ещё жив (множитель ≈1.0 — гладко),
     * в тике 8 (возраст > TTL) — мутация слепая (null).
     */
    public function testSignalHintDecaysAfterTtlTicks(): void
    {
        $bee = new Bee(['+', '×'], 10.0);
        $bee->signalHint('(x0maxx1)', 5);

        $alive = $bee->signalPreferredOps(7, 2);
        $this->assertIsArray($alive, 'at age == TTL hint must still be alive');
        $this->assertSame(
            ['max'],
            array_keys($alive),
            'at age == TTL multiplier ≈1.0 (graceful decay, not a cliff)'
        );
        $this->assertNull(
            $bee->signalPreferredOps(8, 2),
            'at age > TTL hint must decay — mutation goes blind again'
        );
    }

    /**
     * Agent-review F5-хвост: минус-формы на каноне U+2212 бустятся так же
     * (связка known-список ↔ канонизатор). ASCII '-' в системе не существует
     * (ExpressionNormalizer BINARY_OPS), но контракт фиксируем тестом.
     */
    public function testMinusOpOnUnicodeCanonIsBoosted(): void
    {
        $bee = new Bee(['+', '×'], 10.0);
        $bee->signalHint('(x0−x1)', 3);
        $ops = $bee->signalPreferredOps(3);
        $this->assertIsArray($ops, 'минус-форма даёт preference');
        $this->assertSame(['−'], array_keys($ops), 'канон минуса = U+2212');
        $this->assertEqualsWithDelta(2.0, $ops['−'], 0.001, 'полный bias на свежем hint');
    }

    /**
     * WU-2: default TTL из env SIGNAL_GRADIENT_TTL (default 10).
     */
    public function testSignalHintDefaultTtlFromEnv(): void
    {
        putenv('SIGNAL_GRADIENT_TTL=1');
        try {
            $bee = new Bee(['+', '×'], 10.0);
            $bee->signalHint('(x0maxx1)', 0);
            $this->assertIsArray(
                $bee->signalPreferredOps(1),
                'age == TTL: alive'
            );
            $this->assertNull(
                $bee->signalPreferredOps(2),
                'age 2 > TTL 1: decayed'
            );
        } finally {
            putenv('SIGNAL_GRADIENT_TTL');
        }
    }

    /**
     * WU-2 GREEN-буква: затухание ГЛАДКОЕ — вычитание веса, не обнуление.
     * Свежий hint (age 0) даёт множитель SIGNAL_GRADIENT_BIAS, к границе
     * TTL множитель линейно спадает к ~1.0.
     */
    public function testDecayIsGradualNotCliff(): void
    {
        putenv('SIGNAL_GRADIENT_BIAS=3.0');
        try {
            $bee = new Bee(['+', '×'], 10.0);
            $bee->signalHint('(x0maxx1)', 10); // TTL default 10

            $fresh = $bee->signalPreferredOps(10);
            $mid = $bee->signalPreferredOps(15);
            $edge = $bee->signalPreferredOps(20);

            $this->assertEqualsWithDelta(
                3.0,
                $fresh['max'],
                0.001,
                'age 0: multiplier = bias'
            );
            $this->assertEqualsWithDelta(
                2.0,
                $mid['max'],
                0.01,
                'age TTL/2: multiplier halfway to 1.0'
            );
            $this->assertEqualsWithDelta(
                1.0,
                $edge['max'],
                0.01,
                'age == TTL: multiplier back to baseline (no cliff)'
            );
        } finally {
            putenv('SIGNAL_GRADIENT_BIAS');
        }
    }

    /**
     * Premortem H2 (RED→FIX): env=0 должен ВЫКЛЮЧАТЬ TTL (PHP-falsy '0'
     * не должен схлопываться в default 10). TTL=0 → hint мёртв сразу
     * (мутация всегда слепая) — operational rollback должен работать.
     */
    public function testTtlZeroEnvDisablesHint(): void
    {
        putenv('SIGNAL_GRADIENT_TTL=0');
        try {
            $bee = new Bee(['+', '×'], 10.0);
            $bee->signalHint('(x0maxx1)', 5);
            $this->assertNull($bee->signalPreferredOps(5),
                'TTL=0: hint must be disabled immediately (age 0 > TTL 0)');
        } finally {
            putenv('SIGNAL_GRADIENT_TTL');
        }
    }

    /**
     * Premortem H4 (RED→FIX): вызов с default currentTick=0 при hint из
     * тика >0 давал отрицательный age → guard проходил → bias вечен.
     * Отрицательный возраст = рассинхрон часов = невалидный вызов → null.
     */
    public function testNegativeAgeDoesNotExtendHint(): void
    {
        $bee = new Bee(['+', '×'], 10.0);
        $bee->signalHint('(x0maxx1)', 100);
        $this->assertNull($bee->signalPreferredOps(0, 10),
            'negative age (tick reset / missing tick) must not resurrect hint');
    }

    /**
     * Premortem H3 (RED→FIX): PROPAGATION=0 → mutate получает $weights=null.
     * Signal-boost НЕ должен превращать null в массив — uniform-ветка
     * (exploration при выключенной пропагации) обязана сохраниться.
     *
     * Замер: pickOp получает $missing (3 ops: max,min,sq) → uniform даёт
     * max ≈ 1/3; boost ×2 на max даёт max ≈ 2/4 = 0.5. Граница 0.42
     * разделяет (mt_srand фикс, N=300, флак-запас >10pp).
     */
    public function testSignalBoostRespectsNullWeights(): void
    {
        mt_srand(99);
        $available = ['+', '×', 'max', 'min', 'sq'];
        $n = 300;

        $hits = 0;
        for ($i = 0; $i < $n; $i++) {
            $mutated = GrammarMutator::mutate(['+', '×'], $available, null, 1.0, ['max' => 2.0]);
            $newOps = array_diff($mutated, ['+', '×']);
            if ($newOps !== [] && in_array('max', $newOps, true)) {
                $hits++;
            }
        }
        $share = $hits / $n;
        $this->assertLessThan(0.42, $share,
            "null weights + hint must stay uniform: share={$share} (uniform≈0.33, boosted≈0.5)");
    }

    /**
     * WU-1 wiring: следующая мутация грамматики пчелы = мутация грамматики
     * ребёнка при spawn. Пчела с hint формы '(x0maxx1)' должна рожать детей
     * с 'max' чаще базовой доли (запас ≥5%). Без wiring hint = dead code
     * (класс урока fingerprint-gap: запись без потребителя).
     */
    public function testSpawnChildGrammarPrefersSignalOps(): void
    {
        $available = ['+', '×', 'max', 'min', 'sq'];
        $n = 100;

        mt_srand(123);
        $hintedHits = $this->countSpawnMaxHits($n, $available, true);
        mt_srand(321);
        $baseHits = $this->countSpawnMaxHits($n, $available, false);

        $this->assertGreaterThan(
            $baseHits / $n + 0.05,
            $hintedHits / $n,
            'Spawn mutation must prefer signal ops: '
            . sprintf('base=%.3f hinted=%.3f', $baseHits / $n, $hintedHits / $n)
        );
    }

    /**
     * @param string[] $available
     */
    private function countSpawnMaxHits(int $n, array $available, bool $withHint): int
    {
        $hits = 0;
        for ($i = 0; $i < $n; $i++) {
            $bee = new Bee(['+', '×'], 30.0);
            if ($withHint) {
                $bee->signalHint('(x0maxx1)');
            }
            $child = $bee->spawn($available);
            // Родитель ['+','×'] без 'max' → любой max в ребёнке пришёл из мутации
            if ($child !== null && in_array('max', $child->grammar(), true)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * Доля 'max' среди выбранных операторов в add/replace-мутациях.
     * null вместо bee = базовая линия (без signal).
     */
    private function measurePreferredShare(?Bee $bee, array $available): float
    {
        $hits = 0;
        $n = 200;
        $grammar = ['+', '×'];
        // Явные uniform-weights (не null): null+preferred = uniform по контракту
        // premortem H3, а здесь мы меряем сам эффект boost при живой культуре
        $weights = array_fill_keys($available, 1.0);
        for ($i = 0; $i < $n; $i++) {
            $preferred = $bee?->signalPreferredOps();
            $mutated = GrammarMutator::mutate($grammar, $available, $weights, 1.0, $preferred);
            // add/replace = размер вырос или элемент не из исходной пары
            $newOps = array_diff($mutated, $grammar);
            if ($newOps !== [] && in_array('max', $newOps, true)) {
                $hits++;
            }
        }

        return $hits / $n;
    }
}
