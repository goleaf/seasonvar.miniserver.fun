<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SeasonvarImportErrorSanitizer
{
    public function fromException(?Throwable $exception): string
    {
        if ($exception === null) {
            return 'Фоновая задача импорта завершилась без описания ошибки.';
        }

        if ($exception instanceof SeasonvarSourceRequestException) {
            return $this->sanitize($exception->getMessage());
        }

        if ($exception instanceof ValidationException) {
            return 'Ответ провайдера не прошёл проверку структуры данных.';
        }

        if ($exception instanceof ConnectionException) {
            return 'Не удалось установить соединение с провайдером.';
        }

        return $this->sanitize($exception->getMessage());
    }

    public function sanitize(?string $message): string
    {
        $message = Str::squish((string) $message);

        if ($message === '') {
            return 'Подробности ошибки недоступны.';
        }

        $message = preg_replace('/\b(?:Bearer|Basic)\s+\S+/iu', '[учётные данные скрыты]', $message) ?? $message;
        $message = preg_replace('~https?://[^\s]+~iu', '[ссылка скрыта]', $message) ?? $message;
        $message = preg_replace('~(?<![\pL\pN])/(?:[\w.-]+/)+[\w.-]+(?::\d+)?~u', '[путь скрыт]', $message) ?? $message;
        $message = preg_replace('/\b(token|secret|signature|key|password)=\S+/iu', '$1=[скрыто]', $message) ?? $message;

        return Str::limit(Str::squish($message), 500, '…');
    }
}
