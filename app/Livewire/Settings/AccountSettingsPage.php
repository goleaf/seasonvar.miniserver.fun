<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Actions\Comments\UpdateCommentNotificationPreferences;
use App\Actions\ContentRequests\UpdateContentRequestNotificationPreferences;
use App\Actions\ReleaseCalendar\UpdateReleaseCalendarNotificationPreferences;
use App\Actions\Reviews\UpdateReviewNotificationPreferences;
use App\DTOs\AccountSettingsData;
use App\DTOs\PlaybackSettingsData;
use App\Enums\AccountSettingsSection;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogTitleReviewNotificationPreference;
use App\Models\CommentNotificationPreference;
use App\Models\ContentRequestNotificationPreference;
use App\Models\ReleaseCalendarNotificationPreference;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use App\Services\Catalog\PlaybackPreferenceOptions;
use App\Services\Comments\CommentSchema;
use App\Services\ContentRequests\ContentRequestSchema;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\Reviews\ReviewSchema;
use App\ValueObjects\AccountTimezone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class AccountSettingsPage extends Component
{
    #[Locked]
    public string $section = 'profile';

    public string $locale = 'ru';

    public string $timezone = 'UTC';

    public bool $reducedMotion = false;

    public bool $autoplay = false;

    public bool $rememberVolume = true;

    public int $volume = 70;

    public bool $muted = false;

    public string $playbackSpeed = '1.00';

    public string $preferredQuality = '';

    public string $preferredVariant = '';

    public bool $subtitlesEnabled = false;

    public bool $keyboardShortcutsEnabled = true;

    public string $collectionDefaultVisibility = 'private';

    public bool $replyNotifications = true;

    public bool $reactionNotifications = true;

    public bool $commentModerationNotifications = true;

    public bool $commentReportNotifications = true;

    public bool $reviewHelpfulNotifications = true;

    public bool $reviewModerationNotifications = true;

    public bool $reviewReportNotifications = true;

    public bool $requesterRequestNotifications = true;

    public bool $votedRequestNotifications = true;

    public bool $followedRequestNotifications = true;

    public bool $releasePremiereNotifications = true;

    public bool $releaseSeasonNotifications = true;

    public bool $releaseEpisodeNotifications = true;

    public bool $releaseTranslationNotifications = true;

    public bool $releaseSubtitleNotifications = true;

    public bool $releaseDateChangeNotifications = true;

    public bool $releasePostponedNotifications = true;

    public bool $releaseCancelledNotifications = true;

    public bool $releasePortalPublicationNotifications = true;

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public function mount(
        AccountSettingsService $settings,
        CommentSchema $commentSchema,
        ReviewSchema $reviewSchema,
        ContentRequestSchema $contentRequestSchema,
        ReleaseCalendarSchema $releaseCalendarSchema,
        ?string $section = null,
        ?string $locale = null,
    ): void {
        abort_if($locale !== null && ! in_array($locale, (array) config('catalog-collections.supported_locales', []), true), 404);
        $resolvedSection = AccountSettingsSection::tryFrom($section ?? AccountSettingsSection::Profile->value);
        abort_unless($resolvedSection instanceof AccountSettingsSection, 404);
        Gate::forUser($this->user())->authorize('view-account-settings');

        $this->section = $resolvedSection->value;
        $statusKey = Session::pull('account_settings_status_key');
        $status = Session::pull('account_settings_status');
        $this->statusMessage = is_string($statusKey)
            ? __($statusKey)
            : (is_string($status) ? $status : null);
        try {
            $this->loadSettings($settings);
        } catch (Throwable $exception) {
            report($exception);
            $this->applySettings($settings->resolve(null));
            $this->actionError = __('settings.errors.query_failed');
        }

        if ($resolvedSection === AccountSettingsSection::Notifications) {
            $this->loadNotificationPreferences($commentSchema, $reviewSchema, $contentRequestSchema, $releaseCalendarSchema);
        }
    }

    public function saveAppearance(AccountSettingsService $settings): void
    {
        $validated = $this->validate([
            'locale' => ['required', Rule::in((array) config('catalog-collections.supported_locales', []))],
            'timezone' => ['required', Rule::in(AccountTimezone::identifiers())],
            'reducedMotion' => ['required', 'boolean'],
        ], $this->validationMessages());

        try {
            $saved = $settings->updateAppearance(
                $this->user(),
                $validated['locale'],
                AccountTimezone::from($validated['timezone']),
                $validated['reducedMotion'],
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->settingsFailure($exception);

            return;
        }

        Session::flash('account_settings_status_key', 'settings.status.appearance_saved');
        $this->dispatch('account-settings-persisted');
        $this->dispatchDevicePreferences($saved->toExportArray());
        $this->redirectRoute('localized.settings.index', [
            'locale' => $saved->locale,
            'section' => AccountSettingsSection::Appearance->value,
        ], navigate: true);
    }

    public function savePlayback(
        AccountSettingsService $settings,
        PlaybackPreferenceOptions $options,
    ): void {
        $user = $this->user();
        $current = $settings->resolve($user);
        $variantKeys = collect($options->variants(
            $current->preferredVariant,
            $user,
        ))
            ->pluck('value')
            ->all();
        $qualityKeys = collect($options->qualities(
            $current->preferredQuality,
            $user,
        ))->pluck('value')->all();
        $validated = $this->validate([
            'autoplay' => ['required', 'boolean'],
            'rememberVolume' => ['required', 'boolean'],
            'volume' => ['required', 'integer', 'between:0,100'],
            'muted' => ['required', 'boolean'],
            'playbackSpeed' => ['required', Rule::in((array) config('account-settings.playback_speeds', []))],
            'preferredQuality' => ['nullable', Rule::in(['', ...$qualityKeys])],
            'preferredVariant' => ['nullable', Rule::in(['', ...$variantKeys])],
            'subtitlesEnabled' => ['required', 'boolean'],
            'keyboardShortcutsEnabled' => ['required', 'boolean'],
        ], $this->validationMessages());

        try {
            $saved = $settings->updatePlayback($user, new PlaybackSettingsData(
                autoplay: $validated['autoplay'],
                rememberVolume: $validated['rememberVolume'],
                volume: $validated['volume'],
                muted: $validated['muted'],
                playbackSpeed: $validated['playbackSpeed'],
                preferredQuality: $validated['preferredQuality'] !== '' ? $validated['preferredQuality'] : null,
                preferredVariant: $validated['preferredVariant'] !== '' ? $validated['preferredVariant'] : null,
                subtitlesEnabled: $validated['subtitlesEnabled'],
                keyboardShortcutsEnabled: $validated['keyboardShortcutsEnabled'],
            ));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->settingsFailure($exception);

            return;
        }

        $this->loadSettings($settings);
        $this->statusMessage = __('settings.status.playback_saved');
        $this->actionError = null;
        $this->dispatch('account-settings-persisted');
        $this->dispatchDevicePreferences($saved->toExportArray());
    }

    public function resetPlayback(AccountSettingsService $settings): void
    {
        try {
            $saved = $settings->resetPlayback($this->user());
        } catch (Throwable $exception) {
            $this->settingsFailure($exception);

            return;
        }
        $this->loadSettings($settings);
        $this->statusMessage = __('settings.status.playback_reset');
        $this->actionError = null;
        $this->dispatch('account-settings-persisted');
        $this->dispatchDevicePreferences($saved->toExportArray());
    }

    public function saveCollections(AccountSettingsService $settings): void
    {
        $validated = $this->validate([
            'collectionDefaultVisibility' => ['required', Rule::enum(CatalogCollectionVisibility::class)],
        ], $this->validationMessages());
        try {
            $settings->updateCollectionDefault(
                $this->user(),
                CatalogCollectionVisibility::from($validated['collectionDefaultVisibility']),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->settingsFailure($exception);

            return;
        }
        $this->statusMessage = __('settings.status.collections_saved');
        $this->actionError = null;
        $this->dispatch('account-settings-persisted');
    }

    public function saveNotifications(
        UpdateCommentNotificationPreferences $comments,
        UpdateReviewNotificationPreferences $reviews,
        UpdateContentRequestNotificationPreferences $contentRequests,
        UpdateReleaseCalendarNotificationPreferences $releaseCalendar,
        CommentSchema $commentSchema,
        ReviewSchema $reviewSchema,
        ContentRequestSchema $contentRequestSchema,
        ReleaseCalendarSchema $releaseCalendarSchema,
    ): void {
        $user = $this->user();
        Gate::forUser($user)->authorize('update-account-settings');
        $validated = $this->validate([
            'replyNotifications' => ['required', 'boolean'],
            'reactionNotifications' => ['required', 'boolean'],
            'commentModerationNotifications' => ['required', 'boolean'],
            'commentReportNotifications' => ['required', 'boolean'],
            'reviewHelpfulNotifications' => ['required', 'boolean'],
            'reviewModerationNotifications' => ['required', 'boolean'],
            'reviewReportNotifications' => ['required', 'boolean'],
            'requesterRequestNotifications' => ['required', 'boolean'],
            'votedRequestNotifications' => ['required', 'boolean'],
            'followedRequestNotifications' => ['required', 'boolean'],
            'releasePremiereNotifications' => ['required', 'boolean'],
            'releaseSeasonNotifications' => ['required', 'boolean'],
            'releaseEpisodeNotifications' => ['required', 'boolean'],
            'releaseTranslationNotifications' => ['required', 'boolean'],
            'releaseSubtitleNotifications' => ['required', 'boolean'],
            'releaseDateChangeNotifications' => ['required', 'boolean'],
            'releasePostponedNotifications' => ['required', 'boolean'],
            'releaseCancelledNotifications' => ['required', 'boolean'],
            'releasePortalPublicationNotifications' => ['required', 'boolean'],
        ]);

        try {
            DB::transaction(function () use ($comments, $reviews, $contentRequests, $releaseCalendar, $commentSchema, $reviewSchema, $contentRequestSchema, $releaseCalendarSchema, $user, $validated): void {
                if ($commentSchema->notificationsAvailable()) {
                    $comments->handle($user, [
                        'reply_notifications' => $validated['replyNotifications'],
                        'reaction_notifications' => $validated['reactionNotifications'],
                        'moderation_notifications' => $validated['commentModerationNotifications'],
                        'report_notifications' => $validated['commentReportNotifications'],
                    ]);
                }

                if ($reviewSchema->notificationsAvailable()) {
                    $reviews->handle($user, [
                        'helpful_notifications' => $validated['reviewHelpfulNotifications'],
                        'moderation_notifications' => $validated['reviewModerationNotifications'],
                        'report_notifications' => $validated['reviewReportNotifications'],
                    ]);
                }

                if ($contentRequestSchema->ready()) {
                    $contentRequests->handle($user, [
                        'requester_updates' => $validated['requesterRequestNotifications'],
                        'voted_updates' => $validated['votedRequestNotifications'],
                        'followed_updates' => $validated['followedRequestNotifications'],
                    ]);
                }

                if ($releaseCalendarSchema->ready()) {
                    $releaseCalendar->handle($user, [
                        'premiere_notifications' => $validated['releasePremiereNotifications'],
                        'season_notifications' => $validated['releaseSeasonNotifications'],
                        'episode_notifications' => $validated['releaseEpisodeNotifications'],
                        'translation_notifications' => $validated['releaseTranslationNotifications'],
                        'subtitle_notifications' => $validated['releaseSubtitleNotifications'],
                        'date_change_notifications' => $validated['releaseDateChangeNotifications'],
                        'postponed_notifications' => $validated['releasePostponedNotifications'],
                        'cancelled_notifications' => $validated['releaseCancelledNotifications'],
                        'portal_publication_notifications' => $validated['releasePortalPublicationNotifications'],
                    ]);
                }
            }, attempts: 3);
        } catch (Throwable $exception) {
            report($exception);
            $this->statusMessage = null;
            $this->actionError = __('settings.errors.save_failed');
            $this->dispatch('account-settings-save-failed');

            return;
        }

        $this->statusMessage = __('settings.status.notifications_saved');
        $this->actionError = null;
        $this->dispatch('account-settings-persisted');
    }

    public function resetNotifications(
        UpdateCommentNotificationPreferences $comments,
        UpdateReviewNotificationPreferences $reviews,
        UpdateContentRequestNotificationPreferences $contentRequests,
        UpdateReleaseCalendarNotificationPreferences $releaseCalendar,
        CommentSchema $commentSchema,
        ReviewSchema $reviewSchema,
        ContentRequestSchema $contentRequestSchema,
        ReleaseCalendarSchema $releaseCalendarSchema,
    ): void {
        $this->replyNotifications = true;
        $this->reactionNotifications = true;
        $this->commentModerationNotifications = true;
        $this->commentReportNotifications = true;
        $this->reviewHelpfulNotifications = true;
        $this->reviewModerationNotifications = true;
        $this->reviewReportNotifications = true;
        $this->requesterRequestNotifications = true;
        $this->votedRequestNotifications = true;
        $this->followedRequestNotifications = true;
        $this->releasePremiereNotifications = true;
        $this->releaseSeasonNotifications = true;
        $this->releaseEpisodeNotifications = true;
        $this->releaseTranslationNotifications = true;
        $this->releaseSubtitleNotifications = true;
        $this->releaseDateChangeNotifications = true;
        $this->releasePostponedNotifications = true;
        $this->releaseCancelledNotifications = true;
        $this->releasePortalPublicationNotifications = true;
        $this->saveNotifications($comments, $reviews, $contentRequests, $releaseCalendar, $commentSchema, $reviewSchema, $contentRequestSchema, $releaseCalendarSchema);

        if ($this->actionError === null) {
            $this->statusMessage = __('settings.status.notifications_reset');
        }
    }

    public function cancelChanges(
        AccountSettingsService $settings,
        CommentSchema $commentSchema,
        ReviewSchema $reviewSchema,
        ContentRequestSchema $contentRequestSchema,
        ReleaseCalendarSchema $releaseCalendarSchema,
    ): void {
        $this->resetValidation();
        $this->loadSettings($settings);

        if ($this->section === AccountSettingsSection::Notifications->value) {
            $this->loadNotificationPreferences($commentSchema, $reviewSchema, $contentRequestSchema, $releaseCalendarSchema);
        }

        $this->statusMessage = __('settings.status.changes_discarded');
        $this->actionError = null;
        $this->dispatch('account-settings-persisted');
    }

    public function render(
        AccountDateTimeFormatter $dateTimes,
        PlaybackPreferenceOptions $playbackOptions,
        CommentSchema $commentSchema,
        ReviewSchema $reviewSchema,
        ContentRequestSchema $contentRequestSchema,
        ReleaseCalendarSchema $releaseCalendarSchema,
    ): View {
        $active = AccountSettingsSection::from($this->section);
        $navigation = collect(AccountSettingsSection::cases())->map(fn (AccountSettingsSection $section): array => [
            'key' => $section->value,
            'label' => $section->label(),
            'icon' => $section->icon(),
            'url' => $this->settingsRoute($section),
            'active' => $section === $active,
        ])->all();
        $variantOptions = $active === AccountSettingsSection::Playback
            ? $playbackOptions->variants($this->preferredVariant !== '' ? $this->preferredVariant : null, $this->user())
            : [];

        return view('livewire.settings.account-settings-page', [
            'activeSection' => $active,
            'navigation' => $navigation,
            'localeOptions' => [
                ['value' => 'ru', 'label' => __('settings.locales.ru')],
                ['value' => 'en', 'label' => __('settings.locales.en')],
            ],
            'timezoneOptions' => $active === AccountSettingsSection::Appearance ? AccountTimezone::identifiers() : [],
            'currentTimePreview' => $active === AccountSettingsSection::Appearance
                ? $this->timePreview($dateTimes)
                : null,
            'speedOptions' => (array) config('account-settings.playback_speeds', []),
            'qualityOptions' => $active === AccountSettingsSection::Playback
                ? $playbackOptions->qualities($this->preferredQuality !== '' ? $this->preferredQuality : null, $this->user())
                : [],
            'variantOptions' => $variantOptions,
            'visibilityOptions' => array_map(static fn (CatalogCollectionVisibility $visibility): array => [
                'value' => $visibility->value,
                'label' => $visibility->label(),
                'hint' => __('collections.visibility.'.$visibility->value.'_hint'),
            ], CatalogCollectionVisibility::cases()),
            'commentNotificationsAvailable' => $commentSchema->notificationsAvailable(),
            'reviewNotificationsAvailable' => $reviewSchema->notificationsAvailable(),
            'contentRequestNotificationsAvailable' => $contentRequestSchema->ready(),
            'releaseCalendarNotificationsAvailable' => $releaseCalendarSchema->ready(),
            'databaseSessionsAvailable' => config('session.driver') === 'database',
            'anonymousStorageKey' => (string) config('account-settings.anonymous_storage_key'),
            'profileSummary' => [
                'name' => (string) $this->user()->name,
                'email' => (string) $this->user()->email,
                'verified' => $this->user()->hasVerifiedEmail(),
            ],
        ])->extends('layouts.app', [
            'title' => __('settings.title'),
            'seo' => [
                'title' => __('settings.title'),
                'description' => __('settings.description'),
                'robots' => 'noindex, nofollow',
                'canonical' => $this->settingsRoute($active),
                'social' => false,
                'alternates' => [],
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function loadSettings(AccountSettingsService $settings): void
    {
        $this->applySettings($settings->resolve($this->user()));
    }

    private function applySettings(AccountSettingsData $resolved): void
    {
        $this->locale = $resolved->locale;
        $this->timezone = $resolved->timezone;
        $this->reducedMotion = $resolved->reducedMotion;
        $this->autoplay = $resolved->autoplay;
        $this->rememberVolume = $resolved->rememberVolume;
        $this->volume = $resolved->volume;
        $this->muted = $resolved->muted;
        $this->playbackSpeed = $resolved->playbackSpeed;
        $this->preferredQuality = $resolved->preferredQuality ?? '';
        $this->preferredVariant = $resolved->preferredVariant ?? '';
        $this->subtitlesEnabled = $resolved->subtitlesEnabled;
        $this->keyboardShortcutsEnabled = $resolved->keyboardShortcutsEnabled;
        $this->collectionDefaultVisibility = $resolved->collectionDefaultVisibility;
    }

    private function settingsFailure(Throwable $exception): void
    {
        report($exception);
        $this->statusMessage = null;
        $this->actionError = __('settings.errors.save_failed');
        $this->dispatch('account-settings-save-failed');
    }

    private function timePreview(AccountDateTimeFormatter $dateTimes): string
    {
        try {
            $timezone = AccountTimezone::from($this->timezone);

            return $dateTimes->nowPreview($this->locale, $timezone->value);
        } catch (Throwable) {
            return __('settings.validation.timezone');
        }
    }

    private function loadNotificationPreferences(CommentSchema $comments, ReviewSchema $reviews, ContentRequestSchema $contentRequests, ReleaseCalendarSchema $releaseCalendar): void
    {
        try {
            if ($comments->notificationsAvailable()) {
                $preference = CommentNotificationPreference::query()->find($this->user()->id)
                    ?? new CommentNotificationPreference(['user_id' => $this->user()->id]);
                $this->replyNotifications = $preference->reply_notifications;
                $this->reactionNotifications = $preference->reaction_notifications;
                $this->commentModerationNotifications = $preference->moderation_notifications;
                $this->commentReportNotifications = $preference->report_notifications;
            }

            if ($reviews->notificationsAvailable()) {
                $preference = CatalogTitleReviewNotificationPreference::query()->find($this->user()->id)
                    ?? new CatalogTitleReviewNotificationPreference(['user_id' => $this->user()->id]);
                $this->reviewHelpfulNotifications = $preference->helpful_notifications;
                $this->reviewModerationNotifications = $preference->moderation_notifications;
                $this->reviewReportNotifications = $preference->report_notifications;
            }

            if ($contentRequests->ready()) {
                $preference = ContentRequestNotificationPreference::query()->find($this->user()->id)
                    ?? new ContentRequestNotificationPreference(['user_id' => $this->user()->id]);
                $this->requesterRequestNotifications = $preference->requester_updates;
                $this->votedRequestNotifications = $preference->voted_updates;
                $this->followedRequestNotifications = $preference->followed_updates;
            }

            if ($releaseCalendar->ready()) {
                $preference = ReleaseCalendarNotificationPreference::query()->find($this->user()->id)
                    ?? new ReleaseCalendarNotificationPreference(['user_id' => $this->user()->id]);
                $this->releasePremiereNotifications = $preference->premiere_notifications;
                $this->releaseSeasonNotifications = $preference->season_notifications;
                $this->releaseEpisodeNotifications = $preference->episode_notifications;
                $this->releaseTranslationNotifications = $preference->translation_notifications;
                $this->releaseSubtitleNotifications = $preference->subtitle_notifications;
                $this->releaseDateChangeNotifications = $preference->date_change_notifications;
                $this->releasePostponedNotifications = $preference->postponed_notifications;
                $this->releaseCancelledNotifications = $preference->cancelled_notifications;
                $this->releasePortalPublicationNotifications = $preference->portal_publication_notifications;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = __('settings.errors.query_failed');
        }
    }

    /** @param array<string, bool|int|string|null> $preferences */
    private function dispatchDevicePreferences(array $preferences): void
    {
        $this->dispatch('account-settings-saved', preferences: ['version' => 1, ...$preferences]);
    }

    private function settingsRoute(AccountSettingsSection $section): string
    {
        $locale = App::getLocale();

        if (in_array($locale, (array) config('catalog-collections.supported_locales', []), true)) {
            return route('localized.settings.index', ['locale' => $locale, 'section' => $section->value]);
        }

        return route('settings.index', ['section' => $section->value]);
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'locale.*' => __('settings.validation.locale'),
            'timezone.*' => __('settings.validation.timezone'),
            'volume.*' => __('settings.validation.volume'),
            'playbackSpeed.*' => __('settings.validation.playback_speed'),
            'preferredQuality.*' => __('settings.validation.quality'),
            'preferredVariant.*' => __('settings.validation.variant'),
            'collectionDefaultVisibility.*' => __('settings.validation.collection_visibility'),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
