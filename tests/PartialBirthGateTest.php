<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

/**
 * PARTIAL-HYPOTHESIS-BIRTH Фаза 1 (27.08, EXP-035): PartialBirthGate.
 *
 * Частичная гипотеза слабой пчелы рождается как B-кандидат ТОЛЬКО
 * при выполнении ВСЕХ гейтов:
 *  1. ≥2 терминала (нетривиальная структура)
 *  2. CV < CV(mean) — не мусор
 *  3. Compression: definition вдвое короче будущего встраивания
 *  4. Голод линии (stale > 0) — рождение как ответ на безуспешность
 *
 * Рождение: candidate (RCB), активация после reuse≥1.
 */
class PartialBirthGateTest extends TestCase
{
    private function makeShortHive(): Hive
    {
        putenv('FORAGER_SOURCES=:');
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'pbg_'));
        $hive->run();
        return $hive;
    }

    /** Создаёт линию и доводит её до голода (stale>0 через цикл без прогресса). */
    private function starveALine(Hive $hive): void
    {
        $pool = $hive->dormantPool();
        $pool->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
        $hive->materializeFromPool(1);
        // 2 prune без прогресса → stale>0, но K=5 линия жива
        $hive->pruneLineages(5);
        $hive->pruneLineages(5);
    }

    private function birthOpName(string $domain, string $op): ?string
    {
        $row = Database::get()->prepare(
            "SELECT name FROM grammar_ops WHERE source='birth' AND definition=? AND birth_domain=?"
        );
        $row->execute([$op, $domain]);
        $name = $row->fetchColumn();
        return $name === false ? null : $name;
    }

    public function testPartialBirthCreatesCandidateOnHungryLine(): void
    {
        $hive = $this->makeShortHive();
        $this->starveALine($hive);

        $born = $hive->partialBirth('(x0−x1)', 0.42, 'arithmetic', 1.0);
        $this->assertNotFalse($born, 'голод + компрессия + нетривиальность = рождение');

        $name = $this->birthOpName('arithmetic', '(x0−x1)');
        $this->assertNotNull($name, 'B-кандидат обязан быть в grammar_ops');

        $status = Database::get()->prepare(
            "SELECT status FROM grammar_ops WHERE name=?"
        );
        $status->execute([$name]);
        $this->assertSame('candidate', $status->fetchColumn(),
            'RCB: рождение = кандидат, не активный');
    }

    public function testGateRejectsTrivialFormula(): void
    {
        $hive = $this->makeShortHive();
        $this->starveALine($hive);

        $born = $hive->partialBirth('(x0)', 0.42, 'arithmetic', 1.0);
        $this->assertFalse($born, 'один терминал = тривиально, отказ');
    }

    public function testGateRejectsGarbageCv(): void
    {
        $hive = $this->makeShortHive();
        $this->starveALine($hive);

        // CV=0.95 выше CV(mean)≈1.0? Нет, 0.95 < 1.0... но мусорный порог:
        // по контракту CV(e) обязан быть < 0.5 (заметно лучше среднего)
        $born = $hive->partialBirth('(x0−x1)', 0.95, 'arithmetic', 1.0);
        $this->assertFalse($born, 'CV близко к mean = мусор, отказ');
    }

    public function testGateRejectsWellFedLine(): void
    {
        $hive = $this->makeShortHive();

        // Линия БЕЗ голода: prune не вызывался, stale=0
        $born = $hive->partialBirth('(x0−x1)', 0.42, 'arithmetic', 1.0);
        $this->assertFalse($born, 'успешная линия не рождает атомы');
    }

    public function testCompressionGateRejectsBloatedFormula(): void
    {
        $hive = $this->makeShortHive();
        $this->starveALine($hive);

        // «Формула» длиннее будущего встраивания (B-name ~3-4 символа)
        // — атом раздувает пространство (урок EXP-029)
        $bloated = '((x0−x1)+((x0−x1)×(x0−x1)))';
        $born = $hive->partialBirth($bloated, 0.42, 'arithmetic', 1.0);
        $this->assertFalse($born, 'некомпактная формула = отказ (compression)');
    }

    public function testMaterializedChildInheritsPartialBirthOp(): void
    {
        $hive = $this->makeShortHive();
        $this->starveALine($hive);

        $this->assertNotFalse($hive->partialBirth('(x0−x1)', 0.42, 'arithmetic', 1.0));
        $bName = $this->birthOpName('arithmetic', '(x0−x1)');
        $this->assertNotNull($bName);

        // Активируем (reuse) — только active видны в грамматике потомка
        \BeeSwarm\Core\Grammar::registerReuse($bName, 'arithmetic');

        // Новая материализация из ТОЙ ЖЕ линии (родитель жив) —
        // потомок наследует пчелиную грамматику; B-атом приходит
        // из grammar_ops через Grammar (BASE+birth)
        $pool = $hive->dormantPool();
        $pool->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
        $this->assertSame(1, $hive->materializeFromPool(1));

        $bees = $hive->getBees();
        $child = $bees[count($bees) - 1];
        $grammar = $child->grammar();
        $this->assertContains($bName, $grammar,
            'потомок линии обязан видеть активированный B-атом');
    }

    public function testChildGrammarUnionIncludesBirthOps(): void
    {
        // Bee::grammar() объединяет personal + customGrammarOps;
        // birth-опы приходят через $this->customGrammarOps при создании
        // с наследованием. Проверка контрактная: Grammar::all() содержит
        // активированный birth-оп.
        $hive = $this->makeShortHive();
        $this->starveALine($hive);
        $this->assertNotFalse($hive->partialBirth('(x0×x1)', 0.3, 'arithmetic', 1.0));
        $bName = $this->birthOpName('arithmetic', '(x0×x1)');
        \BeeSwarm\Core\Grammar::registerReuse($bName, 'arithmetic');

        $g = new \BeeSwarm\Core\Grammar();
        $all = $g->all(); // all() УЖЕ возвращает имена (array_keys(ops) внутри)
        $this->assertContains($bName, $all,
            'Grammar::all() обязан включать активированный birth-оп');
    }

    public function testPartialBirthCannotDuplicate(): void
    {
        $hive = $this->makeShortHive();
        $this->starveALine($hive);

        $this->assertNotFalse($hive->partialBirth('(x0−x1)', 0.42, 'arithmetic', 1.0));
        $this->assertNotFalse($hive->partialBirth('(x0−x1)', 0.42, 'arithmetic', 1.0),
            'повторное открытие не падает (INSERT OR IGNORE)');

        $cnt = Database::get()->prepare(
            "SELECT COUNT(*) FROM grammar_ops WHERE source='birth' AND definition=? AND birth_domain=?"
        );
        $cnt->execute(['(x0−x1)', 'arithmetic']);
        $this->assertSame(1, (int) $cnt->fetchColumn(), 'дедуп: один B на definition+domain');
    }
}
