<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

/**
 * Story D10 Phase 5.2: Structural constraints — nesting ≤2, methods ≤30 lines
 *
 * These tests verify code structure, not runtime behavior.
 * They document the target state: scanDir() decomposed into small methods.
 */
class ScannerStructureTest extends TestCase
{
    private const MAX_METHOD_LINES = 30;

    /**
     * scanDir() must be ≤30 lines — currently 129.
     * RED until decomposed.
     */
    public function testScanDirMethodSize(): void
    {
        $ref = new \ReflectionMethod(\BeeSwarm\Forager\Scanner::class, 'scanDir');
        // Count method body only (opening { to closing }), not docblock
        $source = file($ref->getFileName());
        $bodyStart = $ref->getStartLine(); // first line after docblock (signature)
        // Find opening brace line
        $openBrace = $bodyStart;
        while ($openBrace <= $ref->getEndLine() && ! str_contains($source[$openBrace - 1], '{')) {
            $openBrace++;
        }
        $lines = $ref->getEndLine() - $openBrace;

        $this->assertLessThanOrEqual(
            self::MAX_METHOD_LINES,
            $lines,
            "scanDir() body is {$lines} lines, must be ≤" . self::MAX_METHOD_LINES
        );
    }
}
