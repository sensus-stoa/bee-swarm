<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;

class SelfCodingTest extends TestCase
{
    private string $modulesDir;
    private string $backupAutoload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modulesDir = sys_get_temp_dir() . '/roe_modules_' . getmypid();
        @mkdir($this->modulesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->modulesDir);
    }

    // ═══ 1. ГЕНЕРАЦИЯ КОДА ═══

    /** Генератор создаёт валидный PHP */
    public function test_generate_produces_valid_php(): void
    {
        $code = $this->generateModule('add', 'ADD', [[1,2,3],[3,4,7],[5,6,11]]);
        $this->assertStringContainsString('<?php', $code);
        // Проверяем что код синтаксически верный
        $tmpFile = $this->modulesDir . '/_check.php';
        file_put_contents($tmpFile, $code);
        $check = shell_exec("php -l $tmpFile 2>&1");
        @unlink($tmpFile);
        $this->assertStringContainsString('No syntax errors', $check);
    }

    /** Сгенерированный код решает задачу */
    public function test_generated_code_solves_task(): void
    {
        $tasks = [
            ['name' => 'ADD', 'data' => [[1,2,3],[3,4,7],[5,6,11]]],
        ];

        foreach ($tasks as $task) {
            $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
            $y = array_column($task['data'], count($task['data'][0]) - 1);

            $found = AtomRegistry::discover($X, $y);
            $this->assertNotEmpty($found, 'Should discover atom for task');

            $atom = $found[0]['atom'];
            $code = $this->generateModule($atom, $task['name'], $task['data']);

            // Выполняем сгенерированный код
            $tmpFile = $this->modulesDir . '/test_module.php';
            file_put_contents($tmpFile, $code);
            $output = shell_exec("timeout 3 php $tmpFile 2>/dev/null");
            @unlink($tmpFile);

            $this->assertNotEmpty($output, 'Generated code should produce output');
            $result = json_decode($output, true);
            $this->assertIsArray($result);
            $this->assertEquals(0.0, $result['cv']);
        }
    }

    // ═══ 2. УСТАНОВКА МОДУЛЯ ═══

    /** Модуль устанавливается в файловую систему */
    public function test_install_module_creates_file(): void
    {
        $atomName = 'add';
        $code = $this->generateModule($atomName, 'ADD', [[1,2,3],[3,4,7]]);

        $installedPath = $this->installModule($atomName, $code);
        $this->assertFileExists($installedPath);
        $this->assertStringContainsString('<?php', file_get_contents($installedPath));
    }

    /** Установленный модуль проходит валидацию */
    public function test_installed_module_passes_validation(): void
    {
        $code = $this->generateModule('add', 'ADD', [[1,2,3],[3,4,7]]);
        $path = $this->installModule('add', $code);

        // Валидация: запустить модуль — должен вернуть ok=true на своих данных
        $valid = $this->validateModule($path, []);
        $this->assertTrue($valid, 'Installed module should validate');
    }

    // ═══ 3. ОТБОР: НЕРАБОЧИЙ МОДУЛЬ ОТБРАСЫВАЕТСЯ ═══

    /** Модуль с ошибкой не устанавливается */
    public function test_broken_module_not_installed(): void
    {
        $badCode = "<?php\n// broken\n\$x = ;\n";
        $path = $this->installModule('broken', $badCode);
        $this->assertNull($path, 'Broken module should not install');
    }

    // ═══ 4. ПОЛНЫЙ ЦИКЛ: ГОЛОД → ГЕНЕРАЦИЯ → ТЕСТ → УСТАНОВКА ═══

    /** Полный цикл самокодирования */
    public function test_full_self_coding_cycle(): void
    {
        $tasks = [
            ['name' => 'ADD',  'data' => [[1,2,3],[3,4,7]]],
            ['name' => 'SQRT', 'data' => [[1,1],[4,2],[9,3]]],
        ];

        $installed = [];
        foreach ($tasks as $task) {
            $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
            $y = array_column($task['data'], count($task['data'][0]) - 1);

            foreach (AtomRegistry::discover($X, $y) as $found) {
                $code = $this->generateModule($found['atom'], $task['name'], $task['data']);
                $path = $this->installModule($found['atom'], $code);
                if ($path) {
                    $valid = $this->validateModule($path, []);
                    if ($valid) $installed[] = $path;
                }
            }
        }

        $this->assertGreaterThanOrEqual(2, count($installed), 'Should install at least 2 modules');
    }

    // ═══ HELPERS ═══

    private function generateModule(string $atomName, string $taskName, array $data): string
    {
        $nFeat = count($data[0]) - 1;
        $vendorPath = realpath(__DIR__ . '/../vendor/autoload.php');

        $testData = var_export($data, true);
        $testDataStr = str_replace("\n", "\n", $testData);

        return <<<PHP
<?php
// Auto-generated module: {$atomName} for {$taskName}
// Generated: {$this->now()}

require_once '{$vendorPath}';
use BeeSwarm\\AtomRegistry;

\$data = {$testDataStr};
\$nFeat = {$nFeat};
\$X = array_map(fn(\$r) => array_slice(\$r, 0, \$nFeat), \$data);
\$y = array_column(\$data, \$nFeat);

\$vec = [];
foreach (\$X as \$row) {
    \$a = (float)\$row[0];
    \$b = \$nFeat >= 2 ? (float)\$row[1] : null;
    \$v = AtomRegistry::apply('{$atomName}', \$a, \$b);
    if (\$v === null) { echo json_encode(['ok' => false, 'error' => 'apply failed']); exit(1); }
    \$vec[] = \$v;
}

\$cv = AtomRegistry::cv(\$vec, \$y);
echo json_encode(['ok' => \$cv < 0.01, 'cv' => \$cv, 'atom' => '{$atomName}']);
PHP;
    }

    private function installModule(string $name, string $code): ?string
    {
        $path = $this->modulesDir . '/' . $name . '.php';

        // Проверка синтаксиса
        $tmpFile = $this->modulesDir . '/_tmp.php';
        file_put_contents($tmpFile, $code);
        $syntaxCheck = shell_exec("php -l $tmpFile 2>&1");
        if (!str_contains($syntaxCheck, 'No syntax errors')) {
            @unlink($tmpFile);
            return null;
        }
        @unlink($tmpFile);

        file_put_contents($path, $code);
        return $path;
    }

    private function validateModule(string $path, array $testData): bool
    {
        // Модуль уже содержит свои обучающие данные. 
        // Для теста просто запускаем — он должен отработать без ошибок.
        $output = shell_exec("timeout 3 php $path 2>/dev/null");
        if (!$output) return false;
        $result = json_decode($output, true);
        return ($result['ok'] ?? false) === true;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
