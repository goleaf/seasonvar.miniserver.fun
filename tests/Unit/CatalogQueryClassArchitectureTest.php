<?php

declare(strict_types=1);

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Tests\TestCase;

final class CatalogQueryClassArchitectureTest extends TestCase
{
    public function test_catalog_query_classes_are_final_readonly_and_expose_one_handle_method(): void
    {
        $directory = app_path('Services/Catalog/Queries');

        $this->assertDirectoryExists($directory);
        $classes = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)))
            ->filter(fn (mixed $file): bool => $file instanceof SplFileInfo
                && $file->isFile()
                && $file->getExtension() === 'php')
            ->map(fn (SplFileInfo $file): string => 'App\\Services\\Catalog\\Queries\\'.$file->getBasename('.php'))
            ->values();

        $this->assertSame(3, $classes->count());

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
                ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class
                    && $method->getName() !== '__construct')
                ->pluck('name')
                ->values()
                ->all();

            $this->assertTrue($reflection->isFinal(), "{$class} must be final.");
            $this->assertTrue($reflection->isReadOnly(), "{$class} must be readonly.");
            $this->assertSame(['handle'], $publicMethods, "{$class} must expose exactly one public handle() method.");
        }
    }
}
