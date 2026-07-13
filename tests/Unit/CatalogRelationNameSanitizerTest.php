<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogRelationNameSanitizer;
use Tests\TestCase;

class CatalogRelationNameSanitizerTest extends TestCase
{
    public function test_it_accepts_real_country_names(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        foreach (['Россия', 'США', 'Великобритания', 'Корея Южная', 'Южная Корея', 'Нидерланды', 'Тайвань', 'Армения', 'Исландия', 'Чехословакия', 'Филиппины'] as $name) {
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

    public function test_it_accepts_real_organization_names_and_rejects_metadata_placeholders(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        foreach (['A-1 Pictures', 'J.C.Staff', 'TV Tokyo', 'Пятница'] as $name) {
            $this->assertTrue($sanitizer->isValid('studio', $name), $name);
        }

        foreach (['ничего не найдено', 'не указано', 'отсутствует', 'Рекомендовано!'] as $name) {
            $this->assertFalse($sanitizer->isValid('network', $name), $name);
            $this->assertFalse($sanitizer->isValid('studio', $name), $name);
            $this->assertFalse($sanitizer->isValid('status', $name), $name);
        }
    }

    public function test_it_rejects_serial_titles_and_release_announcements_as_people(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        foreach ([
            '1',
            '>>> Сериал Актер (2024)/Actor',
            'Сериал Фабрика кукол/The Doll Factory (04.12.2023 сериал полностью из 6)',
            'Женский характер (12.03.2026 8 серия из 8)',
        ] as $name) {
            $this->assertFalse($sanitizer->isValid('actor', $name), $name);
            $this->assertFalse($sanitizer->isValid('director', $name), $name);
        }

        foreach (['50 Cent', 'A$AP Ferg', '«Убийца» Майк', 'Ацуко Танака'] as $name) {
            $this->assertTrue($sanitizer->isValid('actor', $name), $name);
        }
    }

    public function test_it_builds_one_cross_script_person_identity_and_prefers_the_russian_label(): void
    {
        $sanitizer = app(CatalogRelationNameSanitizer::class);

        $this->assertSame(
            $sanitizer->canonicalKey('actor', 'Ацуко Танака'),
            $sanitizer->canonicalKey('actor', 'Atsuko Tanaka'),
        );
        $this->assertSame(
            'Ацуко Танака',
            $sanitizer->preferredName('actor', 'Atsuko Tanaka', 'Ацуко Танака'),
        );
        $this->assertSame(
            'Ацуко Танака',
            $sanitizer->preferredName('actor', 'Ацуко Танака', 'Atsuko Tanaka'),
        );
    }
}
