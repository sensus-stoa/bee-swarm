<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\FormInvarianceVerify;
use PHPUnit\Framework\TestCase;

/**
 * S2.2-TER: verify_2_2b unit tests - the measurement class contract.
 *
 * The full run is E2E-expensive (Search on 6 domains, ~2 min); unit tests
 * cover the GUARD (destructive-DB refusal, RED-testable) and the report
 * shape. The PASS verdict itself is verified by the CLI run (10.10,
 * progress.md) - one full E2E per story, not per test.
 */
class FormInvarianceVerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FormInvarianceVerify::reset();
    }

    protected function tearDown(): void
    {
        putenv('V22B_DESTRUCTIVE_OK');
        putenv('SWARM_DB_PATH=:memory:');
        FormInvarianceVerify::reset();
        parent::tearDown();
    }

    /**
     * Guard: a persistent DB path without opt-in must be REFUSED
     * (the run deletes the birth dictionary - same HIGH contract as V28).
     */
    public function testPersistentDbRefusedWithoutOptIn(): void
    {
        putenv('SWARM_DB_PATH=/tmp/fake_persistent_v22b.sqlite');
        putenv('V22B_DESTRUCTIVE_OK');
        $this->expectException(\RuntimeException::class);
        FormInvarianceVerify::report();
    }

    /**
     * Guard opt-in: explicit env override passes the guard.
     */
    public function testPersistentDbAllowedWithOptIn(): void
    {
        // Fake path never touched (the class only reads getenv here);
        // opt-in must not throw.
        putenv('SWARM_DB_PATH=/tmp/fake_persistent_v22b.sqlite');
        putenv('V22B_DESTRUCTIVE_OK=1');
        // Do NOT run the full report (would wipe a real DB if the path
        // existed) - guard logic is verified by the absence of the
        // RuntimeException path; exercise via reflection on the guard.
        $m = new \ReflectionMethod(FormInvarianceVerify::class, 'assertDatabaseIsDisposable');
        $m->setAccessible(true);
        $m->invoke(null);
        $this->assertTrue(true); // no exception = guard passed
    }

    /**
     * Report shape: pass/invariants/power/flux/fpr/env/pending keys.
     */
    public function testReportShape(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        $r = FormInvarianceVerify::report();
        foreach (['pass', 'invariants', 'power', 'flux', 'fpr', 'env', 'pending'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertIsBool($r['pass']);
        $this->assertIsInt($r['invariants']);
        // env facts are logged, not assumed (canonical env-header rule)
        $this->assertSame(':memory:', $r['env']['SWARM_DB_PATH']);
    }
}
