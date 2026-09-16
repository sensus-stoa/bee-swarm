<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\ShapeTally;
use PHPUnit\Framework\TestCase;

/**
 * V0.11 WU-1: консенсус-таблица форм ансамбля.
 *
 * Контракт §1.9: сигнатура результата члена = канон (ExpressionNormalizer)
 * -> LawShape маска (xN -> *, константы -> C). ShapeTally — обёртка над
 * LawShape::of (НЕ самодельный shapeKey runner'а со str_replace).
 *
 * Фикстуры сверены php-пробой канонизатора (15.09):
 *   '((x0×x1)+(x2×x3))' -> '((*×*)+(*×*))'  [коммутативный вариант — та же]
 *   '((x0×x1)×x2)'      -> '((*×*)×*)'      [другая структура]
 *   '(K2×x0)'           -> '(C×*)'
 *   '((x1/Rmaxx0+x1)−K2)' -> '((* / Rmaxx0)+*) − C'  [R-атом вербатим]
 *   '(B1a2b3c×x0)'      -> '(B1a2b3c×*)'
 *
 * Дизайн-решение (вопрос 1 хендоффа): B/BW- и R-атомы НЕ маскируются.
 * Имена детерминированы содержимым (BW+md5hex; R-атомы защищены protectAtoms),
 * одинаковый атом -> одинаковый токен -> консолидация работает; маскирование
 * B->B обобщило бы РАЗНЫЕ слова роя в ложный консенсус. Runner маскировал
 * B[0-9a-f]{6,}->B defensive — в Demo #3 атомов не было, маска не влияла.
 *
 * ВАЖНО: формы фикстур полностью скобочные — язык роя не пишет внешние скобки,
 * без них грамматика парсит с другим приоритетом (пробой: '(x0×x1)+(x2×x3)' ->
 * '((*×*)+(*))×*', скобки обязательны).
 */
final class ShapeTallyTest extends TestCase
{
    public function testCommutativeVariantsCollapseToOneShape(): void
    {
        $t = new ShapeTally();
        $t->add('((x0×x1)+(x2×x3))');
        $t->add('((x1×x0)+(x3×x2))');

        $tally = $t->tally();
        $this->assertCount(1, $tally, 'Коммутативные варианты обязаны канонизироваться в одну shape: ' . print_r($tally, true));
        $this->assertSame(2, reset($tally));
    }

    public function testDifferentStructureIsDifferentShape(): void
    {
        $t = new ShapeTally();
        $t->add('((x0×x1)+(x2×x3))');
        $t->add('((x0×x1)×x2)');

        $this->assertCount(2, $t->tally());
    }

    public function testConstantsMaskToCAndColumnAtomsToStar(): void
    {
        $t = new ShapeTally();
        $t->add('(K2×x0)');
        $shape = (string) array_key_first($t->tally());

        $this->assertSame('(C×*)', $shape);
        $this->assertStringNotContainsString('x0', $shape, 'Атом колонки обязан быть замаскирован в *');
    }

    public function testRAtomStaysVerbatimAsPartOfShape(): void
    {
        // Rmaxx0 — R-атом колонки: не xN и не константа, в shape вербатимом.
        // Закон CCPP-класса '(((x1/Rmaxx0)+x1)−K2)' канонизируется в
        // '(((*/Rmaxx0)+*)−C)' — форма различима, консолидация той же формы
        // того же домена работает (имя R-атома детерминировано содержимым).
        $t = new ShapeTally();
        $t->add('((x1/Rmaxx0+x1)−K2)');
        $t->add('((x1/Rmaxx0+x1)−K3)');

        $tally = $t->tally();
        $this->assertCount(1, $tally, 'Та же форма с другой константой = одна shape');
        $this->assertSame('(((*/Rmaxx0)+*)−C)', (string) array_key_first($tally));
    }

    public function testCompressorAtomNeverLeaksRawHex(): void
    {
        // B-атом компрессора: имя BW?<hex>, hex детерминирован контентом атома.
        // Один и тот же атом в двух членах = один токен; РАЗНЫЕ атомы остаются
        // разными (ложной консолидации разных слов нет — дизайн-решение WU-1).
        $t = new ShapeTally();
        $t->add('(B1a2b3c×x0)');
        $t->add('(B1a2b3c×x0)');

        $tally = $t->tally();
        $this->assertCount(1, $tally, 'Один и тот же B-атом = один токен формы');
        $this->assertSame(2, reset($tally));
        $this->assertSame('(B1a2b3c×*)', (string) array_key_first($tally), 'Имя атома сохраняется как часть формы');
    }

    public function testConsensusRespectsMinRate(): void
    {
        $t = new ShapeTally();
        $t->add('(x0×x1)');
        $t->add('(x1×x0)');
        $t->add('((x0×x1)×K2)');
        $t->add('(x0+x1)');

        $this->assertSame('(*×*)', $t->consensus(0.5), '2 коммутативных варианта = 2/4');
        $this->assertNull($t->consensus(0.75), '2/4 < 0.75 — консенсуса нет');
    }

    public function testConsensusReturnsNullOnEmpty(): void
    {
        $t = new ShapeTally();
        $this->assertNull($t->consensus(0.8));
    }

    public function testAddNullFormulaIsIgnored(): void
    {
        // Члены-отказы (found=false) дают формулу '' — в tally не попадают
        // (runner: if ($m['found'] && $m['shape'] !== null)).
        $t = new ShapeTally();
        $t->add('');
        $this->assertSame([], $t->tally());
    }
}
