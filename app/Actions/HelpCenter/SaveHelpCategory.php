<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Models\HelpCategory;
use App\Models\HelpCategoryTranslation;
use App\Models\User;
use App\Services\HelpCenter\HelpCacheInvalidator;
use App\Services\HelpCenter\HelpLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class SaveHelpCategory
{
    public function __construct(private HelpLocale $locales, private HelpCacheInvalidator $cache) {}

    /** @param array<string, mixed> $input */
    public function handle(User $editor, array $input, ?HelpCategory $category = null): HelpCategory
    {
        Gate::forUser($editor)->authorize('manage-help-center');
        $data = validator($input, [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_]+$/', Rule::unique('help_categories', 'code')->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', 'exists:help_categories,id'],
            'position' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_visible' => ['required', 'boolean'],
            'translations' => ['required', 'array'],
            'translations.*.locale' => ['required', 'distinct', Rule::in($this->locales->supported())],
            'translations.*.slug' => ['required', 'string', 'max:160', 'regex:/^[\pL\pN][\pL\pN-]*$/u'],
            'translations.*.title' => ['required', 'string', 'max:160'],
            'translations.*.description' => ['required', 'string', 'max:500'],
        ])->validate();
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($parentId !== null) {
            $parent = HelpCategory::query()->findOrFail($parentId);

            if ($parent->parent_id !== null
                || $category?->id === $parent->id
                || ($category instanceof HelpCategory && $category->children()->exists())) {
                throw ValidationException::withMessages(['parent_id' => [__('help.admin.validation.category_depth')]]);
            }
        }

        $saved = DB::transaction(function () use ($category, $data, $parentId): HelpCategory {
            $locked = $category instanceof HelpCategory
                ? HelpCategory::query()->lockForUpdate()->findOrFail($category->id)
                : new HelpCategory(['public_id' => (string) Str::uuid()]);
            $locked->fill([
                'code' => $data['code'],
                'parent_id' => $parentId,
                'position' => (int) $data['position'],
                'is_visible' => (bool) $data['is_visible'],
                'content_version' => max(1, (int) $locked->content_version + ($locked->exists ? 1 : 0)),
            ])->save();

            foreach ($data['translations'] as $translation) {
                $current = HelpCategoryTranslation::query()
                    ->where('help_category_id', $locked->id)
                    ->where('locale', $translation['locale'])
                    ->first();
                $duplicate = HelpCategoryTranslation::query()
                    ->where('locale', $translation['locale'])
                    ->where('slug', $translation['slug'])
                    ->when($current !== null, fn ($query) => $query->whereKeyNot($current->id))
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['translations' => [__('help.admin.validation.slug_unique')]]);
                }

                $historicalOwner = DB::table('help_category_slugs')
                    ->where('locale', $translation['locale'])
                    ->where('slug', $translation['slug'])
                    ->value('help_category_id');

                if (is_numeric($historicalOwner) && (int) $historicalOwner !== $locked->id) {
                    throw ValidationException::withMessages(['translations' => [__('help.admin.validation.slug_unique')]]);
                }

                if ($current instanceof HelpCategoryTranslation && $current->slug !== $translation['slug']) {
                    DB::table('help_category_slugs')->insertOrIgnore([
                        'help_category_id' => $locked->id,
                        'locale' => $translation['locale'],
                        'slug' => $current->slug,
                        'created_at' => now(),
                    ]);
                }

                HelpCategoryTranslation::query()->updateOrCreate([
                    'help_category_id' => $locked->id,
                    'locale' => $translation['locale'],
                ], [
                    'slug' => $translation['slug'],
                    'title' => $translation['title'],
                    'description' => $translation['description'],
                    'seo_title' => $translation['title'],
                    'seo_description' => $translation['description'],
                ]);
            }

            return $locked->fresh('translations');
        }, attempts: 3);
        $this->cache->changed();

        return $saved;
    }
}
