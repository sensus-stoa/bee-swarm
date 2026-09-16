<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\ContradictionEngine;
use PHPUnit\Framework\TestCase;

/**
 * V0.13 WU-1: ContradictionEngine::detect (§1.11 v1.7-draft).
 *
 * Триггер: CCPP Demo#2 — дегенерат устойчив (recurrence 3/3+) и зеркален
 * (corr = −0.947). ТРИЗ: противоречие не разрешается выбором стороны, а
 * снимается разделением слоёв: shape + anchor + sign. Закон = тройка
 * (shape, m̂, sign); противоречие живёт в sign-слое.
 *
 * Контракт detect(): CONTRADICTION только при ВСЕХ:
 *  - found=true;
 *  - ensemble-устойчив (recurrence >= 0.5) — без устойчивости это шум;
 *  - sign_co_gate FAIL (corr < 0);
 *  - |corr| >= 0.7 (зеркальная сила).
 * Иначе NOT_CONTRADICTION (двойной барьер от ложных срабатываний).
 *
 * Verdict поля: class (CONTRADICTION|NOT_CONTRADICTION), mirror_strength,
 * action (INVERT_AND_RESEARCH|NONE), pre_registered_hypothesis (строка,
 * фиксируется ДО ре-поиска — прозрачность против подгонки).
 */
final class ContradictionDetectTest extends TestCase
{
    /** CCPP-артефакт Demo#2: зеркальный устойчивый кандидат. */
    private function ccppCandidate(): array
    {
        return [
            'atom' => '((x0/Rminx0)−Rrangex3)',
            'found' => true,
            'ensemble_recurrence' => 1.0,   // 3/3 ресемпла
            'corr' => -0.947,
        ];
    }

    private function ensembleResult(float $recurrence): object
    {
        return (object) ['recurrence' => $recurrence, 'verdict' => $recurrence >= 0.5 ? 'ENSEMBLE_CERT' : 'UNSTABLE_CERTIFICATE'];
    }

    private function coGates(float $corr): object
    {
        return (object) ['sign_ok' => $corr > 0.0, 'scale_ok' => true, 'corr' => $corr, 'scale_ratio' => 0.15];
    }

    public function testCcppArtifactTriggersContradiction(): void
    {
        $out = ContradictionEngine::detect(
            $this->ccppCandidate(),
            $this->ensembleResult(1.0),
            $this->coGates(-0.947),
        );

        $this->assertSame('CONTRADICTION', $out->class, json_encode($out));
        $this->assertEqualsWithDelta(0.947, $out->mirror_strength, 0.001);
        $this->assertSame('INVERT_AND_RESEARCH', $out->action);
        $this->assertNotEmpty($out->pre_registered_hypothesis, 'Гипотеза пре-регистрируется ДО ре-поиска');
        $this->assertStringContainsString('0.5', $out->pre_registered_hypothesis, 'Гипотеза содержит критерий cv_инв < 0.5·cv');
    }

    public function testPositiveCorrelationIsNotContradiction(): void
    {
        $c = $this->ccppCandidate();
        $c['corr'] = 0.9;
        $out = ContradictionEngine::detect($c, $this->ensembleResult(1.0), $this->coGates(0.9));

        $this->assertSame('NOT_CONTRADICTION', $out->class, 'Знак правильный — противоречия нет');
        $this->assertSame('NONE', $out->action);
    }

    public function testWeakMirrorIsNotContradiction(): void
    {
        // |corr|=0.5 < 0.7: анти-корреляция слабая, не «закон в зеркале».
        // corr кандидата перекрывает coGates (candidates corr приоритетнее).
        $c = $this->ccppCandidate();
        $c['corr'] = -0.5;
        $out = ContradictionEngine::detect(
            $c,
            $this->ensembleResult(1.0),
            $this->coGates(-0.5),
        );

        $this->assertSame('NOT_CONTRADICTION', $out->class);
    }

    public function testUnstableEnsembleIsNotContradiction(): void
    {
        // corr=−0.9 но recurrence 1/25: без устойчивости это шум, не закон-зеркало
        $out = ContradictionEngine::detect(
            $this->ccppCandidate(),
            $this->ensembleResult(0.04),
            $this->coGates(-0.9),
        );

        $this->assertSame('NOT_CONTRADICTION', $out->class, 'Противоречие без устойчивости = шум');
    }

    public function testNotFoundIsNotContradiction(): void
    {
        $c = $this->ccppCandidate();
        $c['found'] = false;
        $out = ContradictionEngine::detect($c, $this->ensembleResult(1.0), $this->coGates(-0.947));

        $this->assertSame('NOT_CONTRADICTION', $out->class);
    }

    public function testScaleFailDoesNotTrigger(): void
    {
        // SCALE-провал — не sign-противоречие: другой слой, другая обработка
        $cg = $this->coGates(-0.947);
        $cg->sign_ok = true;
        $cg->scale_ok = false;
        $out = ContradictionEngine::detect($this->ccppCandidate(), $this->ensembleResult(1.0), $cg);

        $this->assertSame('NOT_CONTRADICTION', $out->class, 'SCALE-провал не входит в sign-слой противоречия');
    }
}
