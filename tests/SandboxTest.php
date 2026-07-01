<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

class SandboxTest extends TestCase
{
    private \Sandbox $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../sandbox.php';
        $this->sandbox = new \Sandbox();
    }

    /** Базовый запуск: код находит формулу */
    public function test_run_simple_formula(): void
    {
        $code = '$ops=["+"];$x=array_column($data,0);$y=array_column($data,1);$vec=[];for($i=0;$i<count($y);$i++)$vec[]=$x[$i]+$data[$i][1];$cv=0.0;$formula="(x0+x1)";';
        $r = $this->sandbox->run($code, [[1, 2, 3], [2, 3, 5], [3, 4, 7]]);
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(0.0, $r['cv'], 0.01);
    }

    /** Код с ошибкой: не падает, возвращает cv=9.99 */
    public function test_run_error_code(): void
    {
        $code = '$x = undefined_function();';
        $r = $this->sandbox->run($code, [[1, 2]]);
        $this->assertFalse($r['ok']);
        $this->assertEqualsWithDelta(9.99, $r['cv'], 0.01);
    }

    /** Валидация: формула (x0+x1) на реальных данных ADD */
    public function test_validate_formula_add(): void
    {
        $code = '$cv = 0.0; $formula = "(x0+x1)";';
        $r = $this->sandbox->run($code, [[1, 2, 3], [2, 3, 5], [3, 4, 7]]);
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(0.0, $r['cv'], 0.01);
    }

    /** Валидация: формула (x0×x1) на данных MUL */
    public function test_validate_formula_mul(): void
    {
        $code = '$cv = 0.0; $formula = "(x0×x1)";';
        $r = $this->sandbox->run($code, [[1, 2, 2], [2, 3, 6], [3, 4, 12]]);
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(0.0, $r['cv'], 0.01);
    }

    /** Валидация: код врёт про CV → независимая проверка штрафует */
    public function test_validate_catches_cv_lie(): void
    {
        // Код утверждает CV=0 и формулу (x0×x1), но данные для ADD
        $code = '$cv = 0.0; $formula = "(x0×x1)";';
        $r = $this->sandbox->run($code, [[1, 2, 3], [2, 3, 5], [3, 4, 7]]);
        // Независимая валидация: (x0×x1) != y=[3,5,7] → CV > 0
        $this->assertGreaterThan(0.0, $r['cv']);
    }

    /** Шаблон-обманщик: report_* должен штрафоваться */
    public function test_report_template_penalized(): void
    {
        $code = '$cv = 0.0; $formula = "report_test_001";';
        $r = $this->sandbox->run($code, [[1, 2, 3], [2, 3, 5]]);
        $this->assertFalse($r['ok']);
        $this->assertEqualsWithDelta(9.99, $r['cv'], 0.01);
    }

    /** Шаблон-обманщик: api_* должен штрафоваться */
    public function test_api_template_penalized(): void
    {
        $code = '$cv = 0.0; $formula = "api_laws_57";';
        $r = $this->sandbox->run($code, [[1, 2, 3]]);
        $this->assertFalse($r['ok']);
        $this->assertEqualsWithDelta(9.99, $r['cv'], 0.01);
    }

    /** Константа K на константных данных — ок */
    public function test_constant_on_constant_data(): void
    {
        $code = '$cv = 0.0; $formula = "K5";';
        $r = $this->sandbox->run($code, [[5, 5, 5], [5, 5, 5], [5, 5, 5]]);
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(0.0, $r['cv'], 0.01);
    }

    /** Константа K на НЕконстантных данных → штраф */
    public function test_constant_on_variable_data(): void
    {
        $code = '$cv = 0.0; $formula = "K5";';
        $r = $this->sandbox->run($code, [[1, 2, 3], [2, 3, 5], [3, 4, 7]]);
        // K5 не равно y=[3,5,7] → валидация зафиксирует CV > 0
        $this->assertGreaterThan(0.0, $r['cv']);
    }

    /** UNTRUSTED: curl_exec запрещён → код с curl фейлится */
    public function test_untrusted_blocks_curl(): void
    {
        $code = '$ch = curl_init("http://127.0.0.1:8765/status"); $r = curl_exec($ch); $cv = $r ? 0.0 : 9.99; $formula = "curl_test";';
        $r = $this->sandbox->run($code, [[1, 2, 3]], false);
        $this->assertFalse($r['ok']);
    }

    /** TRUSTED: curl_exec разрешён */
    public function test_trusted_allows_curl(): void
    {
        // Проверяем что curl не падает с fatal error (она была бы при disabled_function)
        $code = '$cv=9.99;$formula=null;$ch=curl_init("http://127.0.0.1:8765/status");curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_TIMEOUT,1);$r=curl_exec($ch);curl_close($ch);if($r){$cv=0.0;$formula="ok";}';
        $r = $this->sandbox->run($code, [[1, 2, 3]], true);
        // В trusted режиме curl_init/curl_exec не должны быть disabled
        $this->assertNotSame('Call to undefined function curl_init()', $r['error'] ?? '');
        $this->assertNotSame('Call to undefined function curl_exec()', $r['error'] ?? '');
        // Если RR работает — CV=0, если нет — 9.99. Оба варианта норм.
        $this->assertNotNull($r);
    }
}
