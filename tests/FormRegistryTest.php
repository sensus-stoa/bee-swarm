<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\FormRegistry;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * S2.2-TER (§3.2.2): form_registry - the "law about laws".
 *
 * Story criterion (stricter than protocol 3.2.2): a form is registered as
 * 'invariant' only when >=3 DISTINCT domains AND parameters differ >=10x
 * in at least one pair AND cv <= 0.15 in every domain. Related domains
 * (same form, close params) stay 'observed' and never complete the trio.
 * Meta-laws live in form_registry (own status), never in laws.
 */
class FormRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec('DELETE FROM form_registry');
    }

    protected function tearDown(): void
    {
        Database::get()->exec('DELETE FROM form_registry');
        parent::tearDown();
    }

    /**
     * T1: two isomorphic laws from different domains -> one row, 2 domains,
     * status observed (invariant needs 3).
     */
    public function testRegistryPopulatedTwoDomains(): void
    {
        FormRegistry::register('(*)²', 'grav', 4.0, [
            'k_est' => 1.0,
        ], 'law_g1', 'x0²');
        FormRegistry::register('(*)²', 'bio', 5.0, [
            'k_est' => 5.0,
        ], 'law_b1', 'x0²');
        $rows = Database::get()
            ->query('SELECT * FROM form_registry')
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('observed', $rows[0]['status']);
        $domains = json_decode($rows[0]['domains'], true);
        $this->assertSame(['grav', 'bio'], $domains);
    }

    /**
     * T2: same form, parameters within 10% -> NOT invariant (dedup-scale
     * case: the same law in another scale, not a form invariant).
     */
    public function testDistinctParamsRequired(): void
    {
        FormRegistry::register('(*)²', 'd1', 0.05, [
            'k_est' => 10.0,
        ], 'law_1', 'x0²');
        FormRegistry::register('(*)²', 'd2', 0.06, [
            'k_est' => 10.5,
        ], 'law_2', 'x0²');
        FormRegistry::register('(*)²', 'd3', 0.07, [
            'k_est' => 10.9,
        ], 'law_3', 'x0²');
        $status = (string) Database::get()
            ->query('SELECT status FROM form_registry')
            ->fetchColumn();
        $this->assertSame('observed', $status);
    }

    /**
     * T5: 3 distinct domains with params >=10x in one pair AND cv<=0.15
     * -> status='invariant'.
     */
    public function testInvariantAtThreeDomains10x(): void
    {
        FormRegistry::register('(*)²', 'd1', 0.05, [
            'k_est' => 1.0,
        ], 'law_1', 'x0²');
        FormRegistry::register('(*)²', 'd2', 0.06, [
            'k_est' => 5.0,
        ], 'law_2', 'x0²');
        FormRegistry::register('(*)²', 'd3', 0.07, [
            'k_est' => 25.0,
        ], 'law_3', 'x0²');
        $status = (string) Database::get()
            ->query('SELECT status FROM form_registry')
            ->fetchColumn();
        $this->assertSame('invariant', $status);
    }

    /**
     * T6: a WILD third domain (params >=10x vs every existing) completes
     * the invariant - the criterion is ">=10x in at least one pair"
     * (story criterion, conjunctive with cv gate), so related-then-wild
     * sequences legitimately reach invariant.
     */
    public function testWildThirdDomainCompletesInvariant(): void
    {
        FormRegistry::register('(*)²', 'd1', 0.05, [
            'k_est' => 1.0,
        ], 'law_1', 'x0²');
        FormRegistry::register('(*)²', 'd2', 0.06, [
            'k_est' => 1.9,
        ], 'law_2', 'x0²');
        // d1-d2 related (1.9x), d3 wild (25x vs d1) -> one >=10x pair exists
        FormRegistry::register('(*)²', 'd3', 0.07, [
            'k_est' => 25.0,
        ], 'law_3', 'x0²');
        $status = (string) Database::get()
            ->query('SELECT status FROM form_registry')
            ->fetchColumn();
        $this->assertSame('invariant', $status);
    }

    /**
     * T6b: status is monotone - once invariant, a later bad-cv domain does
     * not demote the form (invariance is a discovered fact).
     */
    public function testInvariantStatusMonotone(): void
    {
        FormRegistry::register('(*)²', 'd1', 0.05, [
            'k_est' => 1.0,
        ], 'law_1', 'x0²');
        FormRegistry::register('(*)²', 'd2', 0.06, [
            'k_est' => 5.0,
        ], 'law_2', 'x0²');
        FormRegistry::register('(*)²', 'd3', 0.07, [
            'k_est' => 25.0,
        ], 'law_3', 'x0²');
        FormRegistry::register('(*)²', 'd4', 0.40, [
            'k_est' => 50.0,
        ], 'law_4', 'x0²');
        $status = (string) Database::get()
            ->query('SELECT status FROM form_registry')
            ->fetchColumn();
        $this->assertSame('invariant', $status);
    }

    /**
     * T7: cv gate - one domain above 0.15 blocks invariant even at >=3
     * domains and >=10x params.
     */
    public function testCvGateBlocksInvariant(): void
    {
        FormRegistry::register('(*)²', 'd1', 0.05, [
            'k_est' => 1.0,
        ], 'law_1', 'x0²');
        FormRegistry::register('(*)²', 'd2', 0.06, [
            'k_est' => 25.0,
        ], 'law_2', 'x0²');
        FormRegistry::register('(*)²', 'd3', 0.40, [
            'k_est' => 50.0,
        ], 'law_3', 'x0²');
        $status = (string) Database::get()
            ->query('SELECT status FROM form_registry')
            ->fetchColumn();
        $this->assertSame('observed', $status);
    }

    /**
     * T8: dedup - same (form, domain) pair updates the row, no duplicate
     * domains inflate the count.
     */
    public function testDedupSameDomain(): void
    {
        FormRegistry::register('(*)²', 'd1', 0.05, [
            'k_est' => 1.0,
        ], 'law_1', 'x0²');
        FormRegistry::register('(*)²', 'd1', 0.05, [
            'k_est' => 1.0,
        ], 'law_1b', 'x0²');
        $rows = Database::get()
            ->query('SELECT domains FROM form_registry')
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame(['d1'], json_decode($rows[0]['domains'], true));
    }
}
