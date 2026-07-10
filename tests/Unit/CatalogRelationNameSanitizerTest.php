<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogRelationNameSanitizer;
use Tests\TestCase;

class CatalogRelationNameSanitizerTest extends TestCase
{
    public function test_it_accepts_real_country_names(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        foreach (['Россия', 'США', 'Великобритания', 'Корея Южная', 'Южная Корея', 'Нидерланды'] as $name) {
            $this->assertTrue($sanitizer->isValid('country', $name), $name);
        }
    }

    public function test_it_rejects_values_that_are_not_country_names(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        foreach (['Москва', 'Лондон', 'LostFilm', 'комедия', 'финал сезона', 'Главные герои сериала'] as $name) {
            $this->assertFalse($sanitizer->isValid('country', $name), $name);
        }
    }

    public function test_it_accepts_real_translation_names(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        $this->assertTrue($sanitizer->isValid('translation', 'LostFilm'));
        $this->assertTrue($sanitizer->isValid('translation', '2x2'));
        $this->assertTrue($sanitizer->isValid('translation', 'Пифагор, Субтитры'));
    }

    public function test_it_rejects_values_that_are_not_translation_names(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        foreach (['2007', '2020', 'США', 'версия США', 'финал сезона', 'рус.', '1 серия из 10'] as $name) {
            $this->assertFalse($sanitizer->isValid('translation', $name), $name);
        }
    }
}
