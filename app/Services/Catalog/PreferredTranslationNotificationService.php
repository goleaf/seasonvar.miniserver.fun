<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\MediaHealthStatus;
use App\Enums\PlaybackAvailability;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Notifications\PreferredTranslationAvailableNotification;
use App\Support\DeterministicUuid;
use App\Support\PlainText;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class PreferredTranslationNotificationService
{
    public function __construct(private CatalogEntitlementService $entitlements) {}

    public function available(LicensedMedia|int $media): void
    {
        $mediaId = $media instanceof LicensedMedia ? $media->id : $media;

        $this->safely(function () use ($mediaId): void {
            $media = LicensedMedia::query()
                ->with([
                    'catalogTitle:id,slug,is_published,publication_status,audience,available_from,available_until,deleted_at',
                    'season:id,catalog_title_id,publication_status,audience,available_from,available_until,deleted_at',
                    'episode:id,season_id,publication_status,audience,available_from,available_until,deleted_at',
                ])
                ->find($mediaId);

            if (! $media instanceof LicensedMedia
                || ! $this->eligibleMedia($media)
                || ! is_string($media->variant_key)
                || ! $media->catalogTitle?->slug) {
                return;
            }

            UserAccountSetting::query()
                ->where('notify_preferred_translation', true)
                ->where('preferred_variant', $media->variant_key)
                ->whereNotExists(function ($query) use ($media): void {
                    $query
                        ->selectRaw('1')
                        ->from('user_hidden_playback_variants')
                        ->whereColumn('user_hidden_playback_variants.user_id', 'user_account_settings.user_id')
                        ->where('user_hidden_playback_variants.variant_key', $media->variant_key);
                })
                ->with('user:id,name')
                ->chunkById(200, function ($settings) use ($media): void {
                    foreach ($settings as $setting) {
                        $recipient = $setting->user;

                        if (! $recipient instanceof User || ! $this->availableTo($media, $recipient)) {
                            continue;
                        }

                        $notification = new PreferredTranslationAvailableNotification(
                            (string) $media->catalogTitle->slug,
                            (string) $media->variant_key,
                            $this->variantLabel($media),
                        );
                        $notification->id = DeterministicUuid::from(
                            'seasonvar.playback-preference.translation-available',
                            implode(':', [
                                $recipient->id,
                                $media->catalog_title_id,
                                $media->variant_key,
                            ]),
                        );
                        $this->deliver($recipient, $notification);
                    }
                }, column: 'user_id', alias: 'user_id');
        });
    }

    private function eligibleMedia(LicensedMedia $media): bool
    {
        return $media->status === 'published'
            && ($media->published_at === null || $media->published_at->isPast())
            && ($media->health_status ?? MediaHealthStatus::Active)->isPlayable()
            && $media->effectivePlaybackUrl() !== null
            && $media->catalogTitle !== null;
    }

    private function availableTo(LicensedMedia $media, User $user): bool
    {
        foreach (array_filter([
            $media->catalogTitle,
            $media->season,
            $media->episode,
            $media,
        ]) as $release) {
            if ($this->entitlements->decide($user, $release)->status !== PlaybackAvailability::Ready) {
                return false;
            }
        }

        return true;
    }

    private function variantLabel(LicensedMedia $media): string
    {
        $label = PlainText::clean($media->variant_name ?: $media->translation_name ?: $media->variant_key);

        return Str::limit($label !== '' ? $label : (string) $media->variant_key, 120, '');
    }

    private function deliver(User $recipient, PreferredTranslationAvailableNotification $notification): void
    {
        DB::transaction(function () use ($recipient, $notification): void {
            $locked = User::query()->lockForUpdate()->find($recipient->id);

            if (! $locked instanceof User || $locked->notifications()->whereKey($notification->id)->exists()) {
                return;
            }

            try {
                $locked->notify($notification);
            } catch (QueryException $exception) {
                if (! $locked->notifications()->whereKey($notification->id)->exists()) {
                    throw $exception;
                }
            }
        }, attempts: 3);
    }

    private function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
