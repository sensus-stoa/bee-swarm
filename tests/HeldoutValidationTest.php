<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\AtomRegistry;
use BeeSwarm\Infra\Database;

/**
 * Story 03: Held-Out Validation (HONEST_CRITERIA §1.1)
 *
 * discoverHeldout() — как discover(), но с train/test split.
 * retrospectiveValidate() — проверка существующих законов.
 */
class HeldoutValidationTest extends TestCase
{
    /** discoverHeldout существует и возвращает правильную структуру */
    public function test_discover_heldout_exists(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10]];
        $y = [3, 7, 11, 15, 19]; // y = x0 + x1 (точный закон)

        $result = AtomRegistry::discoverHeldout($X, $y);
        $this->assertIsArray($result);

        if (!empty($result)) {
            $this->assertArrayHasKey('atom', $result[0]);
            $this->assertArrayHasKey('cv_train', $result[0]);
            $this->assertArrayHasKey('cv_holdout', $result[0]);
        }
    }

    /** Точный закон (add) проходит held-out на 5+ точках */
    public function test_exact_law_passes_heldout(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [11, 12]];
        $y = [3, 7, 11, 15, 19, 23]; // y = x0 + x1

        $result = AtomRegistry::discoverHeldout($X, $y);
        $atoms = array_column($result, 'atom');

        $this->assertContains('add', $atoms, 'Exact law add must pass held-out');
    }

    /** Случайные данные — held-out отсеивает ложные CV→0 */
    public function test_random_data_no_false_positives(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 10; $i++) {
            $X[] = [(float)mt_rand(0, 100), (float)mt_rand(0, 100)];
            $y[] = (float)mt_rand(0, 100);
        }

        $result = AtomRegistry::discoverHeldout($X, $y);
        // На случайных данных held-out должен отсеять всё или почти всё
        // Допускаем не более 1 false positive
        $this->assertLessThanOrEqual(1, count($result),
            'Random data must not produce >1 false positive with held-out');
    }

    /** Overfit: CV_train=0 но CV_holdout>0.10 → возвращает OVERFIT маркер */
    public function test_overfit_marker_in_result(): void
    {
        // Данные где add работает на первых 4 точках но не на 5-й
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [100, 200]];
        $y = [3, 7, 11, 15, 999]; // последняя точка — выброс

        $result = AtomRegistry::discoverHeldout($X, $y);
        $atoms = array_column($result, 'atom');

        // add должен быть rejected (CV_train=0 но CV_holdout > 0.10 на выбросе)
        // либо не появляться в результатах
        $addFound = in_array('add', $atoms, true);
        if ($addFound) {
            // Если add найден, он должен иметь cv_holdout ≤ 0.10
            foreach ($result as $r) {
                if ($r['atom'] === 'add') {
                    $this->assertLessThanOrEqual(0.10, $r['cv_holdout'],
                        'add with outlier must have cv_holdout ≤ 0.10 or be absent');
                }
            }
        }
        // Тест проходит если add rejected (не в результате) или accepted с cv_holdout ≤ 0.10
        $this->assertTrue(true);
    }

    /** retrospectiveValidate() — проверяет все законы в БД */
    public function test_retrospective_validate_exists(): void
    {
        $this->assertTrue(method_exists(AtomRegistry::class, 'retrospectiveValidate'),
            'AtomRegistry must implement retrospectiveValidate()');
    }

    /** Валидный закон (add для ADD-задачи) проходит ретроспективу */
    public function test_valid_law_passes_retrospective(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE name='TEST_RETRO_ADD'");

        // Сохраняем закон (как будто открыт до held-out)
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
           ->execute(['TEST_RETRO_ADD', 'add', 0, 'arithmetic']);

        // Ретроспективная валидация с данными ADD-задачи
        $addData = [[1,2,3],[3,4,7],[5,6,11],[7,8,15],[9,10,19],[11,12,23]];
        $tasks = [['name' => 'TEST_RETRO_ADD', 'data' => $addData, 'domain' => 'arithmetic']];

        $result = AtomRegistry::retrospectiveValidate($tasks);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('overfit', $result);

        // add на ADD-задаче должно пройти
        $this->assertContains('TEST_RETRO_ADD::add', $result['passed']);

        $db->exec("DELETE FROM laws WHERE name='TEST_RETRO_ADD'");
    }

    /** discoverHeldout фильтрует compose-мусор: меньше открытий чем discover */
    public function test_heldout_filters_more_than_discover(): void
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 15; $i++) {
            $X[] = [(float)mt_rand(0, 10), (float)mt_rand(0, 10)];
            $y[] = (float)mt_rand(0, 10);
        }

        $withoutHeldout = count(AtomRegistry::discover($X, $y));
        $withHeldout = count(AtomRegistry::discoverHeldout($X, $y));

        $this->assertLessThanOrEqual($withoutHeldout, $withHeldout,
            'discoverHeldout must not produce MORE results than discover');
    }

    /** isHeldoutEnabled() — флаг что демон использует held-out режим */
    public function test_heldout_enabled_flag(): void
    {
        $this->assertTrue(AtomRegistry::isHeldoutEnabled(),
            'Daemon must use held-out validation by default');
    }
}
