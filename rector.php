<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php70\Rector\StmtsAwareInterface\IfIssetToCoalescingRector;
use Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector;
use Rector\Php71\Rector\TryCatch\MultiExceptionCatchRector;
use Rector\Php73\Rector\String_\SensitiveHereNowDocRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Rector\Php80\Rector\FuncCall\ClassOnObjectRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use RectorLaravel\Rector\Class_\DescriptionPropertyToDescriptionAttributeRector;
use RectorLaravel\Rector\Class_\SignaturePropertyToSignatureAttributeRector;
use RectorLaravel\Rector\Class_\TablePropertyToTableAttributeRector;
use RectorLaravel\Rector\Class_\TimeoutPropertyToTimeoutAttributeRector;
use RectorLaravel\Rector\Class_\TriesPropertyToTriesAttributeRector;
use RectorLaravel\Rector\Class_\UniqueForPropertyToUniqueForAttributeRector;
use RectorLaravel\Rector\Class_\WithoutIncrementingPropertyToWithoutIncrementingAttributeRector;
use RectorLaravel\Rector\Class_\WithoutTimestampsPropertyToWithoutTimestampsAttributeRector;
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
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
        FunctionFirstClassCallableRector::class,
        SeparateMultiUseImportsRector::class,
        StringClassNameToClassConstantRector::class,
        IfIssetToCoalescingRector::class,
        TernaryToNullCoalescingRector::class,
        MultiExceptionCatchRector::class,
        SensitiveHereNowDocRector::class,
        ClosureToArrowFunctionRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        StringableForToStringRector::class,
        ClassOnObjectRector::class,
        ArrayToFirstClassCallableRector::class,
        NullToStrictStringFuncCallArgRector::class,
        ReadOnlyPropertyRector::class,
        ReadOnlyClassRector::class,
        AddTypeToConstRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
        AddClosureVoidReturnTypeWhereNoReturnRector::class,
        DescriptionPropertyToDescriptionAttributeRector::class,
        SignaturePropertyToSignatureAttributeRector::class,
        TablePropertyToTableAttributeRector::class,
        TimeoutPropertyToTimeoutAttributeRector::class,
        TriesPropertyToTriesAttributeRector::class,
        UniqueForPropertyToUniqueForAttributeRector::class,
        WithoutIncrementingPropertyToWithoutIncrementingAttributeRector::class,
        WithoutTimestampsPropertyToWithoutTimestampsAttributeRector::class,
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
