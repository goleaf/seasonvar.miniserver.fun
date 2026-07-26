<?php

declare(strict_types=1);

namespace App\Services\Collections\Quality;

use App\DTOs\CollectionQuality\CatalogCollectionThemeMatch;
use App\Enums\CatalogCollectionInclusionReason;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionType;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogTitle;
use App\Services\Collections\CatalogCollectionCategorySuggestionRules;
use Illuminate\Database\Eloquent\Model;

final class CatalogCollectionThemeMatcher
{
    public function __construct(
        private readonly CatalogCollectionCategorySuggestionRules $rules,
        private readonly CatalogCollectionQualityText $text = new CatalogCollectionQualityText,
    ) {}

    public function match(
        CatalogCollection $collection,
        CatalogTitle $title,
    ): CatalogCollectionThemeMatch {
        if ($collection->mode === CatalogCollectionMode::Smart) {
            return $this->result(100, CatalogCollectionInclusionReason::SmartRule);
        }

        if ($collection->relationLoaded('sourceRecord') && $collection->sourceRecord !== null) {
            return $this->result(100, CatalogCollectionInclusionReason::SourceRule);
        }

        $category = $collection->relationLoaded('category') ? $collection->category : null;
        $parent = $category?->relationLoaded('parent') === true
            ? $category->getRelation('parent')
            : null;
        $definitions = $this->rules->definitions();
        $rule = $category === null
            ? null
            : ($definitions[(string) $category->slug]
                ?? ($parent instanceof CatalogCollectionCategory
                    ? $definitions[(string) $parent->slug] ?? null
                    : null));

        if (is_array($rule)) {
            if ($this->relationMatches($title, ['genres'], $rule['genres'])) {
                return $this->result(100, CatalogCollectionInclusionReason::CategoryGenre);
            }

            if ($this->relationMatches($title, ['countries'], $rule['countries'])) {
                return $this->result(100, CatalogCollectionInclusionReason::CategoryCountry);
            }

            if ($this->relationMatches(
                $title,
                ['networks', 'studios'],
                [...$rule['networks'], ...$rule['studios']],
            )) {
                return $this->result(100, CatalogCollectionInclusionReason::CategoryPlatform);
            }

            if ($this->contains((string) $title->type, $rule['types'])) {
                return $this->result(100, CatalogCollectionInclusionReason::CategoryType);
            }

            if ($this->contains(
                implode(' ', [(string) $title->title, (string) $title->description]),
                [...$rule['title_terms'], ...$rule['description_terms']],
            )) {
                return $this->result(80, CatalogCollectionInclusionReason::TitleTheme);
            }
        }

        if ($collection->type === CatalogCollectionType::Editorial) {
            return $this->result(40, CatalogCollectionInclusionReason::EditorialChoice);
        }

        return $this->result(0, CatalogCollectionInclusionReason::ManualChoice);
    }

    /**
     * @param  list<string>  $relations
     * @param  list<string>  $terms
     */
    private function relationMatches(CatalogTitle $title, array $relations, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }

        foreach ($relations as $relation) {
            if (! $title->relationLoaded($relation)) {
                continue;
            }

            foreach ($title->getRelation($relation) as $related) {
                if (! $related instanceof Model) {
                    continue;
                }

                if ($this->contains(
                    implode(' ', [
                        (string) $related->getAttribute('name'),
                        (string) $related->getAttribute('slug'),
                    ]),
                    $terms,
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $terms */
    private function contains(string $value, array $terms): bool
    {
        $normalizedValue = $this->text->normalize($value);
        $valueTokens = explode(' ', $normalizedValue);

        foreach ($terms as $term) {
            $normalizedTerm = $this->text->normalize($term);

            if ($normalizedTerm === '') {
                continue;
            }

            if (str_contains($normalizedTerm, ' ')
                && str_contains(' '.$normalizedValue.' ', ' '.$normalizedTerm.' ')) {
                return true;
            }

            foreach ($valueTokens as $valueToken) {
                if ($valueToken === $normalizedTerm
                    || (
                        mb_strlen($normalizedTerm) >= 5
                        && str_starts_with($valueToken, $normalizedTerm)
                    )) {
                    return true;
                }
            }
        }

        return false;
    }

    private function result(
        int $percent,
        CatalogCollectionInclusionReason $reason,
    ): CatalogCollectionThemeMatch {
        return new CatalogCollectionThemeMatch(
            min(100, max(0, $percent)),
            $reason,
        );
    }
}
