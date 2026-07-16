<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    ->withSkip([
        // The first all-project audit found these migrations across 301 files.
        // Keep that review backlog visible in rector-max.php without baselining paths.
        \Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
        \Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector::class,
        \Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector::class,
        \Rector\Php55\Rector\String_\StringClassNameToClassConstantRector::class,
        \Rector\Php70\Rector\StmtsAwareInterface\IfIssetToCoalescingRector::class,
        \Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector::class,
        \Rector\Php71\Rector\TryCatch\MultiExceptionCatchRector::class,
        \Rector\Php73\Rector\String_\SensitiveHereNowDocRector::class,
        \Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector::class,
        \Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class,
        \Rector\Php80\Rector\Class_\StringableForToStringRector::class,
        \Rector\Php80\Rector\FuncCall\ClassOnObjectRector::class,
        \Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector::class,
        \Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class,
        \Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class,
        \Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
        \Rector\Php83\Rector\ClassConst\AddTypeToConstRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
        \Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector::class,
        \RectorLaravel\Rector\Class_\DescriptionPropertyToDescriptionAttributeRector::class,
        \RectorLaravel\Rector\Class_\SignaturePropertyToSignatureAttributeRector::class,
        \RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector::class,
        \RectorLaravel\Rector\Class_\TimeoutPropertyToTimeoutAttributeRector::class,
        \RectorLaravel\Rector\Class_\TriesPropertyToTriesAttributeRector::class,
        \RectorLaravel\Rector\Class_\UniqueForPropertyToUniqueForAttributeRector::class,
        \RectorLaravel\Rector\Class_\WithoutIncrementingPropertyToWithoutIncrementingAttributeRector::class,
        \RectorLaravel\Rector\Class_\WithoutTimestampsPropertyToWithoutTimestampsAttributeRector::class,
        __DIR__.'/vendor',
        __DIR__.'/storage',
        __DIR__.'/bootstrap/cache',
        __DIR__.'/output',
    ])
    ->withCache(
        cacheDirectory: __DIR__.'/output/rector/required',
        cacheClass: FileCacheStorage::class,
    )
    ->withParallel(timeoutSeconds: 600, maxNumberOfProcess: 4, jobSize: 20)
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(phpunit: true, laravel: true)
    ->withPhpSets()
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withCodingStyleLevel(0);
