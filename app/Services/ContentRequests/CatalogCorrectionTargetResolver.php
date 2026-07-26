<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentCorrectionTargetData;
use App\DTOs\ContentRequests\ContentRequestInput;
use App\Enums\ContentCorrectionField;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Model;

final readonly class CatalogCorrectionTargetResolver
{
    public function __construct(private ContentCorrectionTargetKey $keys) {}

    public function resolveForTitleId(
        int $catalogTitleId,
        ContentCorrectionField $field,
        ?int $targetId,
        ?User $viewer,
    ): ContentCorrectionTargetData {
        $title = CatalogTitle::query()->availableTo($viewer)->find($catalogTitleId);

        if (! $title instanceof CatalogTitle) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        return $this->resolve($title, $field, $targetId, $viewer);
    }

    public function resolve(
        CatalogTitle $title,
        ContentCorrectionField $field,
        ?int $targetId,
        ?User $viewer,
    ): ContentCorrectionTargetData {
        $relation = $field->relationName();

        if ($relation !== null) {
            return $this->relation($title, $field, $relation, $targetId);
        }

        if (in_array($field, [ContentCorrectionField::Episode, ContentCorrectionField::Subtitles], true)) {
            return $this->episode($title, $field, $targetId, $viewer);
        }

        if ($targetId !== null) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        return new ContentCorrectionTargetData(
            field: $field,
            type: $field->requestType(),
            storedField: $field->storedField(),
            targetKey: $this->keys->make($field),
            currentValue: $this->scalarValue($title, $field),
            proposedValue: '',
        );
    }

    public function assertInput(ContentRequestInput $input): void
    {
        if ($input->correctionTargetKey === null) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        $parsed = $this->keys->parse($input->correctionTargetKey);

        if ($parsed === null
            || $input->catalogTitleId === null
            || $parsed['field']->storedField() !== $input->correctionField
            || $parsed['field']->requestType() !== $input->type) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        $resolved = $this->resolveForTitleId(
            $input->catalogTitleId,
            $parsed['field'],
            $parsed['target_id'],
            null,
        );

        if ($resolved->targetKey !== $input->correctionTargetKey
            || $resolved->currentValue !== (string) $input->currentValue
            || $resolved->seasonId !== $input->seasonId
            || $resolved->episodeId !== $input->episodeId) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }
    }

    private function relation(
        CatalogTitle $title,
        ContentCorrectionField $field,
        string $relation,
        ?int $targetId,
    ): ContentCorrectionTargetData {
        if ($targetId === null) {
            return new ContentCorrectionTargetData(
                field: $field,
                type: $field->requestType(),
                storedField: $field->storedField(),
                targetKey: $this->keys->make($field),
                currentValue: __('requests.corrections.value_missing'),
                proposedValue: '',
            );
        }

        $query = $title->{$relation}();

        if ($field === ContentCorrectionField::Tag) {
            $query->publiclyEligible();
        }

        $target = $query->whereKey($targetId)->first();

        if (! $target instanceof Model) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        $name = PlainText::clean($target->getAttribute('name'), 240);

        return new ContentCorrectionTargetData(
            field: $field,
            type: $field->requestType(),
            storedField: $field->storedField(),
            targetKey: $this->keys->make($field, $targetId),
            currentValue: $name,
            proposedValue: $field === ContentCorrectionField::Tag
                ? __('requests.corrections.remove_tag', ['tag' => $name])
                : '',
        );
    }

    private function episode(
        CatalogTitle $title,
        ContentCorrectionField $field,
        ?int $episodeId,
        ?User $viewer,
    ): ContentCorrectionTargetData {
        if ($episodeId === null) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        $episode = Episode::query()
            ->availableTo($viewer)
            ->whereIn('season_id', Season::query()
                ->availableTo($viewer)
                ->where('catalog_title_id', $title->id)
                ->select('id'))
            ->find($episodeId);

        if (! $episode instanceof Episode) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        $currentValue = $field === ContentCorrectionField::Subtitles
            ? $this->subtitleValue($episode)
            : __('requests.corrections.episode_value', [
                'number' => $episode->number,
                'title' => PlainText::clean($episode->title, 240),
            ]);

        return new ContentCorrectionTargetData(
            field: $field,
            type: $field->requestType(),
            storedField: $field->storedField(),
            targetKey: $this->keys->make($field, $episode->id),
            currentValue: $currentValue,
            proposedValue: '',
            seasonId: $episode->season_id,
            episodeId: $episode->id,
        );
    }

    private function subtitleValue(Episode $episode): string
    {
        $available = LicensedMedia::query()
            ->published()
            ->where('episode_id', $episode->id)
            ->where('has_subtitles', true)
            ->exists();

        return $available
            ? __('requests.corrections.subtitles_available', ['number' => $episode->number])
            : __('requests.corrections.subtitles_missing', ['number' => $episode->number]);
    }

    private function scalarValue(CatalogTitle $title, ContentCorrectionField $field): string
    {
        return match ($field) {
            ContentCorrectionField::Title => PlainText::clean($title->display_title, 240),
            ContentCorrectionField::Year => $title->year !== null
                ? (string) $title->year
                : __('requests.corrections.value_missing'),
            ContentCorrectionField::Poster => filled($title->poster_url)
                ? __('requests.corrections.poster_present')
                : __('requests.corrections.poster_missing'),
            ContentCorrectionField::Description => PlainText::clean(
                filled($title->description) ? $title->description : __('requests.corrections.value_missing'),
                2_000,
            ),
            default => throw new ContentRequestActionException('requests.errors.invalid_target'),
        };
    }
}
