<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReviewNotificationPreference;
use App\Models\User;
use App\Services\Reviews\ReviewSchema;

final class UpdateReviewNotificationPreferences
{
    public function __construct(private readonly ReviewSchema $schema) {}

    /** @param array{helpful_notifications: bool, moderation_notifications: bool, report_notifications: bool} $preferences */
    public function handle(User $user, array $preferences): CatalogTitleReviewNotificationPreference
    {
        if (! $this->schema->notificationsAvailable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        return CatalogTitleReviewNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            $preferences,
        );
    }
}
