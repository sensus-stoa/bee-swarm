<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\TrailAdmission;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * R4-WU-3 (EXP-045 Road v4, спека WU_ROAD_V4_SPEC.md §1/§2): competitive
 * admission в writer — wiring через find() (unit-семантика классификации —
 * в TrailAdmissionTest).
 *
 * Контракт:
 *  - за вылет персистентно выживают ≤5 кандидатов (≤3 stair + ≤2 прочих),
 *    ранжирование по admission-proxy = |Pearson(values, y)|;
 *  - каждая строка несёт state/class/fit_last/birth_route (state machine §1);
 *  - телеметрия причин: Search::$lastTrailEvalStats (death-rate breakdown §4);
 *  - ghost fast-track: ре-адмиссия с confirm_count>0 → сразу CONFIRMED;
 *  - ghost-налог: evict_count≥2 ∧ confirm_count=0 → admit только при
 *    fit > class-median (спека §1).
 *
 * Фикстура: y = x0*x3 + умеренный шум — (x0×x3) детерминированно сильнейший
 * прочий кандидат (proxy ≈ 0.9+), задача НЕ решаема одним произведением
 * (cv выше гейта) → writer исполняется на каждом вылете.
 */
class TrailMarksV4Test extends TestCase
{
    private const DOMAIN_FINGERPRINT = 'x0,x1,x2,x3,x4,x5';

