<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * AMENDMENT_STAGE_A read-priority quorum (спека §2.3): distinct readers per
 * mark, cert_use bump по сертифицированному закону, hot-предикат
 * (quorum K ∧ cert_use ≥ 1), priority-бонус (multiplicative).
 * EXP-037 guard: усиление только через сертификационное использование.
 * Метки в тестах — LABEL-space (c-формы), как в реальном trail.
 */
class TrailReadersTest extends TestCase
{
    

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec('DELETE FROM trail_marks');
        $db->exec('DELETE FROM trail_ghosts');
        $db->exec('DELETE FROM trail_readers');
        putenv('TRAIL_MARKS=1');
        putenv('QUORUM_K');
        putenv('READ_PRIORITY_MULT');
    }

    protected function tearDown(): void
    {
        putenv('TRAIL_MARKS');
        putenv('QUORUM_K');
        putenv('READ_PRIORITY_MULT');
        parent::tearDown();
    }

    private const LABELS = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];

    private static function scopeFp(): string
    {
        // Тот же scope-key, что bumpCertUse/confirmTrailMarks вычисляют из labels.
        return 'sha1:' . sha1(json_encode(['labels' => self::LABELS]));
    }

    private function insertMark(string $formula, int $certUse = 0): void
    {
        $st = Database::get()->prepare(
            'INSERT INTO trail_marks (fingerprint, formula, state, cert_use, birth_route) VALUES (?,?,?,?,1)'
        );
        $st->execute([self::scopeFp(), $formula, 'PERSISTED', $certUse]);
    }

    private function addReader(string $formula, int $tag): void
    {
        $st = Database::get()->prepare(
            'INSERT OR IGNORE INTO trail_readers (fingerprint, formula, reader_tag) VALUES (?,?,?)'
        );
        $st->execute([self::scopeFp(), $formula, $tag]);
    }

    private function distinctReaders(string $formula): int
    {
        $st = Database::get()->prepare(
            'SELECT COUNT(DISTINCT reader_tag) c FROM trail_readers WHERE fingerprint = ? AND formula = ?'
        );
        $st->execute([self::scopeFp(), $formula]);

        return (int) $st->fetchColumn();
    }

    public function testReaderDedupSameTagCountedOnce(): void
    {
        $this->insertMark('(c0×c3)');
        $this->addReader('(c0×c3)', 1);
        $this->addReader('(c0×c3)', 1); // повторное чтение тем же маршрутом
        $this->addReader('(c0×c3)', 1);
        $this->assertSame(1, $this->distinctReaders('(c0×c3)'), 'PK дедупит повторные чтения');
        $this->addReader('(c0×c3)', 2);
        $this->addReader('(c0×c3)', 3);
        $this->assertSame(3, $this->distinctReaders('(c0×c3)'), 'разные маршруты = разные читатели');
    }

    public function testQuorumHotRequiresKAndCertUse(): void
    {
        $this->insertMark('(c0×c3)', 0); // читателей много, cert_use=0
        for ($t = 1; $t <= 5; $t++) {
            $this->addReader('(c0×c3)', $t);
        }
        $rdr = $this->distinctReaders('(c0×c3)');
        $this->assertFalse(Search::isHotMark($rdr, 0), 'quorum без cert_use — не hot (EXP-037 guard)');
        Database::get()->exec("UPDATE trail_marks SET cert_use = 1 WHERE formula = '(c0×c3)'");
        $this->assertTrue(Search::isHotMark($rdr, 1), 'quorum + cert_use → hot');
    }

    public function testQuorumKEnvCalibration(): void
    {
        $this->insertMark('(c1×c4)', 1);
        $this->addReader('(c1×c4)', 1);
        $this->addReader('(c1×c4)', 2);
        $rdr = $this->distinctReaders('(c1×c4)');
        putenv('QUORUM_K=2');
        $this->assertTrue(Search::isHotMark($rdr, 1), 'K=2: два читателя достаточны');
        putenv('QUORUM_K=4');
        $this->assertFalse(Search::isHotMark($rdr, 1), 'K=4: два читателя не достигают кворума');
    }

    public function testPriorityBonusMultiplicativeAndNeutral(): void
    {
        putenv('READ_PRIORITY_MULT=1.5');
        $this->assertEqualsWithDelta(0.6 * 1.5, Search::applyReadPriority(0.6, true), 1e-12, 'hot → fit × MULT');
        $this->assertEqualsWithDelta(0.6, Search::applyReadPriority(0.6, false), 1e-12, 'не-hot → fit без изменений');
        putenv('READ_PRIORITY_MULT=1.0');
        $this->assertEqualsWithDelta(0.6, Search::applyReadPriority(0.6, true), 1e-12, 'MULT=1.0 — контроль, бонус нейтрален');
    }

    public function testCertUseBumpOnlyForLawParticipants(): void
    {
        // Метки в LABEL-space (как реальный trail). Участник закона:
        // (c0×c3) входит в ((c0×c3)+(c1×c4))+((c2×c5)+(c3×c5)); не-участник: (c1×c5).
        // birth_route=1 < trailFlightNo=5 — гард cross-чтения (как в проде).
        $this->insertMark('(c0×c3)', 0);
        $this->insertMark('(c1×c5)', 0);
        Search::$trailFlightNo = 5;
        $labels = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];
        Search::bumpCertUse($labels, '((c0×c3)+(c1×c4))+((c2×c5)+(c3×c5))');
        $a = Database::get()->query("SELECT cert_use FROM trail_marks WHERE formula = '(c0×c3)'")->fetch();
        $b = Database::get()->query("SELECT cert_use FROM trail_marks WHERE formula = '(c1×c5)'")->fetch();
        $this->assertSame(1, (int) $a['cert_use'], 'участник закона получает cert_use');
        $this->assertSame(0, (int) $b['cert_use'], 'не-участник не трогается');
    }

    public function testTrailMarksSchemaHasCertUseColumn(): void
    {
        $cols = Database::get()->query('PRAGMA table_info(trail_marks)')->fetchAll();
        $names = array_column($cols, 'name');
        $this->assertContains('cert_use', $names, 'trail_marks.cert_use (AMENDMENT §2.3)');
        $tables = Database::get()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'trail_readers'"
        )->fetchAll();
        $this->assertNotEmpty($tables, 'trail_readers table exists');
    }
}
