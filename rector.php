<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrowFunctionParamArrayWhereDimFetchRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/create-phar.php'])
    // Bitbucket can return a null `user` for a default reviewer; keep that tolerated.
    ->withSkip([AddArrowFunctionParamArrayWhereDimFetchRector::class => [__DIR__ . '/src/Actions/Pr.php']])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, earlyReturn: true, typeDeclarations: true);
