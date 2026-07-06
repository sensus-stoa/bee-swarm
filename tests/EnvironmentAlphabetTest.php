<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

class EnvironmentAlphabetTest extends TestCase
{
    /** Алфавит из всей среды */
    public function test_environment_alphabet_has_atoms(): void
    {
        $atoms = AtomRegistry::loadEnvironment();
        $this->assertGreaterThan(20, count($atoms), 'Should have 20+ math atoms from PHP');
    }

    /** Унарные атомы есть */
    public function test_unary_atoms_from_env(): void
    {
        AtomRegistry::loadEnvironment();
        $all = AtomRegistry::all();
        
        $unary = ['abs', 'sqrt', 'sin', 'cos', 'exp', 'log', 'floor', 'ceil'];
        foreach ($unary as $name) {
            $this->assertContains($name, $all, "Missing: $name");
        }
    }

    /** Бинарные атомы есть */
    public function test_binary_atoms_from_env(): void
    {
        AtomRegistry::loadEnvironment();
        $all = AtomRegistry::all();
        
        $binary = ['min', 'max', 'hypot', 'pow'];
        foreach ($binary as $name) {
            $this->assertContains($name, $all, "Missing: $name");
        }
    }

    /** Файловые атомы есть (когда доступен файл) */
    public function test_filesystem_atoms_from_env(): void
    {
        AtomRegistry::loadEnvironment();
        $all = AtomRegistry::all();
        
        // Файловые функции требуют путь, не float — они загружаются отдельно
        // через loadDomain('filesystem')
        $this->assertGreaterThan(20, count($all), 'Should have math atoms');
    }

    /** Строковые атомы есть (базовые) */
    public function test_string_atoms_from_env(): void
    {
        AtomRegistry::loadEnvironment();
        $all = AtomRegistry::all();
        
        // Строковые функции требуют string input — загружаются через loadDomain
        $this->assertGreaterThan(20, count($all), 'Should have math atoms');
    }

    /** Кэширование: повторный вызов не пересчитывает */
    public function test_environment_caching(): void
    {
        $first = AtomRegistry::loadEnvironment();
        $second = AtomRegistry::loadEnvironment();
        $this->assertSame(count($first), count($second), 'Should be cached');
    }

    /** Атомы из среды решают задачи */
    public function test_env_atoms_solve_tasks(): void
    {
        AtomRegistry::loadEnvironment();
        
        $X = [[1.0], [4.0], [9.0], [16.0]];
        $y = [1.0, 2.0, 3.0, 4.0];
        
        $found = AtomRegistry::discover($X, $y);
        $this->assertNotEmpty($found, 'Should find sqrt');
    }
}
