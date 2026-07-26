<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class PreferredTranslationAvailableNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $catalogTitleSlug,
        private readonly string $variantKey,
        private readonly string $variantLabel,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'playback-preference.translation-available';
    }

    /** @return array{catalog_title_slug: string, variant_key: string, variant_label: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'catalog_title_slug' => $this->catalogTitleSlug,
            'variant_key' => $this->variantKey,
            'variant_label' => $this->variantLabel,
        ];
    }
}
