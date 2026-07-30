<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

final class ModelFactoryCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_concrete_first_party_model_has_a_factory_contract(): void
    {
        $missingFactories = [];
        $missingTraits = [];

        foreach (self::modelClasses() as $label => [$modelClass]) {
            $factoryClass = 'Database\\Factories\\'.class_basename($modelClass).'Factory';

            if (! class_exists($factoryClass)) {
                $missingFactories[] = $label;
            }

            if (! in_array(HasFactory::class, class_uses_recursive($modelClass), true)) {
                $missingTraits[] = $label;
            }
        }

        self::assertSame([], $missingFactories, 'Models without factories: '.implode(', ', $missingFactories));
        self::assertSame([], $missingTraits, 'Models without HasFactory: '.implode(', ', $missingTraits));
    }

    /**
     * @param class-string<Model> $modelClass
     */
    #[DataProvider('modelClasses')]
    public function test_each_model_factory_creates_a_valid_record(string $modelClass): void
    {
        Http::preventStrayRequests();

        $model = $modelClass::factory()->create();

        self::assertInstanceOf($modelClass, $model);
        self::assertTrue($model->exists);
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function modelClasses(): array
    {
        $models = [];
        $root = dirname(__DIR__, 2);

        foreach (glob($root.'/app/Models/*.php') ?: [] as $path) {
            $modelClass = 'App\\Models\\'.pathinfo($path, PATHINFO_FILENAME);

            if (! class_exists($modelClass)) {
                continue;
            }

            $reflection = new ReflectionClass($modelClass);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            $models[class_basename($modelClass)] = [$modelClass];
        }

        ksort($models);

        return $models;
    }
}
