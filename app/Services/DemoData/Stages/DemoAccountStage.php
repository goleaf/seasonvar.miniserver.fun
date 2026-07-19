<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\UserProfileModerationStatus;
use App\Enums\UserProfileVisibility;
use App\Models\CatalogTitleReviewNotificationPreference;
use App\Models\CommentNotificationPreference;
use App\Models\ContentRequestNotificationPreference;
use App\Models\TechnicalIssueNotificationPreference;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Models\UserProfile;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoPersonaFactory;
use App\Services\DemoData\DemoRasterAsset;
use App\Services\DemoData\DemoStableValue;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class DemoAccountStage implements DemoDataStage
{
    private const TIMEZONES = [
        'Europe/Kaliningrad',
        'Europe/Moscow',
        'Europe/Samara',
        'Asia/Yekaterinburg',
        'Asia/Omsk',
        'Asia/Krasnoyarsk',
        'Asia/Irkutsk',
        'Asia/Yakutsk',
        'Asia/Vladivostok',
    ];

    private const DEVICES = [
        ['Chrome · Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/138.0 Safari/537.36'],
        ['Firefox · Linux', 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0'],
        ['Safari · macOS', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.5 Safari/605.1.15'],
        ['Chrome · Android', 'Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/138.0 Mobile Safari/537.36'],
        ['Safari · iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 Version/18.5 Mobile Safari/604.1'],
        ['Microsoft Edge · Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/138.0 Safari/537.36 Edg/138.0'],
    ];

    public function __construct(
        private DemoStableValue $stable,
        private DemoPersonaFactory $personas,
    ) {}

    public function key(): string
    {
        return 'accounts';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $options->assertEnvironment(app()->environment());
        $asset = new DemoRasterAsset($options, $this->stable);
        $writer = new DemoBulkWriter($options);
        $users = [];
        $profiles = [];
        $settings = [];
        $commentPreferences = [];
        $reviewPreferences = [];
        $requestPreferences = [];
        $issuePreferences = [];
        $tokens = [];
        $sessions = [];

        for ($userIndex = 1; $userIndex <= $options->userCount; $userIndex++) {
            $persona = $this->personas->make($userIndex);
            $email = "user{$userIndex}@example.com";
            $createdAt = $this->createdAt($userIndex);
            $updatedAt = $createdAt->addDays($this->stable->integer("account:{$userIndex}:updated-days", 30, 900));
            $user = User::query()->where('email', $email)->first() ?? new User;
            $user->timestamps = false;
            $user->forceFill([
                'public_id' => $this->stable->uuid("account:{$userIndex}:public-id"),
                'name' => $persona->displayName,
                'email' => $email,
                'email_verified_at' => $createdAt->addMinutes(15),
                'created_at' => $user->exists ? $user->created_at : $createdAt,
                'updated_at' => $updatedAt,
            ]);

            if (! $user->exists || ! is_string($user->password) || ! Hash::check('password', $user->password)) {
                $user->password = 'password';
            }

            $user->saveQuietly();
            $user->timestamps = true;
            $users[$userIndex] = $user;
            $avatar = $asset->store(
                'avatars',
                (string) $user->public_id,
                320,
                320,
                'user-profiles/'.$user->public_id.'/avatar/demo',
                'webp',
            );
            $cover = $asset->store(
                'profile-covers',
                (string) $user->public_id,
                1_280,
                360,
                'user-profiles/'.$user->public_id.'/cover/demo',
                'webp',
            );
            $visibility = $this->stable->boolean("account:{$userIndex}:public-profile", 72)
                ? UserProfileVisibility::Public->value
                : UserProfileVisibility::Private->value;

            $profiles[] = [
                'user_id' => $user->id,
                'username' => $persona->username,
                'normalized_username' => $persona->username,
                'biography' => $persona->biography,
                'profile_visibility' => $visibility,
                'biography_visibility' => $visibility,
                'member_since_visibility' => $visibility,
                'collections_visibility' => $visibility,
                'reviews_visibility' => $visibility,
                'comments_visibility' => $visibility,
                'watching_visibility' => $visibility,
                'completed_visibility' => $visibility,
                'activity_visibility' => $visibility,
                'moderation_status' => UserProfileModerationStatus::Active->value,
                'avatar_disk' => $avatar['disk'],
                'avatar_path' => $avatar['path'],
                'avatar_mime_type' => $avatar['mime_type'],
                'avatar_size' => $avatar['size'],
                'avatar_version' => 1,
                'cover_disk' => $cover['disk'],
                'cover_path' => $cover['path'],
                'cover_mime_type' => $cover['mime_type'],
                'cover_size' => $cover['size'],
                'cover_version' => 1,
                'content_version' => 1,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];
            $settings[] = $this->settings($user, $userIndex, $createdAt, $updatedAt);
            $commentPreferences[] = $this->commentPreferences($user, $userIndex, $createdAt, $updatedAt);
            $reviewPreferences[] = $this->reviewPreferences($user, $userIndex, $createdAt, $updatedAt);
            $requestPreferences[] = $this->requestPreferences($user, $userIndex, $createdAt, $updatedAt);
            $issuePreferences[] = $this->issuePreferences($user, $userIndex, $createdAt, $updatedAt);

            foreach ($this->deviceRows($user, $userIndex, $createdAt, $updatedAt) as $device) {
                $tokens[] = $device['token'];

                if (config('session.driver') === 'database') {
                    $sessions[] = $device['session'];
                }
            }

            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        $writer->upsert((new UserProfile)->getTable(), $profiles, ['user_id'], $this->updates($profiles));
        $writer->upsert((new UserAccountSetting)->getTable(), $settings, ['user_id'], $this->updates($settings));
        $writer->upsert((new CommentNotificationPreference)->getTable(), $commentPreferences, ['user_id'], $this->updates($commentPreferences));
        $writer->upsert((new CatalogTitleReviewNotificationPreference)->getTable(), $reviewPreferences, ['user_id'], $this->updates($reviewPreferences));
        $writer->upsert((new ContentRequestNotificationPreference)->getTable(), $requestPreferences, ['user_id'], $this->updates($requestPreferences));
        $writer->upsert((new TechnicalIssueNotificationPreference)->getTable(), $issuePreferences, ['user_id'], $this->updates($issuePreferences));
        $writer->upsert((new PersonalAccessToken)->getTable(), $tokens, ['token'], $this->updates($tokens));
        $this->upsertSessions($sessions);

        return new DemoStageReport($this->key(), [
            'users' => count($users),
            'profiles' => count($profiles),
            'settings' => count($settings),
            'preferences' => count($commentPreferences) + count($reviewPreferences) + count($requestPreferences) + count($issuePreferences),
            'devices' => count($tokens),
            'sessions' => count($sessions),
        ], microtime(true) - $startedAt);
    }

    /** @return array<string, mixed> */
    private function settings(User $user, int $userIndex, CarbonImmutable $createdAt, CarbonImmutable $updatedAt): array
    {
        return [
            'user_id' => $user->id,
            'locale' => 'ru',
            'timezone' => $this->stable->pick("account:{$userIndex}:timezone", self::TIMEZONES),
            'autoplay' => $this->stable->boolean("account:{$userIndex}:autoplay", 45),
            'remember_volume' => $this->stable->boolean("account:{$userIndex}:remember-volume", 88),
            'volume' => $this->stable->integer("account:{$userIndex}:volume", 25, 100),
            'muted' => false,
            'playback_speed' => $this->stable->pick("account:{$userIndex}:speed", (array) config('account-settings.playback_speeds')),
            'preferred_quality' => $this->stable->pick("account:{$userIndex}:quality", (array) config('playback.supported_qualities')),
            'preferred_variant' => $this->stable->pick("account:{$userIndex}:variant", ['original', 'dubbed', 'subtitled']),
            'subtitles_enabled' => $this->stable->boolean("account:{$userIndex}:subtitles", 43),
            'keyboard_shortcuts_enabled' => $this->stable->boolean("account:{$userIndex}:keyboard", 92),
            'reduced_motion' => $this->stable->boolean("account:{$userIndex}:reduced-motion", 12),
            'collection_default_visibility' => $this->stable->pick(
                "account:{$userIndex}:collection-visibility",
                array_map(static fn (CatalogCollectionVisibility $visibility): string => $visibility->value, CatalogCollectionVisibility::cases()),
            ),
            'settings_version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function commentPreferences(User $user, int $userIndex, CarbonImmutable $createdAt, CarbonImmutable $updatedAt): array
    {
        return [
            'user_id' => $user->id,
            'reply_notifications' => $this->stable->boolean("account:{$userIndex}:comment-replies", 90),
            'reaction_notifications' => $this->stable->boolean("account:{$userIndex}:comment-reactions", 76),
            'moderation_notifications' => true,
            'report_notifications' => true,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function reviewPreferences(User $user, int $userIndex, CarbonImmutable $createdAt, CarbonImmutable $updatedAt): array
    {
        return [
            'user_id' => $user->id,
            'helpful_notifications' => $this->stable->boolean("account:{$userIndex}:review-helpful", 78),
            'moderation_notifications' => true,
            'report_notifications' => true,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function requestPreferences(User $user, int $userIndex, CarbonImmutable $createdAt, CarbonImmutable $updatedAt): array
    {
        return [
            'user_id' => $user->id,
            'requester_updates' => true,
            'voted_updates' => $this->stable->boolean("account:{$userIndex}:request-votes", 82),
            'followed_updates' => $this->stable->boolean("account:{$userIndex}:request-follows", 84),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function issuePreferences(User $user, int $userIndex, CarbonImmutable $createdAt, CarbonImmutable $updatedAt): array
    {
        return [
            'user_id' => $user->id,
            'requester_updates' => true,
            'confirmer_updates' => $this->stable->boolean("account:{$userIndex}:issue-confirmations", 86),
            'follower_updates' => $this->stable->boolean("account:{$userIndex}:issue-follows", 82),
            'support_replies' => true,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return list<array{token: array<string, mixed>, session: array<string, mixed>}>
     */
    private function deviceRows(User $user, int $userIndex, CarbonImmutable $createdAt, CarbonImmutable $updatedAt): array
    {
        $rows = [];
        $count = $this->stable->integer("account:{$userIndex}:device-count", 1, 3);
        $offset = $this->stable->integer("account:{$userIndex}:device-offset", 0, count(self::DEVICES) - 1);

        for ($ordinal = 0; $ordinal < $count; $ordinal++) {
            [$name, $userAgent] = self::DEVICES[($offset + $ordinal) % count(self::DEVICES)];
            $scope = "account:{$userIndex}:device:{$ordinal}";
            $lastUsedAt = $updatedAt->subDays($this->stable->integer($scope.':last-used', 0, 45));

            $rows[] = [
                'token' => [
                    'tokenable_type' => User::class,
                    'tokenable_id' => $user->id,
                    'name' => $name,
                    'token' => hash('sha256', $this->stable->hash($scope.':secret')),
                    'abilities' => json_encode(['mobile:read', 'mobile:write'], JSON_THROW_ON_ERROR),
                    'last_used_at' => $lastUsedAt,
                    'expires_at' => $createdAt->addYears(5),
                    'created_at' => $createdAt->addDays($ordinal),
                    'updated_at' => $lastUsedAt,
                ],
                'session' => [
                    'id' => $this->stable->hash($scope.':session'),
                    'user_id' => $user->id,
                    'ip_address' => '192.0.2.'.$this->stable->integer($scope.':ip', 1, 254),
                    'user_agent' => $userAgent,
                    'payload' => base64_encode(serialize(['demo' => true])),
                    'last_activity' => $lastUsedAt->getTimestamp(),
                ],
            ];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $sessions */
    private function upsertSessions(array $sessions): void
    {
        if ($sessions === [] || config('session.driver') !== 'database') {
            return;
        }

        $table = config('session.table', 'sessions');
        $connection = config('session.connection');

        if (! is_string($table) || $table === '') {
            return;
        }

        DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table($table)
            ->upsert($sessions, ['id'], ['user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']);
    }

    private function createdAt(int $userIndex): CarbonImmutable
    {
        return $this->stable->date(
            "account:{$userIndex}:created-at",
            CarbonImmutable::parse('2022-01-01 00:00:00'),
            CarbonImmutable::parse('2025-12-31 23:59:59'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function updates(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return array_values(array_diff(array_keys($rows[0]), ['user_id', 'token', 'created_at']));
    }
}
