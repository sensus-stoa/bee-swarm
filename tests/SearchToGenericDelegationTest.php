<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\LawShape;
use BeeSwarm\Core\Search;
use PHPUnit\Framework\TestCase;

/**
 * TESTCV-TOGENERIC-DEDUP (техдолг VERIF-COLLABEL-PARITY, 08.10).
 *
 * Конвенция label→xN существовала в двух копиях (LawShape::toGeneric и
 * Search::testCv:1427) — дрейф любой молча вернул бы P0 all-VREFUTED.
 * Фикс: testCv делегирует LawShape::toGeneric — единый источник.
 *
 * Класс дефекта обеих копий ДО фикса: numeric-метка ('5') проходит
 * str-гвард, PHP int-cast превращает ключ '5' в int → str_replace
 * строит '(Kx0+x1)' из '(K5+feat)' → evaluator null → тихий 9.99.
 * После фикса: numeric-метка пропускается БЕЗ замены (порча
 * невозможна структурно), формула вычисляется честно.
 */
final class SearchToGenericDelegationTest extends TestCase
{
    /**
     * @var list<list<float>>
     */
    private array $X;

    /**
     * @var list<float>
     */
    private array $y;

    protected function setUp(): void
    {
        $this->X = [[1.0], [2.0], [3.0], [4.0]];
        $this->y = [2.0, 4.0, 6.0, 8.0];
    }

    /**
     * Пин 1 (equivalence): делегирование не меняет результат живого пути —
     * cv по формуле с labels == cv по формуле, переведённой toGeneric'ом.
     */
    public function testTestCvMatchesToGenericFirst(): void
    {
        $withLabels = Search::testCv('(K2×feat)', $this->X, $this->y, 1.0, 4, ['feat'], $this->X);
        $toGenericFirst = Search::testCv(
            LawShape::toGeneric('(K2×feat)', ['feat']),
            $this->X,
            $this->y,
            1.0,
            4,
            null,
            $this->X
        );

        self::assertSame($toGenericFirst, $withLabels, 'делегирование не меняет cv');
        self::assertSame(0.0, $withLabels, 'точный закон на своих данных = cv 0 (пререквизит пина)');
    }

    /**
     * Пин 2 (parity longest-first): порядок замен longest-first одинаков
     * в обеих конвенциях ('q' не съедает 'q_lo').
     */
    public function testLongestFirstParity(): void
    {
        // labels: q→x0, q_lo→x1; закон (q_lo+q) = x1+x0; y = x1+x0 точно.
        $X = [[1.0, 10.0], [2.0, 20.0], [3.0, 30.0], [4.0, 40.0]];
        $y = [11.0, 22.0, 33.0, 44.0];

        $withLabels = Search::testCv('(q_lo+q)', $X, $y, 1.0, 4, ['q', 'q_lo'], $X);
        $toGenericFirst = Search::testCv(
            LawShape::toGeneric('(q_lo+q)', ['q', 'q_lo']),
            $X,
            $y,
            1.0,
            4,
            null,
            $X
        );

        self::assertSame($toGenericFirst, $withLabels, 'longest-first parity');
        self::assertSame(0.0, $withLabels);
    }

    /**
     * Пин 3 (defect-класс): numeric-метка НЕ портит формулу.
     * До фикса: '(feat)' → needle '5' (int-cast) не строился, но старый
     * код без гвардов строил map из ЛЮБОЙ строки: '5'→x0 при форматной
     * замене мог задеть 'K5' → '(Kx0+x1)'. После: numeric-метка
     * пропущена, закон вычислен честно.
     * NOTE: K-константы evaluator'а — только K1/K2 (evalAtom), поэтому
     * закон берём без K: '(feat)'.
     */
    public function testNumericLabelDoesNotCorruptFormula(): void
    {
        // X: col0 = числовая метка '5' (порченый раньше needle-кандидат),
        // col1 = feat. y = feat (закон '(feat)' = x1).
        $X = [[7.0, 1.0], [7.0, 2.0], [7.0, 3.0], [7.0, 4.0]];
        $y = [1.0, 2.0, 3.0, 4.0];

        $cv = Search::testCv('(feat)', $X, $y, 1.0, 4, ['5', 'feat'], $X);

        self::assertNotSame(9.99, $cv, 'numeric-метка не должна молча портить формулу');
        self::assertSame(0.0, $cv, 'закон вычислен честно: метка 5 пропущена без замены');
    }

    /**
     * Пин 3b (зеркало в toGeneric): тот же numeric-кейс на самом
     * переводчике — needle не строится из числовой строки ('5' skip),
     * строковая метка переводится по своей позиции ('feat' → x1).
     */
    public function testToGenericSkipsNumericLabels(): void
    {
        self::assertSame(
            '(K5+x1)',
            LawShape::toGeneric('(K5+feat)', ['5', 'feat']),
            'numeric-метка пропущена, строковая переведена по позиции'
        );
    }

    /**
     * Пин 4 (пустая метка): '' не создаёт хвостовой needle
     * (str_replace с пустым search = порча всех позиций).
     */
    public function testToGenericSkipsEmptyLabels(): void
    {
        self::assertSame('(K2+x1)', LawShape::toGeneric('(K2+feat)', ['', 'feat']));
    }

    /**
     * Пин 5 (triage premortem Х2, 08.10): текущее поведение при метке-литерале
     * placeholder'а на чужой позиции — обе копии (старая testCv и toGeneric)
     * дают одинаковую схлопку (x0+x0). Не silent-drift, а pre-existing класс:
     * предусловие инъективности (метки xN стоят на своих позициях) задокументировано
     * в toGeneric. Пин фиксирует семантику, чтобы будущее изменение было ОСОЗНАННЫМ.
     */
    public function testToGenericXnLabelCollisionIsPinned(): void
    {
        self::assertSame('(x0+x0)', LawShape::toGeneric('(x1+feat)', ['x1', 'feat']));
    }

    /**
     * Пин 6 (triage premortem Х3, 08.10): однобуквенная метка 'x' после
     * вставки 'x0' — каскадная порча ('x0'→'x10'). Идентично удалённой копии
     * testCv (проба обеих механик 08.10: '(x10+x1)' в обеих) — порча
     * pre-existing конвенции, не дрейф делегирования. Пин фиксирует паритет.
     */
    public function testToGenericShortLabelParityWithLegacyCopy(): void
    {
        self::assertSame('(x10+x1)', LawShape::toGeneric('(feat+x)', ['feat', 'x']));
    }
}
