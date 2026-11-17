<?php
declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    // USER (23.09): «нам и не нужен ecs в тестах» — ecs только на рабочий код.
    // Побочный эффект (23.09): GeneralPhpdocAnnotationRemove счищал хвостовой
    // @group slow из class-docblock -> тесты тихо уезжали в fast (b89a5d9-питфолл);
    // на tests больше не действует.
    ->withPaths([__DIR__ . '/src', __DIR__ . '/agenda.php', __DIR__ . '/public'])
    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/tests',
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
        // 23.09: @group slow — исполняемая аннотация phpunit (гейт -p8),
        // GeneralPhpdocAnnotationRemove счищает хвостовой тег -> тесты тихо
        // уезжали в fast (b89a5d9-питфолл). Аналог in-code: standalone
        // docblock ecs резал, внутри общего — тоже режет этой версией.
        \PhpCsFixer\Fixer\Phpdoc\GeneralPhpdocAnnotationRemoveFixer::class,
    ]);
