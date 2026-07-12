<?php

namespace App\Models\Concerns;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait HasPublicationAvailability
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $this->applyPublicationState($query)
            ->where($this->qualifyColumn('audience'), ContentAudience::Public->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailableTo(Builder $query, ?User $user): Builder
    {
        $query = $this->applyPublicationState($query);

        if ($user === null) {
            return $query->where($this->qualifyColumn('audience'), ContentAudience::Public->value);
        }

        return $query->whereIn($this->qualifyColumn('audience'), [
            ContentAudience::Public->value,
            ContentAudience::Authenticated->value,
        ]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    private function applyPublicationState(Builder $query): Builder
    {
        $now = now();

        $query
            ->where($this->qualifyColumn($this->publicationStatusColumn()), $this->publishedStatusValue())
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull($this->qualifyColumn('available_from'))
                    ->orWhere($this->qualifyColumn('available_from'), '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull($this->qualifyColumn('available_until'))
                    ->orWhere($this->qualifyColumn('available_until'), '>=', $now);
            });

        if ($this->usesLegacyPublicationFlag()) {
            $query->where($this->qualifyColumn('is_published'), true);
        }

        if ($this->usesPublishedAtGate()) {
            $query->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull($this->qualifyColumn('published_at'))
                    ->orWhere($this->qualifyColumn('published_at'), '<=', $now);
            });
        }

        return $query;
    }

    protected function publicationStatusColumn(): string
    {
        return 'publication_status';
    }

    protected function publishedStatusValue(): string
    {
        return PublicationStatus::Published->value;
    }

    protected function usesLegacyPublicationFlag(): bool
    {
        return false;
    }

    protected function usesPublishedAtGate(): bool
    {
        return false;
    }
}
