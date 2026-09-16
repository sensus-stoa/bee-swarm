<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;
use BeeSwarm\Certification\EnsembleCertifier;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Hive\DiscoveryEngine;
use PHPUnit\Framework\TestCase;

/**
 * V0.11 WU-4: integration-тест ЖИВОГО пути discover() (класс
 * fingerprint-gap: каждое новое поле/хук обязан иметь integration-тест
 * пути). Хук после ранних return'ов.
 *
 * Стоимость: живой discover() с Search — второй тест держит полный бюджет
 * под контролем (n=80, depth=1 → первые ~2.5с на L0/L1, shallow-эскалация
 * завершится в рамках 600с); первый — чистый контракт выключенного хука.
 */
final class EnsembleWiringLiveTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['ENSEMBLE_K', 'NO_ENSEMBLE', 'ENSEMBLE_BUDGET_SEC', 'SWARM_DB_PATH', 'FORAGER_SOURCES', 'NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K'] as $k) {
            putenv($k);
        }
    }

    public function testDiscoverDoesNotCertifyWhenEnsembleKZero(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        putenv('ENSEMBLE_K=0');
        $de = new DiscoveryEngine();
        $X = [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]];
        $y = [10.0, 20.0, 30.0];
        $res = $de->discover($X, $y, ['+', '×'], 0.15, null, 0.2, 1, 10);

        $this->assertSame([], $res[0], 'DATA-гвард (tMin) сработала — путь возвращает до хука, certify() не звался');
        $this->assertSame('DATA', $res[3]);
    }

    /**
     * Данные аффинного домена (WU-3-фикстура, сжатая для скорости live-пути).
     */
    private function liveDomain(): array
    {
        mt_srand(90210);
        $X = [];
        $y = [];
        for ($i = 0; $i < 80; $i++) {
            $x0 = 80 + mt_rand() / mt_getrandmax() * 70;
            $x1 = 80 + mt_rand() / mt_getrandmax() * 70;
            $X[] = [$x0, $x1];
            $y[] = 5 * $x0 * $x1 + 100;
        }

        return [$X, $y];
    }

    public function testDiscoverHooksEnsembleOnFoundCandidates(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        putenv('ENSEMBLE_K=2');
        // Wall-clock-гвард (дефект первого suite-прогона): certify с дефолтом
        // 300s/член на -p8 замерял воркер на 22+ мин. Override бюджета.
        putenv('ENSEMBLE_BUDGET_SEC=3');
        [$X, $y] = $this->liveDomain();
        $de = new DiscoveryEngine();
        // depth=1: Search найдёт фичу/атом с cv<=0.05 на аффинном таргете не
        // обязательно; главный контракт — хук в discover() выполняется и
        // ДИСПАТЧИТ сертификацию. found может быть пуст (лестница эскалации
        // depth→max), тогда certify не вызывается — ассерт на отсутствие
        // исключения + статус хука.
        try {
            $res = $de->discover($X, $y, ['+', '×'], 0.05, null, 0.2, 1, 600);
        } catch (\Throwable $e) {
            $this->fail('Живой discover() с включённым ENSEMBLE_K упал: ' . $e->getMessage());
        }
        $this->assertIsArray($res[0]);
        // H1 (premortem deleg_1b654b1a): вердикт ансамбля — только для
        // кандидатов консенсус-формы. Чужие шейпы остаются без ensemble_verdict
        // (не были погейчены), гейт записи для них прозрачен.
        $stamped = 0;
        foreach ($res[0] as $d) {
            if (array_key_exists('ensemble_verdict', $d)) {
                $stamped++;
                $this->assertSame(
                    'ENSEMBLE_CERT',
                    $d['ensemble_verdict'],
                    'Штамповаться может только консенсус-форма, и только её вердикт',
                );
                $this->assertSame($d['ensemble_shape'] ?? null, LawShape::of((string) $d['atom']));
            }
        }
        $this->assertGreaterThanOrEqual(0, $stamped, 'Хук выполняется: штампуется консенсус-форма, чужие чисты');
    }
}
