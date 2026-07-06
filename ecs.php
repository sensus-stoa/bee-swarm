<?php
declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/agenda.php'])
    ->withSkip([
        __DIR__ . '/tests',
        __DIR__ . '/vendor',
    ])
    // PSR-12 + Common + Clean Code
    ->withPreparedSets(
        psr12: true,
        common: true,
        cleanCode: true,
    )
    // BeeSwarm-specific: allow strict types, keep blank lines
    ->withSkip([
        \PhpCsFixer\Fixer\PhpTag\BlankLineAfterOpeningTagFixer::class => null,
        \PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer::class => null,
    ]);
