<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\DTOs\Profiles\PublicUserProfileData;
use App\Models\UserProfile;
use Illuminate\Support\Str;

final class UserProfileSeoPresenter
{
    /** @return array<string, mixed> */
    public function present(
        UserProfile $profile,
        PublicUserProfileData $data,
        bool $localizedAlias,
        bool $statefulVariant,
    ): array {
        $indexable = $profile->isPublic() && ! $localizedAlias && ! $statefulVariant;
        $description = $data->biography !== null
            ? Str::limit($data->biography, 180)
            : __('profiles.seo.description', ['name' => $data->displayName, 'username' => $data->username]);

        return [
            'title' => __('profiles.seo.title', ['name' => $data->displayName, 'username' => $data->username]),
            'description' => $description,
            'canonical' => $data->canonicalUrl,
            'robots' => $indexable ? 'index,follow,max-image-preview:large,max-snippet:-1' : 'noindex,follow',
            'social' => $profile->isPublic(),
            'type' => 'profile',
            'image' => $data->avatarUrl,
            'image_alt' => __('profiles.accessibility.avatar', ['name' => $data->displayName]),
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => $data->displayName, 'url' => $data->canonicalUrl],
            ],
            'alternates' => [],
            'jsonLd' => $indexable ? [[
                '@context' => 'https://schema.org',
                '@type' => 'ProfilePage',
                'url' => $data->canonicalUrl,
                'mainEntity' => array_filter([
                    '@type' => 'Person',
                    'name' => $data->displayName,
                    'alternateName' => '@'.$data->username,
                    'url' => $data->canonicalUrl,
                    'image' => $data->avatarUrl,
                    'description' => $data->biography,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ]] : [],
        ];
    }
}
