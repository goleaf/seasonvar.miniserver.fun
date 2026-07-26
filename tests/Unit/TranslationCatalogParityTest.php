<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\TestCase;

final class TranslationCatalogParityTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_UK_ENGLISH = [
        'acknowledgement',
        'behaviour',
        'cancelled',
        'catalogue',
        'centre',
        'labelled',
        'programme',
    ];

    public function test_supported_translation_catalogs_match_russian_structure(): void
    {
        $locales = collect((array) config('catalog-collections.supported_locales'))
            ->map(static fn (mixed $locale): string => (string) $locale)
            ->sort()
            ->values()
            ->all();

        self::assertSame(['en', 'ru'], $locales);

        $russianFiles = $this->catalogFiles('ru');

        foreach ($locales as $locale) {
            self::assertSame($russianFiles, $this->catalogFiles($locale), $locale);

            foreach ($russianFiles as $file) {
                $russian = $this->flatten($this->loadCatalog('ru', $file));
                $localized = $this->flatten($this->loadCatalog($locale, $file));

                self::assertSame(
                    array_keys($russian),
                    array_keys($localized),
                    "{$locale}/{$file}",
                );

                foreach ($russian as $key => $value) {
                    $label = "{$locale}/{$file}:{$key}";
                    $localizedValue = $localized[$key];

                    self::assertSame(
                        get_debug_type($value),
                        get_debug_type($localizedValue),
                        $label,
                    );

                    if (! is_string($value)) {
                        continue;
                    }

                    self::assertIsString($localizedValue, $label);
                    self::assertNotSame('', trim($localizedValue), $label);
                    self::assertSame(
                        $this->placeholders($value),
                        $this->placeholders($localizedValue),
                        $label,
                    );

                    $this->assertPluralBranchesAreNonEmpty($localizedValue, $label);
                }
            }
        }
    }

    public function test_translation_catalog_arrays_are_vertical_and_have_unique_literal_keys(): void
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;

        foreach (['ru', 'en'] as $locale) {
            foreach ($this->catalogFiles($locale) as $file) {
                $path = lang_path("{$locale}/{$file}");
                $statements = $parser->parse(File::get($path));

                self::assertNotNull($statements, "{$locale}/{$file}");

                /** @var list<Array_> $arrays */
                $arrays = $finder->findInstanceOf($statements, Array_::class);

                foreach ($arrays as $array) {
                    $label = "{$locale}/{$file}:{$array->getStartLine()}";

                    $this->assertArrayHasUniqueLiteralKeys($array, $label);

                    if ($array->items === []) {
                        continue;
                    }

                    self::assertGreaterThan(
                        $array->getStartLine(),
                        $array->getEndLine(),
                        "{$label} must be multiline",
                    );

                    $itemLines = array_map(
                        static fn (Node\ArrayItem $item): int => $item->getStartLine(),
                        $array->items,
                    );

                    self::assertSame(
                        count($itemLines),
                        count(array_unique($itemLines)),
                        "{$label} must contain one item per line",
                    );
                    self::assertGreaterThan(
                        $array->getStartLine(),
                        min($itemLines),
                        "{$label} must place its first item after the opening bracket",
                    );
                    self::assertGreaterThan(
                        max($itemLines),
                        $array->getEndLine(),
                        "{$label} must place its closing bracket after the final item",
                    );
                }
            }
        }
    }

    public function test_english_catalogs_use_the_approved_us_english_spellings(): void
    {
        $pattern = '/(?<![A-Za-z])(?:'.implode('|', self::FORBIDDEN_UK_ENGLISH).')(?![A-Za-z])/iu';

        foreach ($this->catalogFiles('en') as $file) {
            foreach ($this->flatten($this->loadCatalog('en', $file)) as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $value,
                    "en/{$file}:{$key}",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function catalogFiles(string $locale): array
    {
        return collect(File::files(lang_path($locale)))
            ->filter(static fn (\SplFileInfo $file): bool => $file->getExtension() === 'php')
            ->map(static fn (\SplFileInfo $file): string => $file->getFilename())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function loadCatalog(string $locale, string $file): array
    {
        $catalog = require lang_path("{$locale}/{$file}");

        self::assertIsArray($catalog, "{$locale}/{$file}");

        return $catalog;
    }

    /**
     * @param  array<array-key, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function flatten(array $catalog, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($catalog as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flattened = [...$flattened, ...$this->flatten($value, $path)];

                continue;
            }

            $flattened[$path] = $value;
        }

        return $flattened;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all(
            '/(?<!:):[A-Za-z_][A-Za-z0-9_]*|\{[A-Za-z_][A-Za-z0-9_]*\}/',
            $value,
            $matches,
        );

        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }

    private function assertPluralBranchesAreNonEmpty(string $value, string $label): void
    {
        if (! str_contains($value, '|')) {
            return;
        }

        foreach (explode('|', $value) as $branch) {
            self::assertNotSame('', trim($branch), $label);
        }
    }

    private function assertArrayHasUniqueLiteralKeys(Array_ $array, string $label): void
    {
        $keys = [];

        foreach ($array->items as $item) {
            if ($item->key === null) {
                continue;
            }

            $key = match (true) {
                $item->key instanceof String_ => $item->key->value,
                $item->key instanceof Int_ => (string) $item->key->value,
                default => null,
            };

            self::assertNotNull($key, "{$label} must use literal translation keys");
            self::assertArrayNotHasKey($key, $keys, "{$label} duplicates key {$key}");

            $keys[$key] = true;
        }
    }
}
