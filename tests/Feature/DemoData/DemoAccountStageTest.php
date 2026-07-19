<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Models\CatalogTitleReviewNotificationPreference;
use App\Models\CommentNotificationPreference;
use App\Models\ContentRequestNotificationPreference;
use App\Models\TechnicalIssueNotificationPreference;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Models\UserProfile;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\ValueObjects\ProfileUsername;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class DemoAccountStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 4,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'session.driver' => 'array',
        ]);
    }

    public function test_stage_creates_complete_idempotent_accounts_profiles_preferences_and_devices(): void
    {
        $options = DemoDataOptions::fromConfig();
        $stage = app(DemoAccountStage::class);
        $firstReport = $stage->run($options);
        $countsAfterFirstRun = $this->domainCounts();
        $secondReport = $stage->run($options);

        $users = User::query()
            ->whereIn('email', collect(range(1, 4))->map(fn (int $index): string => "user{$index}@example.com"))
            ->with(['profile', 'accountSetting'])
            ->orderBy('email')
            ->get();

        $this->assertSame('accounts', $stage->key());
        $this->assertSame(4, $firstReport->counters['users']);
        $this->assertSame(4, $secondReport->counters['users']);
        $this->assertSame($countsAfterFirstRun, $this->domainCounts());
        $this->assertCount(4, $users);
        $this->assertCount(4, $users->pluck('name')->unique());
        $this->assertCount(4, $users->pluck('public_id')->unique());
        $this->assertSame(0, DB::table((string) config('session.table', 'sessions'))->count());

        foreach ($users as $user) {
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue(Hash::check('password', $user->password));
            $this->assertInstanceOf(UserProfile::class, $user->profile);
            $this->assertInstanceOf(UserAccountSetting::class, $user->accountSetting);
            $this->assertTrue(ProfileUsername::isValid($user->profile->normalized_username));
            $this->assertSame($user->profile->username, $user->profile->normalized_username);
            $this->assertGreaterThan(100, mb_strlen((string) $user->profile->biography));
            $this->assertNotNull($user->profile->avatar_path);
            $this->assertNotNull($user->profile->cover_path);
            $this->assertSame('image/webp', $user->profile->avatar_mime_type);
            $this->assertSame('image/webp', $user->profile->cover_mime_type);
            $this->assertStringStartsWith('user-profiles/'.$user->public_id.'/avatar/', (string) $user->profile->avatar_path);
            $this->assertStringStartsWith('user-profiles/'.$user->public_id.'/cover/', (string) $user->profile->cover_path);
            $this->assertStringEndsWith('.webp', (string) $user->profile->avatar_path);
            $this->assertStringEndsWith('.webp', (string) $user->profile->cover_path);
            Storage::disk('uploads')->assertExists($user->profile->avatar_path);
            Storage::disk('uploads')->assertExists($user->profile->cover_path);
            $this->assertSame(
                [320, 320],
                array_slice(getimagesize(Storage::disk('uploads')->path($user->profile->avatar_path)) ?: [], 0, 2),
            );
            $this->assertSame(
                [1280, 360],
                array_slice(getimagesize(Storage::disk('uploads')->path($user->profile->cover_path)) ?: [], 0, 2),
            );
            $this->assertNotNull($user->accountSetting->locale);
            $this->assertNotNull($user->accountSetting->timezone);
            $this->assertNotNull($user->accountSetting->playback_speed);
            $this->assertNotNull($user->accountSetting->preferred_quality);
            $this->assertNotNull($user->accountSetting->collection_default_visibility);

            $deviceCount = PersonalAccessToken::query()
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->count();
            $this->assertGreaterThanOrEqual(1, $deviceCount);
            $this->assertLessThanOrEqual(3, $deviceCount);
        }

        $this->assertSame(4, UserProfile::query()->count());
        $this->assertSame(4, UserAccountSetting::query()->count());
        $this->assertSame(4, CommentNotificationPreference::query()->count());
        $this->assertSame(4, CatalogTitleReviewNotificationPreference::query()->count());
        $this->assertSame(4, ContentRequestNotificationPreference::query()->count());
        $this->assertSame(4, TechnicalIssueNotificationPreference::query()->count());
    }

    public function test_database_session_rows_are_created_only_for_database_driver(): void
    {
        config(['session.driver' => 'database']);

        app(DemoAccountStage::class)->run(DemoDataOptions::fromConfig());

        $sessions = DB::table((string) config('session.table', 'sessions'))->get();

        $this->assertGreaterThanOrEqual(4, $sessions->count());
        $this->assertLessThanOrEqual(12, $sessions->count());
        $this->assertCount(4, $sessions->pluck('user_id')->unique());
        $this->assertNotContains('', $sessions->pluck('user_agent')->all());
    }

    /** @return array<string, int> */
    private function domainCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'profiles' => UserProfile::query()->count(),
            'settings' => UserAccountSetting::query()->count(),
            'comment_preferences' => CommentNotificationPreference::query()->count(),
            'review_preferences' => CatalogTitleReviewNotificationPreference::query()->count(),
            'request_preferences' => ContentRequestNotificationPreference::query()->count(),
            'issue_preferences' => TechnicalIssueNotificationPreference::query()->count(),
            'tokens' => PersonalAccessToken::query()->count(),
        ];
    }
}
