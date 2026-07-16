<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\StoredTechnicalIssueAttachment;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Services\Storage\PrivateUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

final readonly class TechnicalIssueAttachmentService
{
    public function __construct(private PrivateUploadStorage $uploads) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<StoredTechnicalIssueAttachment>
     */
    public function store(array $files, string $issuePublicId): array
    {
        if (count($files) > (int) config('technical-issues.maximum_attachments', 3)) {
            throw new TechnicalIssueActionException('issues.errors.too_many_attachments');
        }

        $stored = [];

        try {
            foreach (array_values($files) as $index => $file) {
                $stored[] = $this->storeOne($file, $issuePublicId, $index + 1);
            }
        } catch (Throwable $exception) {
            foreach ($stored as $attachment) {
                $this->deleteBestEffort($attachment->path);
            }

            throw $exception;
        }

        return $stored;
    }

    public function delete(string $path): void
    {
        if (! $this->uploads->delete($path)) {
            throw new TechnicalIssueActionException('issues.errors.attachment_delete_failed');
        }
    }

    private function storeOne(UploadedFile $file, string $issuePublicId, int $position): StoredTechnicalIssueAttachment
    {
        $maximumBytes = max(1, (int) config('uploads.max_image_kilobytes', 2048)) * 1024;
        $originalExtension = Str::lower($file->getClientOriginalExtension());

        if (! $file->isValid()
            || ! in_array($originalExtension, ['jpg', 'jpeg', 'png', 'webp'], true)
            || ($file->getSize() ?: 0) < 1
            || ($file->getSize() ?: 0) > $maximumBytes) {
            throw new TechnicalIssueActionException('issues.errors.invalid_attachment');
        }

        $bytes = file_get_contents($file->getRealPath());
        $imageInfo = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        $mime = is_array($imageInfo) ? $imageInfo['mime'] : null;
        $format = match ($mime) {
            'image/jpeg' => ['extension' => 'jpg', 'encode' => 'jpeg'],
            'image/png' => ['extension' => 'png', 'encode' => 'png'],
            'image/webp' => ['extension' => 'webp', 'encode' => 'webp'],
            default => null,
        };
        $width = is_array($imageInfo) ? (int) $imageInfo[0] : 0;
        $height = is_array($imageInfo) ? (int) $imageInfo[1] : 0;
        $maxDimension = max(1, (int) config('technical-issues.maximum_image_dimension', 6000));
        $maxPixels = max(1, (int) config('technical-issues.maximum_image_pixels', 24_000_000));

        if ($format === null || $width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension || $width * $height > $maxPixels) {
            throw new TechnicalIssueActionException('issues.errors.invalid_attachment');
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new TechnicalIssueActionException('issues.errors.invalid_attachment');
        }

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $encoded = match ($format['encode']) {
                'jpeg' => imagejpeg($image, null, 88),
                'png' => imagepng($image, null, 6),
                'webp' => imagewebp($image, null, 88),
            };
            $reencoded = ob_get_clean();
        } finally {
            imagedestroy($image);

            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }

        if (! $encoded || $reencoded === '' || mb_strlen($reencoded, '8bit') > $maximumBytes) {
            throw new TechnicalIssueActionException('issues.errors.invalid_attachment');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'seasonvar-issue-');

        if (! is_string($temporaryPath) || file_put_contents($temporaryPath, $reencoded, LOCK_EX) === false) {
            throw new TechnicalIssueActionException('issues.errors.attachment_store_failed');
        }

        try {
            $temporary = new UploadedFile(
                $temporaryPath,
                Str::uuid().'.'.$format['extension'],
                $mime,
                UPLOAD_ERR_OK,
                true,
            );
            $stored = $this->uploads->store($temporary, 'technical-issues/'.substr($issuePublicId, 0, 2).'/'.$issuePublicId);
        } finally {
            @unlink($temporaryPath);
        }

        return new StoredTechnicalIssueAttachment(
            disk: $stored->disk,
            path: $stored->path,
            displayName: 'screenshot-'.$position.'.'.$format['extension'],
            mimeType: $mime,
            extension: $format['extension'],
            sizeBytes: mb_strlen($reencoded, '8bit'),
            width: $width,
            height: $height,
            contentHash: hash('sha256', $reencoded),
        );
    }

    private function deleteBestEffort(string $path): void
    {
        try {
            $this->uploads->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
