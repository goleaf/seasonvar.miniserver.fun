<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\DTOs\DemoData\DemoTitleContext;
use App\Enums\ContentRequestExternalProvider;
use App\Enums\ContentRequestPriority;
use App\Enums\ContentRequestRejectionReason;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Models\ContentRequest;
use App\Models\ContentRequestClarification;
use App\Models\ContentRequestExternalIdentifier;
use App\Models\ContentRequestFollower;
use App\Models\ContentRequestSourceLink;
use App\Models\ContentRequestStatusHistory;
use App\Models\ContentRequestVote;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoPersonaFactory;
use App\Services\DemoData\DemoRussianText;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use LogicException;

final readonly class DemoContentRequestStage implements DemoDataStage
{
    public function __construct(
        private DemoStableValue $stable,
        private DemoPersonaFactory $personas,
        private DemoRussianText $text,
        private CatalogSearchNormalizer $normalizer,
    ) {}

    public function key(): string
    {
        return 'content_requests';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $options->assertEnvironment(app()->environment());

        return $this->repairKnownDemoUsers($options, $progress);
    }

    public function repairKnownDemoUsers(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $writer = new DemoBulkWriter($options);
        $selector = new DemoTitleSelector($options);
        $users = $this->users($options);
        $requestRows = [];
        $specs = [];
        $requestCounter = 0;

        foreach ($users as $userIndex => $user) {
            $count = $this->stable->integer(
                "requests:user:{$userIndex}:count",
                $options->requestMinimum,
                $options->requestMaximum,
            );
            $titleIds = $selector->selectedIds($userIndex)->take($count)->values()->all();
            $contexts = $selector->contexts($titleIds)->values();

            if ($contexts->isEmpty()) {
                throw new LogicException('Для демонстрационных заявок нужен хотя бы один опубликованный сериал.');
            }

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                /** @var DemoTitleContext $context */
                $context = $contexts->get($ordinal % $contexts->count());
                $globalOrdinal = $requestCounter++;
                $type = $this->enumAt(ContentRequestType::cases(), $globalOrdinal);
                $status = $this->enumAt(ContentRequestStatus::cases(), $globalOrdinal);
                $priority = $this->enumAt(ContentRequestPriority::cases(), $globalOrdinal);
                $persona = $this->personas->make($userIndex);
                $copy = $this->text->request($persona, $this->typeName($type), $ordinal);
                $title = sprintf('%s — %s: %s', $persona->givenName, $copy['title'], $context->displayTitle);
                $normalizedTitle = $this->normalizer->key($title);
                $submissionKey = $this->stable->hash("requests:{$globalOrdinal}:submission");
                $exactHash = $this->stable->hash("requests:{$globalOrdinal}:identity");
                $createdAt = $this->createdAt($userIndex, $ordinal);
                $hasCatalogTarget = $type->requiresCatalogTitle();
                $correctionField = $this->correctionField($type, $globalOrdinal);
                $requestRows[] = [
                    'public_id' => $this->stable->uuid("requests:{$globalOrdinal}:public"),
                    'requester_id' => $user->id,
                    'type' => $type->value,
                    'status' => $status->value,
                    'priority' => $priority->value,
                    'title' => $title,
                    'normalized_title' => $normalizedTitle,
                    'normalized_title_hash' => hash('sha256', $normalizedTitle),
                    'original_title' => $context->displayTitle,
                    'alternative_title' => 'Альтернативное название для проверки карточки',
                    'release_year' => $context->year ?? 2024,
                    'country' => $this->stable->pick("requests:{$globalOrdinal}:country", ['Россия', 'Канада', 'Франция', 'Япония']),
                    'content_locale' => 'ru',
                    'original_language' => $this->stable->pick("requests:{$globalOrdinal}:language", ['ru', 'en', 'fr', 'ja']),
                    'audio_language' => $type === ContentRequestType::Translation ? 'ru' : null,
                    'subtitle_language' => $type === ContentRequestType::Subtitles ? 'ru' : null,
                    'translation_type' => $type === ContentRequestType::Translation ? 'voice_over' : null,
                    'translation_studio' => $type === ContentRequestType::Translation ? 'Студия Север' : null,
                    'catalog_title_id' => $hasCatalogTarget ? $context->titleId : null,
                    'season_id' => in_array($type, [ContentRequestType::Episode, ContentRequestType::EpisodeListCorrection], true)
                        ? $context->firstSeasonId : null,
                    'episode_id' => $type === ContentRequestType::Episode ? $context->firstEpisodeId : null,
                    'season_number' => $type === ContentRequestType::Season ? 1 : null,
                    'season_kind' => $type === ContentRequestType::Season ? 'regular' : null,
                    'episode_number' => $type === ContentRequestType::Episode ? 1 : null,
                    'episode_release_date' => $type === ContentRequestType::Episode ? '2025-03-15' : null,
                    'current_quality' => $type === ContentRequestType::QualityUpgrade ? '720p' : null,
                    'requested_quality' => $type === ContentRequestType::QualityUpgrade ? '1080p' : null,
                    'correction_field' => $correctionField,
                    'current_value' => $correctionField === null ? null : 'В карточке указано прежнее значение.',
                    'proposed_value' => $correctionField === null ? null : 'Предлагаю уточнённое значение по открытым источникам.',
                    'explanation' => $copy['description'],
                    'different_explanation' => $status === ContentRequestStatus::Duplicate
                        ? 'Похожая заявка относится к другой версии перевода и требует отдельной проверки.' : null,
                    'exact_identity_hash' => $exactHash,
                    'active_identity_key' => $status->isOpen() ? $exactHash : null,
                    'submission_key' => $submissionKey,
                    'probable_duplicate' => in_array($status, [ContentRequestStatus::Duplicate, ContentRequestStatus::Merged], true),
                    'is_public' => $globalOrdinal % 5 !== 0,
                    'rejection_reason' => $status === ContentRequestStatus::Rejected
                        ? $this->rejectionReason($globalOrdinal)->value : null,
                    'public_note' => 'Заявка создана посетителем и доступна для предметного обсуждения.',
                    'private_moderator_note' => 'Проверить связи с карточкой и приложенные источники перед следующим переходом.',
                    'merged_into_id' => null,
                    'completed_catalog_title_id' => $status === ContentRequestStatus::Completed ? $context->titleId : null,
                    'completed_season_id' => $status === ContentRequestStatus::Completed ? $context->firstSeasonId : null,
                    'completed_episode_id' => $status === ContentRequestStatus::Completed ? $context->firstEpisodeId : null,
                    'completed_media_id' => $status === ContentRequestStatus::Completed ? $context->licensedMediaId : null,
                    'source_page_id' => null,
                    'import_run_id' => null,
                    'version' => $status === ContentRequestStatus::Submitted ? 1 : 2,
                    'partial_completed_at' => $status === ContentRequestStatus::PartiallyCompleted ? $createdAt->addDays(3) : null,
                    'completed_at' => $status === ContentRequestStatus::Completed ? $createdAt->addDays(5) : null,
                    'withdrawn_at' => $status === ContentRequestStatus::Withdrawn ? $createdAt->addDays(2) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $status === ContentRequestStatus::Submitted ? $createdAt : $createdAt->addDay(),
                ];
                $specs[$submissionKey] = [
                    'global_ordinal' => $globalOrdinal,
                    'requester_id' => (int) $user->id,
                    'status' => $status,
                    'created_at' => $createdAt,
                ];
            }

            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        $writer->upsert(
            (new ContentRequest)->getTable(),
            $requestRows,
            ['submission_key'],
            $this->updates($requestRows, ['submission_key', 'created_at']),
        );

        $requests = ContentRequest::query()
            ->whereIn('submission_key', array_keys($specs))
            ->get(['id', 'submission_key', 'requester_id', 'status'])
            ->keyBy('submission_key');
        $childRows = $this->childRows($requests, $specs, $users);

        $writer->upsert((new ContentRequestVote)->getTable(), $childRows['votes'], ['content_request_id', 'user_id'], ['updated_at']);
        $writer->upsert((new ContentRequestFollower)->getTable(), $childRows['followers'], ['content_request_id', 'user_id'], ['updated_at']);
        $writer->upsert((new ContentRequestStatusHistory)->getTable(), $childRows['histories'], ['idempotency_key'], $this->updates($childRows['histories'], ['idempotency_key', 'created_at']));
        $writer->upsert((new ContentRequestSourceLink)->getTable(), $childRows['links'], ['content_request_id', 'url_hash'], $this->updates($childRows['links'], ['content_request_id', 'url_hash', 'created_at']));
        $writer->upsert((new ContentRequestExternalIdentifier)->getTable(), $childRows['identifiers'], ['content_request_id', 'provider', 'normalized_identifier'], ['identifier', 'updated_at']);
        $writer->upsert((new ContentRequestClarification)->getTable(), $childRows['clarifications'], ['submission_key'], $this->updates($childRows['clarifications'], ['submission_key', 'created_at']));

        return new DemoStageReport($this->key(), [
            'requests' => count($requestRows),
            'votes' => count($childRows['votes']),
            'followers' => count($childRows['followers']),
            'histories' => count($childRows['histories']),
            'source_links' => count($childRows['links']),
            'identifiers' => count($childRows['identifiers']),
            'clarifications' => count($childRows['clarifications']),
        ], microtime(true) - $startedAt);
    }

    /**
     * @param  Collection<string, ContentRequest>  $requests
     * @param  array<string, array{global_ordinal: int, requester_id: int, status: ContentRequestStatus, created_at: CarbonImmutable}>  $specs
     * @param  Collection<int, User>  $users
     * @return array{votes: list<array<string, mixed>>, followers: list<array<string, mixed>>, histories: list<array<string, mixed>>, links: list<array<string, mixed>>, identifiers: list<array<string, mixed>>, clarifications: list<array<string, mixed>>}
     */
    private function childRows(Collection $requests, array $specs, Collection $users): array
    {
        $rows = ['votes' => [], 'followers' => [], 'histories' => [], 'links' => [], 'identifiers' => [], 'clarifications' => []];
        $providers = ContentRequestExternalProvider::cases();
        $statuses = ContentRequestStatus::cases();

        foreach ($specs as $submissionKey => $spec) {
            $request = $requests->get($submissionKey);

            if (! $request instanceof ContentRequest) {
                throw new LogicException('Не удалось найти созданную демонстрационную заявку.');
            }

            $ordinal = $spec['global_ordinal'];
            $requesterIndex = $users->search(fn (User $user): bool => (int) $user->id === $spec['requester_id']);
            $firstParticipant = $users->get(((int) $requesterIndex % $users->count()) + 1) ?? $users->first();
            $secondParticipant = $users->get((((int) $requesterIndex + 1) % $users->count()) + 1) ?? $users->last();
            $createdAt = $spec['created_at'];

            foreach ([$firstParticipant, $secondParticipant] as $offset => $participant) {
                if (! $participant instanceof User || (int) $participant->id === (int) $request->requester_id) {
                    continue;
                }

                $row = [
                    'content_request_id' => $request->id,
                    'user_id' => $participant->id,
                    'created_at' => $createdAt->addHours($offset + 1),
                    'updated_at' => $createdAt->addHours($offset + 1),
                ];
                $rows['votes'][] = $row;
                $rows['followers'][] = $row;
            }

            $rows['histories'][] = $this->historyRow($request, $ordinal, null, ContentRequestStatus::Submitted, 0, $createdAt);

            if ($spec['status'] !== ContentRequestStatus::Submitted) {
                $rows['histories'][] = $this->historyRow($request, $ordinal, ContentRequestStatus::Submitted, $spec['status'], 1, $createdAt->addDay());
            }

            $linkCount = $this->stable->integer("requests:{$ordinal}:link-count", 1, 3);

            for ($linkOrdinal = 0; $linkOrdinal < $linkCount; $linkOrdinal++) {
                $provider = $providers[($ordinal + $linkOrdinal) % count($providers)];
                $url = sprintf('https://example.com/sources/%s/%s', $provider->value, substr($this->stable->hash("requests:{$ordinal}:link:{$linkOrdinal}"), 0, 20));
                $rows['links'][] = [
                    'content_request_id' => $request->id,
                    'added_by_id' => $request->requester_id,
                    'verified_by_id' => $firstParticipant?->id,
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                    'provider' => $provider->value,
                    'is_public' => $linkOrdinal !== 2,
                    'verified_at' => $createdAt->addDays(2),
                    'created_at' => $createdAt->addMinutes($linkOrdinal),
                    'updated_at' => $createdAt->addDays(2),
                ];
            }

            $identifierCount = 1 + ($ordinal % 2);

            for ($identifierOrdinal = 0; $identifierOrdinal < $identifierCount; $identifierOrdinal++) {
                $provider = $providers[($ordinal + $identifierOrdinal) % count($providers)];
                $identifier = strtoupper(substr($this->stable->hash("requests:{$ordinal}:identifier:{$identifierOrdinal}"), 0, 12));
                $rows['identifiers'][] = [
                    'content_request_id' => $request->id,
                    'provider' => $provider->value,
                    'identifier' => $identifier,
                    'normalized_identifier' => mb_strtolower($identifier),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            $requesterBody = 'Добавляю уточнение: проверил название, год выпуска и карточку сезона; сведения относятся именно к указанной версии.';
            $moderatorBody = 'Спасибо за уточнение. Источники приняты в работу, связь с карточкой и нумерация эпизодов будут проверены отдельно.';

            foreach ([[$request->requester_id, 'requester', $requesterBody], [$firstParticipant?->id, 'moderator', $moderatorBody]] as $clarificationOrdinal => [$authorId, $role, $body]) {
                $body .= ' '.$this->stable->hash("requests:{$ordinal}:clarification:{$clarificationOrdinal}")[0].'.';
                $rows['clarifications'][] = [
                    'content_request_id' => $request->id,
                    'author_id' => $authorId,
                    'author_role' => $role,
                    'body' => $body,
                    'body_hash' => hash('sha256', mb_strtolower($body)),
                    'submission_key' => $this->stable->hash("requests:{$ordinal}:clarification:{$clarificationOrdinal}:submission"),
                    'created_at' => $createdAt->addHours(4 + $clarificationOrdinal),
                    'updated_at' => $createdAt->addHours(4 + $clarificationOrdinal),
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function historyRow(ContentRequest $request, int $ordinal, ?ContentRequestStatus $from, ContentRequestStatus $to, int $step, CarbonImmutable $createdAt): array
    {
        return [
            'content_request_id' => $request->id,
            'actor_id' => $step === 0 ? $request->requester_id : null,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'public_reason' => $step === 0 ? 'Заявка отправлена на рассмотрение.' : 'Статус изменён после проверки сведений и приложенных источников.',
            'private_note' => $step === 0 ? null : 'Демонстрационная запись истории рабочего процесса.',
            'idempotency_key' => $this->stable->hash("requests:{$ordinal}:history:{$step}"),
            'created_at' => $createdAt,
        ];
    }

    private function correctionField(ContentRequestType $type, int $ordinal): ?string
    {
        if ($type === ContentRequestType::EpisodeListCorrection) {
            return 'episode_list';
        }

        if ($type !== ContentRequestType::MetadataCorrection) {
            return null;
        }

        $fields = (array) config('content-requests.correction_fields', ['description']);

        return (string) $fields[$ordinal % count($fields)];
    }

    private function rejectionReason(int $ordinal): ContentRequestRejectionReason
    {
        return $this->enumAt(ContentRequestRejectionReason::cases(), intdiv($ordinal, count(ContentRequestStatus::cases())));
    }

    private function typeName(ContentRequestType $type): string
    {
        return match ($type) {
            ContentRequestType::Serial => 'новый сериал',
            ContentRequestType::Season => 'недостающий сезон',
            ContentRequestType::Episode => 'недостающий эпизод',
            ContentRequestType::Translation => 'другой перевод',
            ContentRequestType::Subtitles => 'русские субтитры',
            ContentRequestType::QualityUpgrade => 'улучшение качества',
            ContentRequestType::MetadataCorrection => 'исправление карточки',
            ContentRequestType::EpisodeListCorrection => 'исправление списка серий',
            ContentRequestType::BrokenContentRestoration => 'восстановление просмотра',
            ContentRequestType::Other => 'другое уточнение',
        };
    }

    private function createdAt(int $userIndex, int $ordinal): CarbonImmutable
    {
        return CarbonImmutable::parse('2025-02-01 10:00:00')->addDays(($userIndex - 1) * 12)->addHours($ordinal);
    }

    /** @return Collection<int, User> */
    private function users(DemoDataOptions $options): Collection
    {
        $emails = collect(range(1, $options->userCount))->mapWithKeys(fn (int $index): array => ["user{$index}@example.com" => $index]);
        $usersByEmail = User::query()->whereIn('email', $emails->keys())->get()->keyBy('email');

        return $emails->mapWithKeys(function (int $index, string $email) use ($usersByEmail): array {
            $user = $usersByEmail->get($email) ?? throw new LogicException("Demo user {$email} is missing.");

            return [$index => $user];
        });
    }

    /** @template T of \BackedEnum @param non-empty-list<T> $cases @return T */
    private function enumAt(array $cases, int $ordinal): \BackedEnum
    {
        return $cases[$ordinal % count($cases)];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private function updates(array $rows, array $excluded): array
    {
        return $rows === [] ? [] : array_values(array_diff(array_keys($rows[0]), $excluded));
    }
}
