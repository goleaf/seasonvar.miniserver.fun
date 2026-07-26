<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlaybackTransitionData
{
    /**
     * @param  array{url: string, mimeType: string|null, format: string|null, expiresAt: string|null}|null  $source
     * @param  array{
     *     seasonId: int,
     *     episodeId: int,
     *     mediaId: int,
     *     variant: string,
     *     quality: string,
     *     format: string,
     *     query: array<string, string>
     * }|null  $selection
     * @param  array{title: string, season: string, episode: string, media: string, translation: string, quality: string, subtitles: string}|null  $labels
     * @param  list<array{mediaId: int, label: string, detail: string|null, active: bool, variant: string, quality: string, format: string, hasSubtitles: bool, subtitleLanguage: string|null, subtitles: string, query: array<string, string>}>|null  $translations
     * @param  array{
     *     previous: array{id: int, label: string, title: string|null}|null,
     *     next: array{id: int, label: string, title: string|null}|null
     * }|null  $navigation
     * @param  array{title: string, artist: string, album: string, artwork: string|null}|null  $mediaSession
     * @param  array{enabled: bool, token: string, sequence: int}|null  $progress
     */
    private function __construct(
        private string $status,
        private string $message,
        private ?string $contextKey = null,
        private ?array $source = null,
        private ?array $selection = null,
        private ?array $labels = null,
        private ?array $translations = null,
        private ?array $navigation = null,
        private ?array $mediaSession = null,
        private ?array $progress = null,
        private ?string $noticeCode = null,
    ) {}

    /**
     * @param  array{url: string, mimeType: string|null, format: string|null, expiresAt: string|null}  $source
     * @param  array{
     *     seasonId: int,
     *     episodeId: int,
     *     mediaId: int,
     *     variant: string,
     *     quality: string,
     *     format: string,
     *     query: array<string, string>
     * }  $selection
     * @param  array{title: string, season: string, episode: string, media: string, translation: string, quality: string, subtitles: string}  $labels
     * @param  list<array{mediaId: int, label: string, detail: string|null, active: bool, variant: string, quality: string, format: string, hasSubtitles: bool, subtitleLanguage: string|null, subtitles: string, query: array<string, string>}>  $translations
     * @param  array{
     *     previous: array{id: int, label: string, title: string|null}|null,
     *     next: array{id: int, label: string, title: string|null}|null
     * }  $navigation
     * @param  array{title: string, artist: string, album: string, artwork: string|null}  $mediaSession
     * @param  array{enabled: bool, token: string, sequence: int}  $progress
     */
    public static function ready(
        string $message,
        string $contextKey,
        array $source,
        array $selection,
        array $labels,
        array $translations,
        array $navigation,
        array $mediaSession,
        array $progress,
        ?string $noticeCode,
    ): self {
        return new self(
            status: 'ready',
            message: $message,
            contextKey: $contextKey,
            source: $source,
            selection: $selection,
            labels: $labels,
            translations: $translations,
            navigation: $navigation,
            mediaSession: $mediaSession,
            progress: $progress,
            noticeCode: $noticeCode,
        );
    }

    public static function unavailable(string $message): self
    {
        return new self(status: 'unavailable', message: $message);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    /**
     * @return array{
     *     status: 'ready',
     *     message: string,
     *     contextKey: string,
     *     source: array{url: string, mimeType: string|null, format: string|null, expiresAt: string|null},
     *     selection: array{
     *         seasonId: int,
     *         episodeId: int,
     *         mediaId: int,
     *         variant: string,
     *         quality: string,
     *         format: string,
     *         query: array<string, string>
     *     },
     *     labels: array{title: string, season: string, episode: string, media: string, translation: string, quality: string, subtitles: string},
     *     translations: list<array{mediaId: int, label: string, detail: string|null, active: bool, variant: string, quality: string, format: string, hasSubtitles: bool, subtitleLanguage: string|null, subtitles: string, query: array<string, string>}>,
     *     navigation: array{
     *         previous: array{id: int, label: string, title: string|null}|null,
     *         next: array{id: int, label: string, title: string|null}|null
     *     },
     *     mediaSession: array{title: string, artist: string, album: string, artwork: string|null},
     *     progress: array{enabled: bool, token: string, sequence: int},
     *     noticeCode: string|null
     * }|array{status: 'unavailable', message: string}
     */
    public function toArray(): array
    {
        if (! $this->isReady()) {
            return [
                'status' => 'unavailable',
                'message' => $this->message,
            ];
        }

        return [
            'status' => 'ready',
            'message' => $this->message,
            'contextKey' => $this->contextKey,
            'source' => $this->source,
            'selection' => $this->selection,
            'labels' => $this->labels,
            'translations' => $this->translations,
            'navigation' => $this->navigation,
            'mediaSession' => $this->mediaSession,
            'progress' => $this->progress,
            'noticeCode' => $this->noticeCode,
        ];
    }
}
