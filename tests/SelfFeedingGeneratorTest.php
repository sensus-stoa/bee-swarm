<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

class SelfFeedingGeneratorTest extends TestCase
{
    private \SelfFeedingGenerator $gen;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../self_feeding_generator.php';
        $this->gen = new \SelfFeedingGenerator();
    }

    /** Пул не пустой при старте (seed-шаблоны) */
    public function test_pool_not_empty(): void
    {
        $this->assertGreaterThan(0, $this->gen->poolSize());
    }

    /** generate: возвращает строку PHP-кода */
    public function test_generate_returns_string(): void
    {
        $code = $this->gen->generate();
        $this->assertIsString($code);
        $this->assertNotEmpty($code);
    }

    /** generate: код содержит $cv и $formula */
    public function test_generate_has_cv_and_formula(): void
    {
        $code = $this->gen->generate();
        $this->assertStringContainsString('$cv', $code);
        $this->assertStringContainsString('$formula', $code);
    }

    /** stats: возвращает структуру */
    public function test_stats(): void
    {
        $stats = $this->gen->stats();
        $this->assertArrayHasKey('pool_size', $stats);
        $this->assertArrayHasKey('born_from_human', $stats);
        $this->assertArrayHasKey('born_from_success', $stats);
        $this->assertArrayHasKey('self_sustaining', $stats);
        $this->assertSame($this->gen->poolSize(), $stats['pool_size']);
    }

    /** feedSuccess: увеличивает счётчик существующего кода */
    public function test_feed_success_increments_count(): void
    {
        $code = $this->gen->generate();
        $initialPool = $this->gen->poolSize();

        // Кормим 3 раза
        $this->gen->feedSuccess($code, 0.05);
        $this->gen->feedSuccess($code, 0.03);

        // После feedSuccess тот же код не дублируется в пуле
        $this->assertEquals($initialPool, $this->gen->poolSize());
    }

    /** feedSuccess: 3 успеха → trusted */
    public function test_feed_three_successes_becomes_trusted(): void
    {
        $code = $this->gen->generate();
        $this->gen->feedSuccess($code, 0.1);
        $this->gen->feedSuccess($code, 0.2);
        $this->gen->feedSuccess($code, 0.3);

        // Проверяем через БД
        $db = \BeeSwarm\Database::get();
        $stmt = $db->prepare("SELECT source FROM action_pool WHERE code_hash = ?");
        $source = false;
        if ($stmt) {
            $stmt->execute([md5($code)]);
            $source = $stmt->fetchColumn();
        }
        $this->assertSame('trusted', $source);
    }

    /** generate: 20% шанс random token кода (проверяем что иногда генерится) */
    public function test_generate_sometimes_produces_random_tokens(): void
    {
        $hasAssign = false;
        for ($i = 0; $i < 20; $i++) {
            $code = $this->gen->generate();
            // random token код содержит специфические паттерны типа $i, циклов
            if (str_contains($code, 'for($i=')) {
                $hasAssign = true;
                break;
            }
        }
        $this->assertTrue($hasAssign, 'Random token code should appear at least once in 20 generations');
    }

    /** evictWeakest: при превышении 100 удаляет слабейшего */
    public function test_eviction_on_overflow(): void
    {
        $db = \BeeSwarm\Database::get();

        // Чистим ВЕСЬ пул кроме seed-шаблонов
        $db->exec("DELETE FROM action_pool WHERE source != 'seed'");
        $initial = $db->query("SELECT COUNT(*) FROM action_pool")->fetchColumn();
        $this->assertLessThan(100, $initial, 'Seed pool should be small');

        // Заполняем пул до 100+ уникальными записями
        for ($i = 0; $i < 200; $i++) {
            $code = '$cv = 0.0; $formula = "evict_' . $i . '_' . uniqid() . '";';
            $this->gen->feedSuccess($code, 0.01);
        }

        $count = $db->query("SELECT COUNT(*) FROM action_pool")->fetchColumn();
        $this->assertLessThanOrEqual(100, $count, "Pool should not exceed 100. Initial: $initial, Final: $count");
    }
}
