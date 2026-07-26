<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Collections\Quality\CatalogCollectionQualityText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogCollectionQualityTextTest extends TestCase
{
    #[Test]
    public function normalization_is_unicode_safe_and_ignores_formatting_noise(): void
    {
        $text = new CatalogCollectionQualityText;

        self::assertSame(
            'лучшие корейские дорамы',
            $text->normalize('  ЛУЧШИЕ корейские — дорамы!!!  '),
        );
        self::assertSame(
            $text->textHash('Лучшие корейские дорамы', 'Подборка детективов'),
            $text->textHash(' лучшие  КОРЕЙСКИЕ дорамы ', 'Подборка — детективов!'),
        );
    }

    #[Test]
    public function content_signature_is_stable_for_order_and_rejects_duplicate_noise(): void
    {
        $text = new CatalogCollectionQualityText;

        self::assertSame(
            $text->contentSignature([9, 2, 5, 2]),
            $text->contentSignature([5, 9, 2]),
        );
        self::assertNotSame(
            $text->contentSignature([2, 5, 9]),
            $text->contentSignature([2, 5, 10]),
        );
    }

    #[Test]
    public function token_similarity_finds_templates_without_treating_different_topics_as_duplicates(): void
    {
        $text = new CatalogCollectionQualityText;

        self::assertGreaterThanOrEqual(
            0.80,
            $text->similarity(
                'Лучшие корейские детективные сериалы с высоким рейтингом',
                'Лучшие корейские сериалы-детективы с высоким рейтингом',
            ),
        );
        self::assertLessThan(
            0.40,
            $text->similarity(
                'Лучшие корейские детективные сериалы',
                'Документальные фильмы о космосе и науке',
            ),
        );
    }
}
