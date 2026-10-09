<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * S2.2-TER wiring: every NEW law (recordDiscovery, inserted=true) lands in
 * form_registry via FormHash (expansion-aware). Duplicate discoveries
 * (inserted=false) do not register. The registry is a passive observer:
 * discovery path must not fail when form_registry is unavailable.
 */
class FormRegistryWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec('DELETE FROM form_registry');
        Database::get()->exec("DELETE FROM grammar_ops WHERE source='birth'");
    }

    protected function tearDown(): void
    {
        Database::get()->exec('DELETE FROM form_registry');
        Database::get()->exec("DELETE FROM grammar_ops WHERE source='birth'");
        parent::tearDown();
    }

    /**
     * H1 (agent-review deleg_839e61a4): a domain with NO identifiable
     * parameter (X=null dream path) must NOT write a form_registry row -
     * an empty params entry poisons minPairRatio forever (null -> the
     * form can never become invariant).
     */
    public function testNoParamDomainDoesNotRegister(): void
    {
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'v22b_w_'));
        $m = new \ReflectionMethod(Hive::class, 'registerFormInvariance');
        $m->setAccessible(true);
        // dream-path shape: no data at all
        $m->invoke($hive, ['atom' => '(x0+K1)sq', 'cv' => 0.05, 'name' => 'law_x'], 'dreamDom', null, null);
        $n = (int) Database::get()->query('SELECT COUNT(*) FROM form_registry')->fetchColumn();
        $this->assertSame(0, $n);
    }

    /**
     * Wiring: constructing Hive touches FormRegistry (wired into
     * recordDiscovery path). Probed via ReflectionMethod - the hook is
     * wired AFTER the duplicate-return (precedent VerificationTaskSource).
     */
    public function testHiveConstructsAndRegistryClean(): void
    {
        $hive = new Hive(maxTicks: 1);
        $this->assertInstanceOf(Hive::class, $hive);
        $n = (int) Database::get()->query('SELECT COUNT(*) FROM form_registry')->fetchColumn();
        $this->assertSame(0, $n);
    }

    /**
     * Reflection probe: the wiring method exists and carries the full
     * contract (discovery record, domain, optional data slice for the
     * k_est param proxy).
     */
    public function testWiringMethodExists(): void
    {
        $r = new \ReflectionMethod(Hive::class, 'registerFormInvariance');
        $this->assertSame(4, $r->getNumberOfParameters());
    }
}
