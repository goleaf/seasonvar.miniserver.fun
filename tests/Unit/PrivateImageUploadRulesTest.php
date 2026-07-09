<?php

namespace Tests\Unit;

use App\Support\Uploads\PrivateImageUploadRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PrivateImageUploadRulesTest extends TestCase
{
    public function test_private_image_upload_rules_accept_safe_images(): void
    {
        $validator = Validator::make([
            'poster' => UploadedFile::fake()->image('poster.jpg')->size(512),
        ], [
            'poster' => PrivateImageUploadRules::required(),
        ]);

        $this->assertTrue($validator->passes());
    }

    public function test_private_image_upload_rules_reject_unsafe_extensions(): void
    {
        $validator = Validator::make([
            'poster' => UploadedFile::fake()->image('poster.txt')->size(512),
        ], [
            'poster' => PrivateImageUploadRules::required(),
        ]);

        $this->assertFalse($validator->passes());
    }

    public function test_private_image_upload_rules_reject_oversized_images(): void
    {
        config(['uploads.max_image_kilobytes' => 1024]);

        $validator = Validator::make([
            'poster' => UploadedFile::fake()->image('poster.jpg')->size(2048),
        ], [
            'poster' => PrivateImageUploadRules::required(),
        ]);

        $this->assertFalse($validator->passes());
    }
}
