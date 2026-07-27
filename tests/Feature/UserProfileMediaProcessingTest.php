<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Profiles\UserProfileMediaService;
use App\Services\Profiles\UserProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class UserProfileMediaProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'uploads.disk' => 'uploads',
            'uploads.visibility' => 'private',
        ]);
    }

    public function test_avatar_and_cover_are_reencoded_as_design_sized_webp_files(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $profiles = app(UserProfileService::class);
        $media = app(UserProfileMediaService::class);
        $profile = $profiles->forUser($user);

        $profile = $media->replace(
            $user,
            $profile,
            'avatar',
            UploadedFile::fake()->image('client-avatar.png', 800, 500),
        );
        $profile = $media->replace(
            $user,
            $profile,
            'cover',
            UploadedFile::fake()->image('client-cover.jpg', 1800, 1200),
        );

        $this->assertSame('image/webp', $profile->avatar_mime_type);
        $this->assertSame('image/webp', $profile->cover_mime_type);
        $this->assertStringEndsWith('.webp', (string) $profile->avatar_path);
        $this->assertStringEndsWith('.webp', (string) $profile->cover_path);
        $this->assertStringStartsWith('user-profiles/'.$user->public_id.'/avatar/', (string) $profile->avatar_path);
        $this->assertStringStartsWith('user-profiles/'.$user->public_id.'/cover/', (string) $profile->cover_path);
        Storage::disk('uploads')->assertExists($profile->avatar_path);
        Storage::disk('uploads')->assertExists($profile->cover_path);

        $avatar = getimagesize(Storage::disk('uploads')->path($profile->avatar_path));
        $cover = getimagesize(Storage::disk('uploads')->path($profile->cover_path));

        $this->assertIsArray($avatar);
        $this->assertIsArray($cover);
        $this->assertSame([320, 320, IMAGETYPE_WEBP, 'image/webp'], [$avatar[0], $avatar[1], $avatar[2], $avatar['mime']]);
        $this->assertSame([1280, 360, IMAGETYPE_WEBP, 'image/webp'], [$cover[0], $cover[1], $cover[2], $cover['mime']]);
    }

    public function test_malformed_profile_image_bytes_are_rejected_before_storage(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $profile = app(UserProfileService::class)->forUser($user);

        try {
            app(UserProfileMediaService::class)->replace(
                $user,
                $profile,
                'avatar',
                UploadedFile::fake()->createWithContent('client-avatar.png', 'not an image'),
            );

            $this->fail('Malformed profile image bytes were accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Формат или размеры изображения профиля недопустимы.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('uploads')->allFiles());
        $this->assertNull($profile->fresh()?->avatar_path);
    }
}
