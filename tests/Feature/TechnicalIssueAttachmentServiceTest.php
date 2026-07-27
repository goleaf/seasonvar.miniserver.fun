<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Services\TechnicalIssues\TechnicalIssueAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TechnicalIssueAttachmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'uploads.disk' => 'uploads',
            'uploads.visibility' => 'private',
        ]);
    }

    public function test_it_reencodes_and_stores_a_valid_private_screenshot(): void
    {
        $attachments = app(TechnicalIssueAttachmentService::class)->store(
            [UploadedFile::fake()->image('screenshot.png', 320, 180)],
            '01K123456789ABCDEFGHJKMNPQ',
        );

        $this->assertCount(1, $attachments);

        $attachment = $attachments[0];

        $this->assertSame('image/png', $attachment->mimeType);
        $this->assertSame('png', $attachment->extension);
        $this->assertSame(320, $attachment->width);
        $this->assertSame(180, $attachment->height);
        Storage::disk('uploads')->assertExists($attachment->path);
    }

    public function test_it_rejects_malformed_image_bytes_with_a_safe_domain_error(): void
    {
        try {
            app(TechnicalIssueAttachmentService::class)->store(
                [UploadedFile::fake()->createWithContent('screenshot.png', 'not an image')],
                '01K123456789ABCDEFGHJKMNPQ',
            );

            $this->fail('Malformed image bytes were accepted.');
        } catch (TechnicalIssueActionException $exception) {
            $this->assertSame('issues.errors.invalid_attachment', $exception->translationKey);
            $this->assertSame('issues.errors.invalid_attachment', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }
}