    private static function scopeKey(): string
    {
        return 'sha1:' . sha1(json_encode([
            'labels' => explode(',', self::DOMAIN_FINGERPRINT),
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec('DELETE FROM trail_marks');
        $db->exec('DELETE FROM trail_ghosts');
        putenv('TRAIL_MARKS=1');
        putenv('SEARCH_RAWPAIR_POOL=1');
        putenv('SEARCH_NO_PREREG=1');
        Search::$trailFlightNo = 0;
        Search::$trailFlightInitialized = false;
    }

    protected function tearDown(): void
    {
        putenv('TRAIL_MARKS=');
        putenv('SEARCH_RAWPAIR_POOL=');
        putenv('SEARCH_NO_PREREG=');
        $db = Database::get();
        $db->exec('DELETE FROM trail_marks');
        $db->exec('DELETE FROM trail_ghosts');
        Search::$trailFlightNo = 0;
        Search::$trailFlightInitialized = false;
        parent::tearDown();
    }

    /**
     * y = x0*x3 + шум (одно доминирующее произведение).
     *
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function makeTask(int $seed, int $nRows = 160): array
    {
        mt_srand($seed);
        $X = [];
        $y = [];
        for ($i = 0; $i < $nRows; $i++) {
            $row = [];
            for ($j = 0; $j < 6; $j++) {
                $row[] = 0.5 + mt_rand() / mt_getrandmax() * 1.5;
            }
            $X[] = $row;
            $y[] = $row[0] * $row[3] + (mt_rand() / mt_getrandmax() - 0.5) * 2.0;
        }

        return [$X, $y];
    }

    private static function grammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);

        return $g;
    }

    /**
     * @param array<string, mixed> $over
     */
    private static function seedMark(string $formula, array $over = []): void
    {
        $r = array_merge([
            'kind' => 'uncalibrated',
            'corr' => 0.5,
            'uses' => 0,
            'mark_tick' => 1,
            'class' => TrailAdmission::classOf($formula),
            'state' => 'PERSISTED',
            'written_count' => 1,
            'read_cross_count' => 0,
            'confirm_count' => 0,
            'fit_last' => 0.5,
            'birth_route' => 1,
            'last_activity_tick' => 1,
            'evict_count' => 0,
        ], $over);
        Database::run(
            'INSERT INTO trail_marks (fingerprint, formula, kind, corr, uses, mark_tick, class, state,
                written_count, read_cross_count, confirm_count, fit_last, birth_route, last_activity_tick, evict_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::scopeKey(), $formula, $r['kind'], $r['corr'], $r['uses'], $r['mark_tick'],
                $r['class'], $r['state'], $r['written_count'], $r['read_cross_count'],
                $r['confirm_count'], $r['fit_last'], $r['birth_route'], $r['last_activity_tick'], $r['evict_count']],
        );
    }

    /**
     * @param array<string, mixed> $over
     */
    private static function seedGhost(string $formula, array $over = []): void
    {
        $r = array_merge([
            'evict_count' => 1,
            'confirm_count' => 0,
            'fit_last' => 0.4,
        ], $over);
        Database::run(
            'INSERT INTO trail_ghosts (fingerprint, formula, evict_count, confirm_count, fit_last)
             VALUES (?, ?, ?, ?, ?)',
            [self::scopeKey(), $formula, $r['evict_count'], $r['confirm_count'], $r['fit_last']],
        );
    }

    /**
     * @return array<string, array<string, mixed>> formula → row
     */
    private static function trailRows(?string $fingerprint = null): array
    {
        $fp = $fingerprint ?? self::DOMAIN_FINGERPRINT;
        $stmt = Database::get()->prepare('SELECT * FROM trail_marks WHERE fingerprint = ?');
        $stmt->execute(['sha1:' . sha1(json_encode([
            'labels' => explode(',', $fp),
        ]))]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[$r['formula']] = $r;
        }

        return $rows;
    }

    private static function flight(): void
    {
        [$X, $y] = self::makeTask(42);
        Search::find($X, $y, self::grammar(), 2, explode(',', self::DOMAIN_FINGERPRINT), 0.2, 0.15, 30.0);
    }

    public function testAdmissionWritesStateAndClass(): void
    {
        self::flight();
        $rows = self::trailRows();
        $this->assertNotEmpty($rows, 'admission must persist marks on failed flight');
        foreach ($rows as $f => $r) {
            $this->assertSame('PERSISTED', $r['state'], "fresh admission of {$f} must be PERSISTED");
            $this->assertContains($r['class'], ['stair', 'petal', 'unary_binary', 'other']);
            $this->assertGreaterThan(0, (int) $r['birth_route'], 'birth flight recorded');
            $this->assertGreaterThan(0.0, (float) $r['fit_last'], "admission-proxy stored for {$f}");
        }
    }

    public function testAdmissionCapsFivePerFlightWithClassBalance(): void
    {
        self::flight();
        $rows = self::trailRows();
        $this->assertLessThanOrEqual(5, count($rows), 'admission window ≤5/flight (spec §2)');
        $stair = 0;
        $other = 0;
        foreach ($rows as $r) {
            if ($r['class'] === 'stair') {
                $stair++;
            } else {
                $other++;
            }
        }
        $this->assertLessThanOrEqual(3, $stair, 'class balance: ≤3 stair');
        $this->assertLessThanOrEqual(2, $other, 'class balance: ≤2 прочих');
    }

    public function testEvalStatsBreakdown(): void
    {
        self::flight();
        $stats = Search::$lastTrailEvalStats;
        $this->assertNotEmpty($stats, 'death-rate breakdown must be collected');
        foreach (array_keys($stats) as $k) {
            $this->assertContains($k, ['OK', 'LABEL_UNRESOLVABLE', 'PARSE_FAIL', 'EVAL_NULL', 'NON_FINITE', 'CONSTANT', 'DEPTH_EXCEEDED']);
        }
        $this->assertArrayHasKey('OK', $stats, 'dot fixture produces evaluable candidates');
    }

    public function testGhostFastTrackConfirmedOnReadmission(): void
    {
        self::seedGhost('(x0×x3)', [
            'evict_count' => 1,
            'confirm_count' => 1,
            'fit_last' => 0.5,
        ]);
        self::flight();
        $rows = self::trailRows();
        $this->assertArrayHasKey('(x0×x3)', $rows, 'dominant petal must be re-admitted');
        $this->assertSame('CONFIRMED', $rows['(x0×x3)']['state'], 'ghost confirm_count>0 → сразу CONFIRMED (spec §1)');
        $this->assertSame(1, (int) $rows['(x0×x3)']['evict_count'], 'ghost passport carried into new row');
    }

    public function testGhostTaxBlocksRepeatEvictee(): void
    {
        self::seedMark('(x0×x1)', [
            'class' => 'petal',
            'fit_last' => 0.95,
        ]);
        self::seedMark('(x0×x2)', [
            'class' => 'petal',
            'fit_last' => 0.93,
        ]);
        self::seedGhost('(x0×x3)', [
            'evict_count' => 2,
            'confirm_count' => 0,
            'fit_last' => 0.5,
        ]);
        self::flight();
        $rows = self::trailRows();
        $this->assertArrayNotHasKey('(x0×x3)', $rows, 'evict≥2 ∧ confirm=0 blocked by ghost-tax (proxy < class-median)');
    }

    public function testGhostTaxControlSingleEvictionAdmits(): void
    {
        self::seedMark('(x0×x1)', [
            'class' => 'petal',
            'fit_last' => 0.95,
        ]);
        self::seedMark('(x0×x2)', [
            'class' => 'petal',
            'fit_last' => 0.93,
        ]);
        self::seedGhost('(x0×x3)', [
            'evict_count' => 1,
            'confirm_count' => 0,
            'fit_last' => 0.5,
        ]);
        self::flight();
        $rows = self::trailRows();
        $this->assertArrayHasKey('(x0×x3)', $rows, 'evict_count=1 → налога нет, доминирующий лепесток admitting');
    }

    /* ---------- R4-WU-4: capacity / τ / NMD (спека §3/§4/§5) ---------- */

    /** Слабые 2-произведения: corr < 0.5 на фикстуре → поиск их НЕ ре-пропоузит
     * (не попадают в stair-arm), fit_last сидов остаётся детерминированным. */
    private static function weakStair(int $a, int $b, int $c, int $d): string
    {
        return "((x{$a}×x{$b})+(x{$c}×x{$d}))";
    }

    /**
     * Сеанс слабых лестниц с заданными fit_last.
     *
     * @param list<float> $fits
     * @return array<string, float> формула → fit
     */
    private static function seedWeakStairFits(array $fits): array
    {
        $seeded = [];
        foreach (array_values($fits) as $k => $fit) {
            $f = self::weakStair(0, 1 + ($k % 4), 2, 3 + ($k % 3));
            $seeded[$f] = $fit;
            self::seedMark($f, [
                'class' => 'stair',
                'fit_last' => $fit,
            ]);
        }

        return $seeded;
    }

    /**
     * @param list<string> $forms
     * @param list<float> $fits
     */
    private static function seedStairFits(array $forms, array $fits): void
    {
        foreach ($forms as $i => $f) {
            self::seedMark($f, [
                'class' => 'stair',
                'fit_last' => $fits[$i],
            ]);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private static function countClass(array $rows, string $class): int
    {
        $n = 0;
        foreach ($rows as $r) {
            if ($r['class'] === $class) {
                $n++;
            }
        }

        return $n;
    }

    private function assertGhost(string $formula, int $evict, string $message = ''): void
    {
        $g = Database::run(
            'SELECT evict_count FROM trail_ghosts WHERE fingerprint = ? AND formula = ?',
            [self::scopeKey(), $formula]
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($g, $message === '' ? 'expected tombstone for ' . $formula : $message);
        $this->assertSame($evict, (int) $g['evict_count']);
    }

    public function testCapacityEvictsLowestFitLastNonConfirmed(): void
    {
        $seeded = self::seedWeakStairFits([0.9, 0.8, 0.7, 0.6, 0.5, 0.4]);
        self::flight();
        $rows = self::trailRows();
        $this->assertLessThanOrEqual(6, self::countClass($rows, 'stair'), 'class cap 6 (spec §3)');
        $lowest = (string) array_search(0.4, $seeded, true);
        $this->assertArrayNotHasKey($lowest, $rows, 'lowest fit_last non-confirmed evicted');
        $this->assertGhost($lowest, 1, 'evicted mark leaves tombstone');
    }

    public function testConfirmedEvictedLastInClass(): void
    {
        // слабые чистые 2-произведения (corr < 0.5 → не ре-пропоузятся stair-arm)
        $forms = [
            '((x0×x1)+(x2×x4))', '((x0×x2)+(x2×x4))', '((x0×x4)+(x2×x3))',
            '((x0×x1)+(x2×x5))', '((x0×x2)+(x2×x5))',
        ];
        self::seedStairFits($forms, [0.8, 0.7, 0.6, 0.5, 0.4]);
        $confirmed = '((x0×x1)+(x2×x3))';
        self::seedMark($confirmed, [
            'class' => 'stair',
            'fit_last' => 0.3,
            'state' => 'CONFIRMED',
            'kind' => 'replicated',
            'confirm_count' => 2,
        ]);
        self::flight();
        $rows = self::trailRows();
        $this->assertArrayHasKey($confirmed, $rows, 'CONFIRMED evicted last in class (age protection earned)');
        $this->assertSame('CONFIRMED', $rows[$confirmed]['state']);
        $this->assertLessThanOrEqual(6, self::countClass($rows, 'stair'), 'class cap 6 (spec §3)');
        $this->assertArrayNotHasKey($forms[4], $rows, 'lowest fit_last PERSISTED evicted first (0.4 < confirmed 0.3 защиты)');
    }

    /** 12 CONFIRMED по 3 на класс; формы с '−' не ре-пропоузятся ни одной
     * collection-arm → сиды детерминированы. Возвращает жертву confirmed-cap. */
    private static function seedConfirmedSpread(): string
    {
        $byClass = [
            'stair' => ['((x0×x1)+(x2−x3))', '((x0×x2)+(x2−x4))', '((x0×x4)+(x2−x5))'],
            'petal' => ['(x0×x1−x2)', '(x0×x2−x3)', '(x0×x4−x1)'],
            'unary_binary' => ['((x0−x1)sq)', '((x0−x2)sq)', '((x0−x4)sq)'],
            'other' => ['(x0−x1)', '(x0−x2)', '(x0−x4)'],
        ];
        $victim = '(x0×x4−x1)';
        $fit = 0.5;
        foreach ($byClass as $cls => $forms) {
            foreach ($forms as $f) {
                $over = [
                    'class' => $cls,
                    'state' => 'CONFIRMED',
                    'kind' => 'replicated',
                    'confirm_count' => 1,
                    'fit_last' => $fit,
                ];
                if ($f === $victim) {
                    $over['fit_last'] = 0.35; // жертва confirmed-cap
                }
                self::seedMark($f, $over);
                $fit += 0.01;
            }
        }

        return $victim;
    }

    public function testConfirmedCapTwelveAcrossClasses(): void
    {
        $victim = self::seedConfirmedSpread();
        self::seedGhost('(x0×x3)', [
            'evict_count' => 1,
            'confirm_count' => 1,
            'fit_last' => 0.5,
        ]);
        self::flight();
        $rows = self::trailRows();
        $confirmedN = 0;
        foreach ($rows as $r) {
            if ($r['state'] === 'CONFIRMED') {
                $confirmedN++;
            }
        }
        $this->assertLessThanOrEqual(12, $confirmedN, 'confirmed cap 12 (spec §3)');
        $this->assertArrayHasKey('(x0×x3)', $rows, 'fast-track admission happened');
        $this->assertArrayNotHasKey($victim, $rows, 'lowest fit_last CONFIRMED evicted by cap');
        $this->assertGhost($victim, 1, 'victim leaves tombstone');
    }

    public function testConfirmedBelowCapNotEvicted(): void
    {
        // РЕВЬЮ deleg_231f9459 BLOCK: array_slice с отрицательной длиной
        // эвиктил 2·count−12 здоровых CONFIRMED при count 7..11.
        // 7 confirmed < 12 → ноль выселок, все живы.
        $victim = self::seedConfirmedSpread();
        Database::run('DELETE FROM trail_marks WHERE formula IN (?, ?, ?, ?, ?)', [
            '((x0×x1)+(x2−x3))', '((x0×x2)+(x2−x4))', '((x0×x4)+(x2−x5))',
            '(x0×x1−x2)', '(x0×x2−x3)',
        ]);
        self::flight();
        $rows = self::trailRows();
        $this->assertArrayHasKey($victim, $rows, 'confirmed below cap must NOT be evicted');
        $confirmedN = 0;
        foreach ($rows as $r) {
            if ($r['state'] === 'CONFIRMED') {
                $confirmedN++;
            }
        }
        $this->assertSame(7, $confirmedN, '7 confirmed → cap 12 far → zero evictions');
        $this->assertSame(0, (int) Database::run(
            'SELECT COUNT(*) FROM trail_ghosts WHERE fingerprint = ?',
            [self::scopeKey()]
        )->fetch(\PDO::FETCH_COLUMN), 'no tombstones below cap');
    }

    private static function seedTauTrio(): void
    {
        self::seedMark(self::weakStair(0, 1, 2, 3), [
            'class' => 'stair',
            'mark_tick' => 1,
            'last_activity_tick' => 1,
        ]);
        self::seedMark(self::weakStair(0, 2, 2, 3), [
            'class' => 'stair',
            'mark_tick' => 1,
            'last_activity_tick' => 4,
        ]);
        self::seedMark(self::weakStair(1, 2, 3, 4), [
            'class' => 'stair',
            'mark_tick' => 1,
            'last_activity_tick' => 3,
        ]);
    }

    public function testTauEvictsUncalibratedAfterThreeFlights(): void
    {
        // beam=0: изоляция write-стороны τ (read-инъекция refresh'ит τ —
        // это КОРРЕКТНОЕ поведение, тестируется отдельно в testCrossRoute)
        putenv('SEARCH_BEAM_K=0');
        try {
            self::seedTauTrio();
            Search::$trailFlightNo = 5;
            Search::$trailFlightInitialized = true;
            self::flight();
            $rows = self::trailRows();
            $this->assertArrayNotHasKey(self::weakStair(0, 1, 2, 3), $rows, 'age 5 > τ3 → EVICTED (mark not read)');
            $this->assertArrayHasKey(self::weakStair(0, 2, 2, 3), $rows, 'refreshed at 4 → age 2, alive');
            $this->assertArrayHasKey(self::weakStair(1, 2, 3, 4), $rows, 'age 3 = τ3 boundary → alive (evict strictly >)');
            $this->assertGhost(self::weakStair(0, 1, 2, 3), 1);
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }

    public function testTauConfirmedTwentyFlights(): void
    {
        putenv('SEARCH_BEAM_K=0');
        try {
            self::seedMark(self::weakStair(0, 1, 2, 3), [
                'class' => 'stair',
                'state' => 'CONFIRMED',
                'kind' => 'replicated',
                'confirm_count' => 1,
                'mark_tick' => 1,
                'last_activity_tick' => 1,
            ]);
            self::seedMark(self::weakStair(0, 2, 2, 3), [
                'class' => 'stair',
                'state' => 'CONFIRMED',
                'kind' => 'replicated',
                'confirm_count' => 1,
                'mark_tick' => 1,
                'last_activity_tick' => 10,
            ]);
            Search::$trailFlightNo = 25;
            Search::$trailFlightInitialized = true;
            self::flight();
            $rows = self::trailRows();
            $this->assertArrayNotHasKey(self::weakStair(0, 1, 2, 3), $rows, 'CONFIRMED age 24 > τ20 → EVICTED');
            $this->assertArrayHasKey(self::weakStair(0, 2, 2, 3), $rows, 'CONFIRMED age 15 ≤ τ20 → alive');
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }

    public function testNMDSweepsStructurallyBroken(): void
    {
        // константа на текущих X (спека §4: тот же AST-критерий)
        self::seedMark('(x0−x0)', [
            'class' => 'other',
            'fit_last' => 0.9,
        ]);
        // глубина: 5-членная лестница (4 '+' узла) — поломка depth
        self::seedMark('((((x0×x1)+(x2×x3))+((x4×x5)+(x0×x1)))+(x2×x3))', [
            'class' => 'stair',
            'fit_last' => 0.8,
        ]);
        // контроль: живая форма выживает
        self::seedMark(self::weakStair(0, 2, 2, 3), [
            'class' => 'stair',
            'fit_last' => 0.7,
        ]);
        self::flight();
        $rows = self::trailRows();
        $this->assertArrayNotHasKey('(x0−x0)', $rows, 'NMD: constant on current X → evicted');
        $this->assertArrayNotHasKey('((((x0×x1)+(x2×x3))+((x4×x5)+(x0×x1)))+(x2×x3))', $rows, 'NMD: depth-broken → evicted');
        $this->assertArrayHasKey(self::weakStair(0, 2, 2, 3), $rows, 'healthy mark survives NMD');
        $g = Database::run(
            'SELECT COUNT(*) FROM trail_ghosts WHERE fingerprint = ? AND formula = ?',
            [self::scopeKey(), '(x0−x0)']
        )->fetch(\PDO::FETCH_COLUMN);
        $this->assertSame(1, (int) $g);
    }

    /* ---------- R4-WU-5: read-переходы (спека §1/§2) ---------- */

    public function testCrossRouteInjectionCountsAndRefreshes(): void
    {
        putenv('SEARCH_BEAM_K=10');
        try {
            // '−' внутри → поиск эту форму не ре-пропоузит (нет в collection-arms),
            // но она eval-OK → единственная метка дороги инъектируется beam-вылетом
            $mark = '((x0×x1)+(x2−x3))';
            self::seedMark($mark, [
                'class' => 'stair',
                'fit_last' => 0.7,
                'birth_route' => 0,
                'mark_tick' => 0,
                'last_activity_tick' => 0,
            ]);
            self::flight();
            $this->assertContains($mark, Search::$lastTrailReadInjected, 'seeded mark must be injected on beam flight');
            $rows = self::trailRows();
            $this->assertSame(1, (int) $rows[$mark]['read_cross_count'], 'cross-route injection counted (r2 > birth)');
            $this->assertSame(1, (int) $rows[$mark]['last_activity_tick'], 'injection refreshes τ (last_activity = flight)');
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }

    /** @return array{0: list<list<float>>, 1: list<float>} лёгкая задача:
     * закон (x0×x3) находится на depth-2 */
    private static function makeEasyTask(): array
    {
        mt_srand(11);
        $X = [];
        $y = [];
        for ($i = 0; $i < 120; $i++) {
            $row = [];
            for ($j = 0; $j < 6; $j++) {
                $row[] = 0.5 + mt_rand() / mt_getrandmax() * 1.5;
            }
            $X[] = $row;
            $y[] = $row[0] * $row[3] + (mt_rand() / mt_getrandmax() - 0.5) * 0.4;
        }

        return [$X, $y];
    }

    public function testConfirmOnFoundLawParticipation(): void
    {
        putenv('SEARCH_BEAM_K=10');
        try {
            [$X, $y] = self::makeEasyTask();
            self::seedMark('(x0×x3)', [
                'class' => 'petal',
                'fit_last' => 0.9,
                'birth_route' => 0,
                'mark_tick' => 0,
                'last_activity_tick' => 0,
            ]);
            // junk-сосед: строка не в законе → не подтверждается
            self::seedMark('(x0−x1)', [
                'class' => 'other',
                'fit_last' => 0.9,
                'birth_route' => 0,
                'mark_tick' => 0,
                'last_activity_tick' => 0,
            ]);
            $res = Search::find($X, $y, self::grammar(), 2, explode(',', self::DOMAIN_FINGERPRINT), 0.2, 0.15, 30.0);
            $this->assertTrue($res[0], 'pre-condition: easy fixture must be solvable at depth-2');
            $rows = self::trailRows();
            $this->assertSame('CONFIRMED', $rows['(x0×x3)']['state'], 'participation in found law → CONFIRMED (spec §1)');
            $this->assertGreaterThanOrEqual(1, (int) $rows['(x0×x3)']['confirm_count']);
            $this->assertSame('PERSISTED', $rows['(x0−x1)']['state'], 'junk not in law → not confirmed (spec §2)');
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }

    public function testUsesDemotedNoAutophagy(): void
    {
        // старая аутофагия DELETE uses>7 убивала частые метки; Road v4:
        // uses — телеметрия, выживание решают capacity/τ/NMD
        self::seedMark('(x0−x2)', [
            'class' => 'other',
            'uses' => 50,
            'fit_last' => 0.7,
        ]);
        self::flight();
        $rows = self::trailRows();
        $this->assertArrayHasKey('(x0−x2)', $rows, 'uses demoted: no autophagy DELETE (spec §1)');
        $this->assertSame(50, (int) $rows['(x0−x2)']['uses']);
    }

    public function testPetalOnlyFlightWritesAndSweeps(): void
    {
        // РЕВЬЮ deleg_231f9459: гейт '!signalForms && !stairForms' пропускал
        // petal-only вылеты → класс petal не мог родиться сам, τ замирал.
        // 2 фичи: лестницы '((xa×xb)+(xc×xd))' структурно невозможны (дедуп
        // до одной формы), products против ±1-пилы — cv взрывается
        // (>6 signal-потолок) → signal/stair пусты, petal-коллектор собирает
        // БЕЗ гейта → вылет petal-only.
        putenv('SEARCH_BEAM_K=0');
        try {
            mt_srand(23);
            $X = [];
            $y = [];
            for ($i = 0; $i < 60; $i++) {
                $row = [0.5 + mt_rand() / mt_getrandmax() * 1.5, 0.5 + mt_rand() / mt_getrandmax() * 1.5];
                $X[] = $row;
                // ±500 через ноль: ratio-cv любой плавной формы взрывается (>6
                // signal-потолка, урок WU-4c 11.10) → signal arm гарантированно пуст
                $y[] = ($i % 2 === 0) ? 500.0 : -500.0;
            }
            $res = Search::find($X, $y, self::grammar(), 2, explode(',', 'x0,x1'), 0.2, 0.15, 30.0);
            $this->assertFalse($res[0], 'pre-condition: alternating y is not solvable at depth-2');
            $rows = self::trailRows('x0,x1');
            $this->assertNotEmpty($rows, 'petal-only flight must write marks (admission lives on petals too)');
            $this->assertGreaterThan(0, self::countClass($rows, 'petal'), 'petal class must be admitted on its own flight');
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }
}
