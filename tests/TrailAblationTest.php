<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * ШАГ III (EXP-045 Road v4, спека WU_ROAD_V4_SPEC.md §6-III): по-слойные
 * kill-switch абляции. TRAIL_ABLATE = CSV 'write,tau,slots,nmd'; unset/'' =
 * full v4 (все слои активны). Атрибуция слоёв — НЕ каузальность (спека).
 *
 * Ожидания прогонов (PREREG_STEP3_ABLATION.md):
 *  - write  → пол-контроль (0 found, как off);
 *  - nmd    → no-op в харнесе (одна и та же X → NMD не срабатывает;
 *             проверяем ОТСУТСТВИЕ дельты);
 *  - tau    → ≈no-delta (refresh инъекцией/confirm и так держит);
 *  - slots  → единственная дельта-кандидат (незапертая дорога → bloat).
 */
class TrailAblationTest extends TestCase
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
        putenv('TRAIL_ABLATE=');
        Search::$trailFlightNo = 0;
        Search::$trailFlightInitialized = false;
    }

    protected function tearDown(): void
    {
        putenv('TRAIL_MARKS=');
        putenv('SEARCH_RAWPAIR_POOL=');
        putenv('SEARCH_NO_PREREG=');
        putenv('TRAIL_ABLATE=');
        $db = Database::get();
        $db->exec('DELETE FROM trail_marks');
        $db->exec('DELETE FROM trail_ghosts');
        Search::$trailFlightNo = 0;
        Search::$trailFlightInitialized = false;
        parent::tearDown();
    }

    /**
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
            'class' => 'stair',
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

    private static function flight(): void
    {
        [$X, $y] = self::makeTask(42);
        Search::find($X, $y, self::grammar(), 2, explode(',', self::DOMAIN_FINGERPRINT), 0.2, 0.15, 30.0);
    }

    private static function weakStair(int $a, int $b, int $c, int $d): string
    {
        return "((x{$a}×x{$b})+(x{$c}×x{$d}))";
    }

    /**
     * @return array<string, array<string, mixed>> formula → row
     */
    private static function trailRows(): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM trail_marks WHERE fingerprint = ?');
        $stmt->execute([self::scopeKey()]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[$r['formula']] = $r;
        }

        return $rows;
    }

    public function testAblationReadsEnvCsv(): void
    {
        putenv('TRAIL_ABLATE=tau,nmd');
        $this->assertTrue(Search::trailAblation('tau'));
        $this->assertTrue(Search::trailAblation('nmd'));
        $this->assertFalse(Search::trailAblation('slots'));
        $this->assertFalse(Search::trailAblation('write'));
    }

    public function testAblationUnsetOrEmptyIsFullV4(): void
    {
        foreach (['', 'x'] as $env) {
            putenv('TRAIL_ABLATE=' . $env);
            foreach (['write', 'tau', 'slots', 'nmd'] as $layer) {
                $this->assertFalse(Search::trailAblation($layer), "layer {$layer} must be active when TRAIL_ABLATE='{$env}'");
            }
        }
    }

    public function testAblateWriteSkipsAdmission(): void
    {
        putenv('TRAIL_ABLATE=write');
        self::flight();
        $this->assertSame([], self::trailRows(), 'ablated write → no marks');
        $this->assertSame('ablate_write', Search::$lastTrailWriterInput['reason'] ?? null);
    }

    public function testAblateTauKeepsExpiredMark(): void
    {
        putenv('TRAIL_ABLATE=tau');
        putenv('SEARCH_BEAM_K=0');
        try {
            self::seedMark(self::weakStair(0, 1, 2, 3), [
                'mark_tick' => 1,
                'last_activity_tick' => 1,
            ]);
            Search::$trailFlightNo = 5;
            Search::$trailFlightInitialized = true;
            self::flight(); // age 5 > τ3 → без абляции эвикт
            $this->assertArrayHasKey(self::weakStair(0, 1, 2, 3), self::trailRows(), 'τ-ablated: expired mark survives');
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }

    public function testAblateSlotsKeepsOverflow(): void
    {
        putenv('TRAIL_ABLATE=slots');
        try {
            $fits = [0.9, 0.8, 0.7, 0.6, 0.5, 0.4];
            $k = 0;
            $seeded = [];
            foreach ($fits as $fit) {
                $f = self::weakStair(0, 1 + ($k % 4), 2, 3 + ($k % 3));
                $seeded[] = $f;
                self::seedMark($f, [
                    'class' => 'stair',
                    'fit_last' => $fit,
                ]);
                $k++;
            }
            self::flight(); // admission добавит stair'ов → без абляции lowest evicted
            $rows = self::trailRows();
            foreach ($seeded as $f) {
                $this->assertArrayHasKey($f, $rows, "slots-ablated: seeded {$f} survives overflow");
            }
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }

    public function testAblateNmdKeepsBrokenMark(): void
    {
        putenv('TRAIL_ABLATE=nmd');
        try {
            self::seedMark('(x0−x0)', [
                'class' => 'other',
                'fit_last' => 0.9,
            ]);
            self::flight(); // константа на X → без абляции NMD эвиктит
            $this->assertArrayHasKey('(x0−x0)', self::trailRows(), 'nmd-ablated: broken mark survives sweep');
        } finally {
            putenv('SEARCH_BEAM_K=');
        }
    }
}
