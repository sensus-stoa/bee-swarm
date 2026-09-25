<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Hive\RecordKeeper;
use BeeSwarm\Infra\Database;

/**
 * V0.15 WU-2: конгруэнтное поглощение в RecordKeeper::record.
 *
 * Форма с ДРУГИМ каноном, предсказательно эквивалентная существующему закону
 * домена, НЕ вставляется второй строкой: usage_count существующего растёт,
 * класс фиксируется (class_id + class_domain_json), лог CONGRUENCE-CLASS.
 * Это и есть «не удваивать наблюдательное явление».
 *
 * Негативные пины (GREEN до кода — допустимо, один содержательный RED):
 * инконгруэнтная форма = свой закон; данные отсутствуют = поглощения нет
 * («не доказана эквивалентность» ≠ «доказана»).
 */
class RecordKeeperCongruenceTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        Database::get();
        Database::get()->exec('DELETE FROM laws');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'cong');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    /**
     * Детерминированный домен [0,40]^2 + y (последний столбец режется детектором).
     */
    private function rowsAB(int $n, float $shift): array
    {
        $rows = [];
        for ($i = 0; $i < $n; ++$i) {
            $x0 = fmod($i * 0.41 + $shift, 40.0);
            $x1 = fmod($i * 1.37 + $shift, 40.0);
            $s = $x0 + $x1;
            $rows[] = [$x0, $x1, $s * $s];
        }

        return $rows;
    }

    private function keeper(): RecordKeeper
    {
        $log = $this->logFile;

        return new RecordKeeper(static function (string $m) use ($log): void {
            file_put_contents($log, $m . "\n", FILE_APPEND);
        });
    }

    public function testCongruentMemberIsAbsorbedIntoClass(): void
    {
        $canonA = ExpressionNormalizer::normalize('((x0+x1)×(x0+x1))');
        $canonB = ExpressionNormalizer::normalize('(((x0×x0)+((x0×x1)+(x0×x1)))+(x1×x1))');
        $keeper = $this->keeper();

        $r1 = $keeper->record([
            'atom' => '((x0+x1)×(x0+x1))',
            'cv' => 0.01,
            'mode' => 'search',
        ], [
            'name' => 't1',
            'fingerprint' => 'fp-1',
            'data' => $this->rowsAB(120, 0.0),
        ], 'domC');
        $r2 = $keeper->record([
            'atom' => '(((x0×x0)+((x0×x1)+(x0×x1)))+(x1×x1))',
            'cv' => 0.02,
            'mode' => 'search',
        ], [
            'name' => 't2',
            'fingerprint' => 'fp-2',
            'data' => $this->rowsAB(120, 3.0),
        ], 'domC');

        $this->assertTrue($r1['inserted']);
        $this->assertFalse($r2['inserted'], 'конгруэнтная форма не вторая строка');
        $this->assertSame($canonA, $r2['congruence']['class_id'] ?? null);
        $count = (int) Database::get()->query("SELECT COUNT(*) FROM laws WHERE domain='domC'")->fetchColumn();
        $this->assertSame(1, $count, 'в домене одна строка закона');
        $row = Database::get()->query("SELECT * FROM laws WHERE domain='domC'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $row['usage_count'], 'usage_count растёт с атрибуцией');
        $this->assertSame(1, (int) $row['confirmed_count'], 'новый fp на других данных = подтверждение');
        $this->assertSame($canonA, $row['class_id']);
        $class = json_decode((string) $row['class_domain_json'], true);
        $this->assertContains($canonA, $class['members']);
        $this->assertContains($canonB, $class['members']);
        $this->assertStringContainsString('CONGRUENCE-CLASS', (string) file_get_contents($this->logFile));
    }

    public function testIncongruentFormulaInsertsAsOwnLaw(): void
    {
        $keeper = $this->keeper();
        $keeper->record([
            'atom' => '(x0+x1)',
            'cv' => 0.01,
            'mode' => 'search',
        ], [
            'name' => 't1',
            'fingerprint' => 'fp-1',
            'data' => $this->rowsAB(60, 0.0),
        ], 'domD');
        $r2 = $keeper->record([
            'atom' => '(x0×x1)',
            'cv' => 0.01,
            'mode' => 'search',
        ], [
            'name' => 't2',
            'fingerprint' => 'fp-2',
            'data' => $this->rowsAB(60, 1.0),
        ], 'domD');

        $this->assertTrue($r2['inserted'], 'инконгруэнтная форма — свой закон');
        $count = (int) Database::get()->query("SELECT COUNT(*) FROM laws WHERE domain='domD'")->fetchColumn();
        $this->assertSame(2, $count);
        $cls = Database::get()->query("SELECT class_id FROM laws WHERE formula='(x0×x1)' AND domain='domD'")->fetchColumn();
        $this->assertNull($cls ?: null, 'без класса: расхождение — не конгруэнтность');
        $this->assertStringNotContainsString('CONGRUENCE-CLASS', (string) file_get_contents($this->logFile));
    }

    public function testNoTaskDataMeansNoAbsorption(): void
    {
        $keeper = $this->keeper();
        $keeper->record([
            'atom' => '((x0+x1)×(x0+x1))',
            'cv' => 0.01,
            'mode' => 'search',
        ], [
            'name' => 't1',
            'fingerprint' => 'fp-1',
            'data' => $this->rowsAB(60, 0.0),
        ], 'domE');
        $r2 = $keeper->record([
            'atom' => '(((x0×x0)+((x0×x1)+(x0×x1)))+(x1×x1))',
            'cv' => 0.02,
            'mode' => 'search',
        ], [
            'name' => 't2',
            'fingerprint' => 'fp-2',
        ], 'domE');

        $this->assertTrue($r2['inserted'], 'нет данных = эквивалентность не доказана, своя строка');
        $this->assertArrayNotHasKey('congruence', $r2);
        $count = (int) Database::get()->query("SELECT COUNT(*) FROM laws WHERE domain='domE'")->fetchColumn();
        $this->assertSame(2, $count);
    }

    /**
     * V0.15 WU-3: граница класса = CLASS-SPLIT.
     * Пара x0 vs (x0max(x0−x0)) конгруэнтна на x>=1 (max = x0), расходится
     * на x<0 (max = 0). Данные за исторической границей класса → событие
     * CLASS-SPLIT с точкой расщепления, форма — свой закон, класс помечен
     * split_at.
     */
    public function testDomainBoundaryDivergenceEmitsClassSplit(): void
    {
        $keeper = $this->keeper();
        $keeper->record([
            'atom' => 'x0',
            'cv' => 0.01,
            'mode' => 'search',
        ], [
            'name' => 's1',
            'fingerprint' => 'fp-1',
            'data' => $this->posRows(60, 0.0),
        ], 'domS');
        $r2 = $keeper->record([
            'atom' => '(x0max(x0−x0))',
            'cv' => 0.02,
            'mode' => 'search',
        ], [
            'name' => 's2',
            'fingerprint' => 'fp-2',
            'data' => $this->posRows(60, 5.0),
        ], 'domS');
        $this->assertFalse($r2['inserted'], 'санити: на позитивном домене пара конгруэнтна');

        $r3 = $keeper->record([
            'atom' => '(x0max(x0−x0))',
            'cv' => 0.02,
            'mode' => 'search',
        ], [
            'name' => 's3',
            'fingerprint' => 'fp-3',
            'data' => $this->negRows(60),
        ], 'domS');

        $this->assertTrue($r3['inserted'], 'за границей класса форма — свой закон');
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('CLASS-SPLIT', $log);
        $this->assertStringContainsString('0maxx0', $log, 'канон формы-члена в событии');
        $row = Database::get()->query("SELECT class_domain_json FROM laws WHERE domain='domS' AND formula='x0'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $class = json_decode((string) $row['class_domain_json'], true);
        $this->assertIsArray($class);
        $this->assertArrayHasKey('split_at', $class, 'класс помечен точкой расщепления');
        $this->assertArrayHasKey('domain', $class, 'исторический домен класса зафиксирован');
    }

    private function posRows(int $n, float $shift): array
    {
        $rows = [];
        for ($i = 0; $i < $n; ++$i) {
            $x = fmod($i * 0.63 + 1.0 + $shift, 39.0) + 1.0;
            $rows[] = [$x, $x];
        }

        return $rows;
    }

    private function negRows(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; ++$i) {
            $x = -(fmod($i * 0.57, 39.0) + 0.5);
            $rows[] = [$x, $x];
        }

        return $rows;
    }

    /**
     * V0.15 fix (R9a): вступление в УЖЕ СУЩЕСТВУЮЩИЙ класс — кандидат
     * с class_id != null (не fallback-ветка). Третья форма класса
     * ((x0+x0)minx0) ≡ x0 на позитиве (проба 25.09: max_diff=0).
     */
    public function testJoinExistingClassByCarrierClassId(): void
    {
        $keeper = $this->keeper();
        $keeper->record(['atom' => 'x0', 'cv' => 0.01, 'mode' => 'search'], [
            'name' => 'j1', 'fingerprint' => 'fp-1', 'data' => $this->posRows(60, 0.0),
        ], 'domJ');
        $keeper->record(['atom' => '(x0max(x0−x0))', 'cv' => 0.02, 'mode' => 'search'], [
            'name' => 'j2', 'fingerprint' => 'fp-2', 'data' => $this->posRows(60, 2.0),
        ], 'domJ');
        $r3 = $keeper->record(['atom' => '(x0min(x0+x0))', 'cv' => 0.02, 'mode' => 'search'], [
            'name' => 'j3', 'fingerprint' => 'fp-3', 'data' => $this->posRows(60, 4.0),
        ], 'domJ');

        $this->assertFalse($r3['inserted'], 'третья форма входит в существующий класс');
        $this->assertSame('x0', $r3['congruence']['class_id'] ?? null, 'class_id от носителя-класса');
        $count = (int) Database::get()->query("SELECT COUNT(*) FROM laws WHERE domain='domJ'")->fetchColumn();
        $this->assertSame(1, $count);
        $json = json_decode((string) Database::get()->query("SELECT class_domain_json FROM laws WHERE domain='domJ'")->fetchColumn(), true);
        $this->assertCount(3, $json['members'] ?? []);
        // MED-2 (re-audit): join-путь тоже пишет ПОЛНОЕ состояние класса.
        $this->assertArrayHasKey('split_at', $json, 'полное состояние (split_at=null до сплита)');
        $this->assertArrayHasKey('domain', $json, 'полное состояние (bounds записаны)');
    }

    /**
     * V0.15 fix (R1+R3): поглощение ПОСЛЕ сплита обязано СОХРАНИТЬ
     * split_at (и накапливать domain) — состояние класса двуписательно.
     */
    public function testAbsorbAfterSplitPreservesSplitState(): void
    {
        $keeper = $this->keeper();
        $keeper->record(['atom' => 'x0', 'cv' => 0.01, 'mode' => 'search'], [
            'name' => 'k1', 'fingerprint' => 'fp-1', 'data' => $this->posRows(60, 0.0),
        ], 'domK');
        $keeper->record(['atom' => '(x0max(x0−x0))', 'cv' => 0.02, 'mode' => 'search'], [
            'name' => 'k2', 'fingerprint' => 'fp-2', 'data' => $this->posRows(60, 2.0),
        ], 'domK');
        $keeper->record(['atom' => '(x0max(x0−x0))', 'cv' => 0.02, 'mode' => 'search'], [
            'name' => 'k3', 'fingerprint' => 'fp-3', 'data' => $this->negRows(60),
        ], 'domK'); // CLASS-SPLIT
        $r4 = $keeper->record(['atom' => '(x0min(x0+x0))', 'cv' => 0.02, 'mode' => 'search'], [
            'name' => 'k4', 'fingerprint' => 'fp-4', 'data' => $this->posRows(60, 4.0),
        ], 'domK');

        $this->assertFalse($r4['inserted'], 'поглощение после сплита работает');
        $json = json_decode((string) Database::get()->query("SELECT class_domain_json FROM laws WHERE domain='domK' AND formula='x0'")->fetchColumn(), true);
        // R1/MED-1 (re-audit): ассерты ЗНАЧЕНИЙ, не ключей — ключ с null
        // маскировал бы деградацию split_at через ??.
        $this->assertSame(['index' => 0], $json['split_at'] ?? 'missing', 'split_at точное значение (сплит на первом негативе)');
        $this->assertIsArray($json['domain'] ?? null);
        $this->assertLessThan(1.5, $json['domain']['0'][0] ?? 99, 'bounds аккумулированы: min покрыл позитивный домен');
        $this->assertGreaterThan(35.0, $json['domain']['0'][1] ?? 0, 'bounds: max ~39');
        $this->assertCount(3, $json['members'] ?? []);
    }

    /**
     * V0.15 fix (R2/H2): окно кандидатов переполнено — эквивалентность
     * не проверялась. Форма встаёт обычным путём, но маркер
     * CONGRUENCE-UNTESTED обязан попасть в лог (наблюдаемость дедуп-пропуска).
     * Фикстура: 6 классов-«мусоров» (непарсable формулы → детектор
     * inconclusive → skip); окно берёт 5 свежих, 6-й доказывает усечение.
     */
    public function testWindowOverflowLogsUntestedMarker(): void
    {
        $keeper = $this->keeper();
        foreach ([1, 2, 3, 4, 5, 6] as $k) {
            Database::get()->prepare(
                "INSERT INTO laws (name, formula, cv, domain, found_at, class_id) VALUES ('bad{$k}', 'bad{$k}', 0.5, 'domW', ?, 'bad{$k}')"
            )->execute([sprintf('2026-09-25 10:0%d:00', $k - 1)]);
        }
        $r = $keeper->record(['atom' => '(x0min(x0+x1))', 'cv' => 0.02, 'mode' => 'search'], [
            'name' => 'w9', 'fingerprint' => 'fp-9', 'data' => $this->rowsAB(60, 1.0),
        ], 'domW');

        $this->assertTrue($r['inserted'], 'окно исчерпано — форма не проверена, встаёт');
        $this->assertSame(7, (int) Database::get()->query("SELECT COUNT(*) FROM laws WHERE domain='domW'")->fetchColumn(), '6 законов + вставленная');
        $this->assertSame(6, (int) Database::get()->query("SELECT COUNT(*) FROM laws WHERE domain='domW' AND class_id IS NOT NULL")->fetchColumn(), '6 классов > окно 5');
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('CONGRUENCE-UNTESTED', $log, 'дедуп-пропуск маркирован');
    }
}
