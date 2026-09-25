<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\CongruenceClassReport;

/**
 * V0.15 WU-5: аудит-вертка — секция «Equivalence class» в отчёте.
 * Прецедент: AUDIT_REPORT_TEMPLATE в репо нет → секция = рендер строк
 * laws с class_id (эксперимент-лог/CLI-отчёт, не новый pipeline).
 */
class CongruenceClassReportTest extends TestCase
{
    public function testRendersEquivalenceClassSection(): void
    {
        $rows = [[
            'formula' => '((x0+x1)×(x0+x1))',
            'domain' => 'domC',
            'class_id' => '((x0+x1)×(x0+x1))',
            'class_domain_json' => json_encode([
                'members' => ['((x0+x1)×(x0+x1))', '(((x0×x0)+(x0×x1))+(x0×x1))'],
                'domain' => [
                    '0' => [0.0, 39.9],
                    '1' => [0.0, 39.4],
                ],
                'split_at' => null,
            ]),
        ]];
        $out = CongruenceClassReport::render($rows);
        $this->assertStringContainsString('Equivalence class:', $out);
        $this->assertStringContainsString('2 formulas', $out, 'число членов класса');
        $this->assertStringContainsString('domain [0..39.9] x [0..39.4]', $out, 'историческая граница D');
        $this->assertStringContainsString('split point: none', $out);
    }

    public function testRendersSplitSection(): void
    {
        $rows = [[
            'formula' => 'x0',
            'domain' => 'domS',
            'class_id' => 'x0',
            'class_domain_json' => json_encode([
                'members' => ['x0', '(0maxx0)'],
                'domain' => [
                    '0' => [-39.0, 40.0],
                ],
                'split_at' => [
                    'index' => 3,
                ],
            ]),
        ]];
        $out = CongruenceClassReport::render($rows);
        $this->assertStringContainsString('split point: #3', $out);
    }

    public function testSkipsLawsWithoutClass(): void
    {
        $rows = [[
            'formula' => '(x0×x1)',
            'domain' => 'domD',
            'class_id' => null,
            'class_domain_json' => null,
        ]];
        $this->assertSame('', CongruenceClassReport::render($rows));
    }
}
