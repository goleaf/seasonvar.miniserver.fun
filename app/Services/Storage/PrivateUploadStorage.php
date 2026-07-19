<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class PrivateUploadStorage
{
    public function store(UploadedFile $file, string $directory): StoredPrivateUpload
    {
        $disk = (string) config('uploads.disk', 'uploads');
        $visibility = (string) config('uploads.visibility', 'private');

        if ($visibility !== 'private') {
            throw new RuntimeException('Uploaded files must use private visibility.');
        }

        $path = Storage::disk($disk)->putFile(
            $this->normalizeDirectory($directory),
            $file,
            ['visibility' => $visibility],
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Не удалось сохранить загруженный файл.');
        }

        return new StoredPrivateUpload(
            disk: $disk,
            path: $path,
            visibility: $visibility,
            mimeType: $file->getMimeType(),
            size: $file->getSize() ?: 0,
        );
    }

    public function delete(StoredPrivateUpload|string $upload): bool
    {
        $disk = $upload instanceof StoredPrivateUpload
            ? $upload->disk
            : (string) config('uploads.disk', 'uploads');

        $path = $upload instanceof StoredPrivateUpload ? $upload->path : $upload;
        $path = $this->safeRelativePath($path);

        if ($path === null) {
            return false;
        }

        return Storage::disk($disk)->delete($path);
    }

    public function storeBytes(string $bytes, string $directory, string $extension, string $mimeType): StoredPrivateUpload
    {
        if ($bytes === '' || preg_match('/^[a-z0-9]+$/D', $extension) !== 1) {
            throw new InvalidArgumentException('Некорректное содержимое производного файла.');
        }

        $disk = (string) config('uploads.disk', 'uploads');
        $visibility = (string) config('uploads.visibility', 'private');

        if ($visibility !== 'private') {
            throw new RuntimeException('Uploaded files must use private visibility.');
        }

        $path = $this->normalizeDirectory($directory).'/'.Str::uuid().'.'.$extension;

        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => $visibility])) {
            throw new RuntimeException('Не удалось сохранить производный файл.');
        }

        return new StoredPrivateUpload(
            disk: $disk,
            path: $path,
            visibility: $visibility,
            mimeType: $mimeType,
            size: strlen($bytes),
        );
    }

    private function normalizeDirectory(string $directory): string
    {
        $directory = $this->safeRelativePath($directory);

        if ($directory === null) {
            throw new InvalidArgumentException('Некорректный каталог загрузки.');
        }

        return $directory;
    }

    private function safeRelativePath(string $path): ?string
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));

        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }

        return implode('/', $segments);
    }
}
