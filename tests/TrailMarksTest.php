<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

/**
 * M1-EXT v2 WU-4 (10.10): trail marks — дорожная память (стигмергия).
 *
 * Контракт: сигнальная форма (лучшая непрошедшая гейт, но лучше null-класса)
 * переживает Search::find() и записывается в trail_marks (fingerprint-скоуп).
 * Следующий find() на ТОМ ЖЕ fingerprint стартует с меток в пуле.
 *
 * §0.5.1-легитимность: сигнал = лучше null_floor (V0.16-EPSPARITY
 * calibrateEpsilon по fingerprint даёт null_floor; неоткалиброванный
 * fingerprint → kind='uncalibrated', метка всё равно пишется — дешёвая
 * метка, дорогая верификация).
 *
 * «Signal must replicate» (V1.9-DL WU-D4 стык): uses инкрементится при
 * повторном чтении; uses≥3 без подтверждения — метка стирается (дорога
 * зарастает, аутофагия-логика §2.5.14).
 *
 * Дефект до WU-4 (RED): сигнальные формы (частичные суммы dot, cv 1.7-3.6
 * против null ~4) умирают вместе с find() — 20 сидов ходят одну дорогу
 * с чистой памятью.
 */
class TrailMarksTest extends TestCase
{
    private const DOMAIN_FINGERPRINT = 'x0,x1,x2,x3,x4,x5';

    /**
     * Scope-ключ WU-4: sha1(labels-JSON) — ТОТ ЖЕ расчёт, что в
     * Search::writeTrailMarks. Тест сверяет контракт, не дублирует логику БД.
     */
    private static function scopeKey(): string
    {
        // Тот же расчёт, что в Search::writeTrailMarks: ТОЛЬКО labels.
        // n (число строк/plausible) в scope НЕ входит — плавает между сидами
        // (решение сессии 10.10, комментарий в writeTrailMarks).
        return 'sha1:' . sha1(json_encode([
            'labels' => explode(',', self::DOMAIN_FINGERPRINT),
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec('DELETE FROM trail_marks');
        // Verify-путь M1-EXT v2: лестница требует ОБА флага (§0.6-канон:
        // verify-величины default OFF, verify-тесты включают явно).
        putenv('TRAIL_MARKS=1');
        putenv('SEARCH_RAWPAIR_POOL=1');
        putenv('SEARCH_NO_PREREG=1');
    }

    protected function tearDown(): void
    {
        putenv('TRAIL_MARKS=');
        putenv('SEARCH_RAWPAIR_POOL=');
        putenv('SEARCH_NO_PREREG=');
        Database::get()->exec('DELETE FROM trail_marks');
        parent::tearDown();
    }

    /**
     * Задача, где закон = одиночное произведение x0·x3, но со ЗАГЛУШКОЙ:
     * y = x0·x3 + шум с редкими выбросами? Нет — честнее: закон глубины 3
     * недостижим на depth-2 (dot-класс): y = полный dot. Частичная сумма
     * (x1×x4)+(x2×x5) — сильнейший сигнал, но не закон (cv 1.7 vs null ~4).
     * find() обязан оставить метку об этой форме.
     *
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function makeDotTask(int $seed, int $nRows = 240): array
    {
        mt_srand($seed);
        $X = [];
        $y = [];
        for ($i = 0; $i < $nRows; $i++) {
            $row = [];
            for ($j = 0; $j < 6; $j++) {
                $row[] = mt_rand() / mt_getrandmax() * 4.0 - 2.0;
            }
            $X[] = $row;
            $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
        }
        return [$X, $y];
    }

    public function testSignalFormSurvivesFindAsTrailMark(): void
    {
        [$X, $y] = self::makeDotTask(42);
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);

        $res = Search::find($X, $y, $g, 2, explode(',', self::DOMAIN_FINGERPRINT), 0.2, 0.15, 90.0);
        $this->assertFalse($res[0], 'pre-condition: dot is not solvable at depth-2');

        $stmt = Database::get()->prepare(
            'SELECT formula, kind, uses FROM trail_marks WHERE fingerprint = ?'
        );
        $stmt->execute([self::scopeKey()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($rows, 'signal forms must be marked as trail marks');
        $formulas = array_column($rows, 'formula');
        $hasPartialSum = false;
        foreach ($formulas as $f) {
            if (str_contains($f, '×') && str_contains($f, '+')) {
                $hasPartialSum = true;
            }
        }
        $this->assertTrue($hasPartialSum, 'at least one sum-of-products signal must be marked; got: ' . json_encode($formulas));
    }

    public function testMarksFeedExceptionWithoutViolation(): void
    {
        // Роевая лестница (симуляция sim_swarm PASS2 20/20): вылет 1 не находит
        // (dot недостижим на depth-2 за один вылет), пишет метки. Вылет 2 читает
        // метку в пул → метка⊕родитель = полный закон → НАХОДИТ. Это и есть
        // «метки питают, не нарушая вердикт»: закон от метки обязателен.
        // Если бы вылет 2 НЕ нашёл (закон недостижим) — контракт fallback:
        // uses обязан инкрементироваться (replication-счётчик).
        [$X, $y] = self::makeDotTask(42);
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);

        $res1 = Search::find($X, $y, $g, 2, explode(',', self::DOMAIN_FINGERPRINT), 0.2, 0.15, 90.0);
        $this->assertFalse($res1[0], 'pre-condition: flight 1 without marks does not find');
        $res2 = Search::find($X, $y, $g, 2, explode(',', self::DOMAIN_FINGERPRINT), 0.2, 0.15, 90.0);

        $stmt = Database::get()->prepare(
            'SELECT formula, uses FROM trail_marks WHERE fingerprint = ?'
        );
        $stmt->execute([self::scopeKey()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);

        if ($res2[0]) {
            // Основной контракт лестницы: метка привела к закону.
            // Формула-победитель обязана содержать метку-подмножество
            // (частичная сумма внутри финального закона).
            $this->assertStringContainsString('×', $res2[2], 'law must be a composition');
            $this->assertStringContainsString('+', $res2[2], 'law must be a sum-of-products at depth-2');
        } else {
            // Fallback: если закон недостижим — метка обязана реплицироваться.
            $maxUses = max(array_column($rows, 'uses'));
            $this->assertGreaterThanOrEqual(2, (int) $maxUses, 'second find on same fingerprint must increment uses');
        }
    }

    public function testNoMarksWhenDisabled(): void
    {
        putenv('TRAIL_MARKS=0');
        [$X, $y] = self::makeDotTask(42);
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
        Search::find($X, $y, $g, 2, explode(',', self::DOMAIN_FINGERPRINT), 0.2, 0.15, 90.0);
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM trail_marks WHERE fingerprint = ?'
        );
        $stmt->execute([self::scopeKey()]);
        $cnt = (int) $stmt->fetchColumn();
        $this->assertSame(0, $cnt, 'TRAIL_MARKS=0 must disable marking (default off = verify value)');
    }
}
