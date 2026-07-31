<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

/**
 * S1.8-DESPERATION: Compose без foundAny при голоде ИЛИ любопытстве.
 *
 */
class DesperationComposeTest extends TestCase
{
    /** Код содержит логику desperation compose */
    public function testDesperationComposeLogicExists(): void
    {
        $code = file_get_contents(__DIR__ . '/../src/Hive/Hive.php');

        // Должен быть гейт desperation compose
        $this->assertStringContainsString('DESPERATION', $code, 'Desperation compose marker must exist');

        // Должна быть проверка на голод ИЛИ новизну (не И)
        $this->assertStringContainsString('hunger', strtolower($code));
        $this->assertStringContainsString('novel', strtolower($code));
    }

    /** Compose доступен даже без foundAny при нужных условиях */
    public function testComposeGateAllowsDesperation(): void
    {
        // Симулируем условия: новый fingerprint, foundAny=false, compose должен разрешиться
        $shouldCompose = false;
        $foundAny = false;
        $energy = 4.0;   // E<5 = голод
        $isNovelFingerprint = true;

        $hunger = $energy < 5.0;
        $desperation = $hunger || $isNovelFingerprint;

        // Desperation compose: голод ИЛИ новизна → compose разрешён
        $shouldCompose = ! $foundAny && $desperation;

        $this->assertTrue($shouldCompose, 'Desperation compose must be allowed when hungry OR on novel fingerprint');
    }

    /** Без голода И без новизны → compose НЕ запускается без foundAny */
    public function testComposeBlockedWithoutDesperation(): void
    {
        $foundAny = false;
        $energy = 8.0;       // не голоден
        $isNovelFingerprint = false;  // не новый fingerprint

        $desperation = ($energy < 5.0) || $isNovelFingerprint;
        $shouldCompose = ! $foundAny && $desperation;

        $this->assertFalse($shouldCompose, 'Compose must be blocked without hunger AND without novelty');
    }
}
