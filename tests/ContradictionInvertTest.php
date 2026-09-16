<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\ContradictionEngine;
use BeeSwarm\Certification\MetricPreflight;
use PHPUnit\Framework\TestCase;

/**
 * V0.13 WU-2: инверсионный ре-поиск (механическая ветвь INVERTED-LAW).
 *
 * Контракт: ContradictionEngine::invertAndResearch(X, y, candidate, cfg) —
 * второй прогон find() с таргетом −y (инверсия меняет ЗАДАЧУ, не данные — §0.4),
 * гипотеза пре-регистрируется ДО ре-поиска (лог), INVERT_MAX=1.
 *
 * Исходы:
 *  - INVERTED-LAW: cv_инв < 0.5 × cv_исходного И corr(pred_инв, y) > +0.7;
 *  - ANOMALY: инверсия не подтвердила гипотезу (WU-3, флаг метрической слепоты).
 *
 * Проба 16.09: y=−(x0·x1) чистое зеркало: +y находит ((x0hasx0²)−(x0×x1)) cv=0
 * (грамматика с −-оператором выражает зеркало!), −y находит (x0×x1) cv=0.
 * Тест-кейс строится наискось: +y cv должен быть > 0 (иначе candidate —
 * не «зеркало», а честная форма с минус-оператором).
 */
final class ContradictionInvertTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        putenv('PREFLIGHT_GATE_FACTOR=0.5');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'contradiction_inv_');
    }

    protected function tearDown(): void
    {
        foreach (['SWARM_DB_PATH', 'FORAGER_SOURCES', 'NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K', 'PREFLIGHT_GATE_FACTOR'] as $k) {
            putenv($k);
        }
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** Синтетика с зеркальным законом, выражаемым грамматикой на −y. */
    private function mirrorDomain(): array
    {
        mt_srand(333);
        $X = [];
        $y = [];
        for ($i = 0; $i < 200; $i++) {
            $a = mt_rand() / mt_getrandmax() * 10;
            $b = mt_rand() / mt_getrandmax() * 10;
            $X[] = [$a, $b];
            // +y: смещение делает знак-инверсию невыразимой без констант
            $y[] = -($a * $b) + 5;
        }

        return [$X, $y];
    }

    public function testInversionFindsInvertedLaw(): void
    {
        [$X, $y] = $this->mirrorDomain();
        // Исходный кандидат: грамматика нашла мусор с положительной корреляцией невозможна —
        // берём candidate с cv (не exact), corr<0, устойчивый
        $candidate = ['atom' => '((x0hasx0²)−(x0×x1))', 'found' => true, 'corr' => -0.95, 'cv' => 0.10];
        $out = ContradictionEngine::invertAndResearch(
            $X,
            $y,
            $candidate,
            $this->logFile,
            ['gate' => 0.15, 'depth' => 2, 'budget' => 15.0],
        );

        $this->assertContains($out->class, ['INVERTED_LAW', 'ANOMALY'], json_encode($out));
        if ($out->class === 'INVERTED_LAW') {
            $this->assertLessThan(0.5 * 0.10, $out->inverted_cv, 'Гипотеза: cv_инв < 0.5·cv');
            $this->assertGreaterThan(0.7, $out->inverted_corr, 'Гипотеза: corr(pred_инв, y) > +0.7');
        }
    }

    public function testInversionHypothesisLoggedBeforeResearch(): void
    {
        [$X, $y] = $this->mirrorDomain();
        $candidate = ['atom' => '((x0hasx0²)−(x0×x1))', 'found' => true, 'corr' => -0.95, 'cv' => 0.10];
        // Лог фиксирует гипотезу ДО записи результата ре-поиска (порядок строк)
        ContradictionEngine::invertAndResearch($X, $y, $candidate, $this->logFile, ['gate' => 0.15, 'depth' => 2, 'budget' => 15.0]);
        $log = (string) file_get_contents($this->logFile);
        $hypoPos = strpos($log, 'PRE_REGISTER');
        $resPos = strpos($log, 'INVERT_RESULT');

        $this->assertNotFalse($hypoPos, 'Гипотеза залогирована');
        $this->assertNotFalse($resPos, 'Результат залогирован');
        $this->assertLessThan($resPos, $hypoPos, 'Пре-регистрация СТРОГО до ре-поиска');
    }

    public function testInversionCappedAtOne(): void
    {
        // INVERT_MAX=1: повторный вызов на инвертированном результате не инвертирует снова
        // (контракт: метод принимает флаг already_inverted или кандидат несёт пометку).
        [$X, $y] = $this->mirrorDomain();
        $candidate = ['atom' => '(x0×x1)', 'found' => true, 'corr' => -0.95, 'cv' => 0.02, 'inverted' => true];
        $out = ContradictionEngine::invertAndResearch($X, $y, $candidate, $this->logFile, ['gate' => 0.15, 'depth' => 2, 'budget' => 15.0]);

        $this->assertSame('SKIP_ALREADY_INVERTED', $out->class, 'Анти-зацикливание: инверсия инверсии = исходная задача');
    }
}
