<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\DTOs\DemoData\DemoTitleContext;
use App\Enums\TechnicalIssueMessageVisibility;
use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Models\TechnicalIssueMessage;
use App\Models\Translation;
use App\Models\User;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoPersonaFactory;
use App\Services\DemoData\DemoRasterAsset;
use App\Services\DemoData\DemoRussianText;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use App\Services\TechnicalIssues\TechnicalIssueTypeRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class DemoTechnicalIssueStage implements DemoDataStage
{
    public function __construct(
        private DemoStableValue $stable,
        private DemoPersonaFactory $personas,
        private DemoRussianText $text,
        private TechnicalIssueTypeRegistry $registry,
    ) {}

    public function key(): string
    {
        return 'technical_issues';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $options->assertEnvironment(app()->environment());
        $writer = new DemoBulkWriter($options);
        $selector = new DemoTitleSelector($options);
        $users = $this->users($options);
        $support = $users->last() ?? throw new LogicException('Demo support user is missing.');
        $translationId = Translation::query()->orderBy('id')->value('id');
        $issueRows = [];
        $specs = [];
        $globalOrdinal = 0;

        foreach ($users as $userIndex => $requester) {
            $count = $this->stable->integer(
                "issues:user:{$userIndex}:count",
                $options->issueMinimum,
                $options->issueMaximum,
            );
            $titleIds = $selector->selectedIds($userIndex)->take($count)->values()->all();
            $contexts = $selector->contexts($titleIds)->values();

            if ($contexts->isEmpty()) {
                throw new LogicException('Для технических обращений нужен хотя бы один опубликованный сериал.');
            }

            for ($ordinal = 0; $ordinal < $count; $ordinal++, $globalOrdinal++) {
                /** @var DemoTitleContext $context */
                $context = $contexts->get($ordinal % $contexts->count());
                $type = $this->issueType($globalOrdinal);
                $targetType = $this->targetType($type, $globalOrdinal);
                $status = $this->enumAt(TechnicalIssueStatus::cases(), $globalOrdinal);
                $severity = $this->enumAt(TechnicalIssueSeverity::cases(), $globalOrdinal);
                $priority = $this->enumAt(TechnicalIssuePriority::cases(), $globalOrdinal);
                $persona = $this->personas->make($userIndex);
                $copy = $this->text->technicalIssue($persona, $type->label(), $ordinal);
                $summary = sprintf('%s: %s — %s', $persona->givenName, $copy['subject'], $context->displayTitle);
                $submissionKey = $this->stable->hash("issues:{$globalOrdinal}:submission");
                $identityHash = $this->stable->hash("issues:{$globalOrdinal}:identity");
                $createdAt = $this->createdAt($userIndex, $ordinal);
                $resolution = $status->isTerminal() ? $this->resolution($globalOrdinal, $status) : null;
                $catalogTarget = in_array($targetType, [
                    TechnicalIssueTargetType::Title,
                    TechnicalIssueTargetType::Season,
                    TechnicalIssueTargetType::Episode,
                    TechnicalIssueTargetType::Media,
                    TechnicalIssueTargetType::Translation,
                ], true);
                $issueRows[] = [
                    'public_id' => $this->stable->uuid("issues:{$globalOrdinal}:public"),
                    'public_number' => sprintf('SV-DEMO-%06d', $globalOrdinal + 1),
                    'requester_id' => $requester->id,
                    'assigned_to_id' => $status === TechnicalIssueStatus::Submitted ? null : $support->id,
                    'support_team' => $this->registry->supportTeam($type),
                    'type' => $type->value,
                    'status' => $status->value,
                    'severity' => $severity->value,
                    'priority' => $priority->value,
                    'target_type' => $targetType->value,
                    'target_label_snapshot' => $this->targetLabel($targetType, $context),
                    'catalog_title_id' => $catalogTarget ? $context->titleId : null,
                    'season_id' => in_array($targetType, [TechnicalIssueTargetType::Season, TechnicalIssueTargetType::Episode, TechnicalIssueTargetType::Media], true)
                        ? $context->firstSeasonId : null,
                    'episode_id' => in_array($targetType, [TechnicalIssueTargetType::Episode, TechnicalIssueTargetType::Media], true)
                        ? $context->firstEpisodeId : null,
                    'licensed_media_id' => $targetType === TechnicalIssueTargetType::Media ? $context->licensedMediaId : null,
                    'translation_id' => $targetType === TechnicalIssueTargetType::Translation ? $translationId : null,
                    'feature_code' => $this->featureCode($targetType),
                    'route_name' => $this->routeName($targetType),
                    'route_path' => '/serial/'.$context->titleId.'/demo-issue',
                    'locale' => 'ru',
                    'summary' => $summary,
                    'expected_behavior' => $copy['expected'],
                    'actual_behavior' => $copy['actual'].' '.$copy['description'],
                    'reproduction_steps' => $copy['steps'],
                    'playback_position_seconds' => $this->registry->supportsTimestamp($type)
                        ? $this->stable->integer("issues:{$globalOrdinal}:position", 15, 2_000) : null,
                    'audio_language' => $this->requiresAudio($type) ? 'ru' : null,
                    'subtitle_language' => $this->requiresSubtitles($type) ? 'ru' : null,
                    'quality_code' => in_array($type, [TechnicalIssueType::QualityUnavailable, TechnicalIssueType::QualityLabelMismatch], true) ? '1080p' : null,
                    'public_error_code' => $this->stable->pick("issues:{$globalOrdinal}:error", ['PLAYER_TIMEOUT', 'PAGE_RENDER', 'STATE_SYNC', 'SOURCE_UNAVAILABLE']),
                    'diagnostics_consent' => true,
                    'exact_identity_hash' => $identityHash,
                    'active_identity_key' => $status->isOpen() ? $identityHash : null,
                    'submission_key' => $submissionKey,
                    'merged_into_id' => null,
                    'resolution_type' => $resolution?->value,
                    'resolution_summary' => $resolution === null ? null : 'Проверка завершена; причина установлена, а результат зафиксирован в истории обращения.',
                    'rejection_reason' => $status === TechnicalIssueStatus::Rejected ? 'Сценарий не удалось подтвердить на поддерживаемой конфигурации.' : null,
                    'rerouted_to' => $status === TechnicalIssueStatus::Rejected ? 'content_requests' : null,
                    'version' => $status === TechnicalIssueStatus::Submitted ? 1 : 2,
                    'reopen_count' => $status === TechnicalIssueStatus::Reopened ? 1 : 0,
                    'last_public_message_at' => $createdAt->addHours(4),
                    'resolved_at' => in_array($status, [TechnicalIssueStatus::Resolved, TechnicalIssueStatus::ResolutionVerified, TechnicalIssueStatus::Closed], true) ? $createdAt->addDays(2) : null,
                    'verified_at' => in_array($status, [TechnicalIssueStatus::ResolutionVerified, TechnicalIssueStatus::Closed], true) ? $createdAt->addDays(3) : null,
                    'closed_at' => $status === TechnicalIssueStatus::Closed ? $createdAt->addDays(4) : null,
                    'withdrawn_at' => $status === TechnicalIssueStatus::Withdrawn ? $createdAt->addDay() : null,
                    'created_at' => $createdAt,
                    'updated_at' => $status === TechnicalIssueStatus::Submitted ? $createdAt : $createdAt->addDay(),
                ];
                $specs[$submissionKey] = [
                    'ordinal' => $globalOrdinal,
                    'requester_id' => (int) $requester->id,
                    'requester_index' => $userIndex,
                    'status' => $status,
                    'summary' => $summary,
                    'description' => $copy['description'],
                    'catalog_title_id' => $context->titleId,
                    'licensed_media_id' => $context->licensedMediaId,
                    'created_at' => $createdAt,
                ];
            }

            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        $writer->upsert((new TechnicalIssue)->getTable(), $issueRows, ['submission_key'], $this->updates($issueRows, ['submission_key', 'created_at']));
        $issues = TechnicalIssue::query()
            ->whereIn('submission_key', array_keys($specs))
            ->get(['id', 'submission_key', 'requester_id', 'status', 'licensed_media_id'])
            ->keyBy('submission_key');
        $children = $this->childRows($issues, $specs, $users, $support, $options);

        $writer->upsert('technical_issue_diagnostics', $children['diagnostics'], ['technical_issue_id'], $this->updates($children['diagnostics'], ['technical_issue_id', 'created_at']));
        $writer->upsert((new TechnicalIssueMessage)->getTable(), $children['messages'], ['submission_key'], $this->updates($children['messages'], ['submission_key', 'created_at']));
        $messages = TechnicalIssueMessage::query()->whereIn('submission_key', $children['requester_message_keys'])->get(['id', 'submission_key'])->keyBy('submission_key');
        $attachmentRows = $this->attachmentRows($issues, $specs, $messages, $options);
        $writer->upsert((new TechnicalIssueAttachment)->getTable(), $attachmentRows, ['path'], $this->updates($attachmentRows, ['path', 'created_at']));
        $writer->upsert('technical_issue_status_histories', $children['histories'], ['idempotency_key'], $this->updates($children['histories'], ['idempotency_key', 'created_at']));
        $writer->upsert('technical_issue_confirmations', $children['confirmations'], ['technical_issue_id', 'user_id'], ['verification_state', 'updated_at']);
        $writer->upsert('technical_issue_followers', $children['followers'], ['technical_issue_id', 'user_id'], ['updated_at']);
        $writer->upsert('technical_issue_occurrences', $children['occurrences'], ['technical_issue_id', 'user_id'], $this->updates($children['occurrences'], ['technical_issue_id', 'user_id', 'created_at']));
        $writer->upsert('technical_issue_merges', $children['merges'], ['duplicate_issue_id'], ['canonical_issue_id', 'merged_by_id']);

        foreach ($children['assignments'] as $row) {
            DB::table('technical_issue_assignments')->updateOrInsert(
                ['technical_issue_id' => $row['technical_issue_id'], 'created_at' => $row['created_at']],
                array_diff_key($row, ['technical_issue_id' => true, 'created_at' => true]),
            );
        }

        foreach ($children['redactions'] as $row) {
            DB::table('technical_issue_redactions')->updateOrInsert(
                ['technical_issue_id' => $row['technical_issue_id'], 'field' => $row['field'], 'created_at' => $row['created_at']],
                array_diff_key($row, ['technical_issue_id' => true, 'field' => true, 'created_at' => true]),
            );
        }

        foreach ($children['source_actions'] as $row) {
            DB::table('technical_issue_source_actions')->updateOrInsert(
                ['technical_issue_id' => $row['technical_issue_id'], 'action' => $row['action'], 'created_at' => $row['created_at']],
                array_diff_key($row, ['technical_issue_id' => true, 'action' => true, 'created_at' => true]),
            );
        }

        return new DemoStageReport($this->key(), [
            'issues' => count($issueRows),
            'diagnostics' => count($children['diagnostics']),
            'messages' => count($children['messages']),
            'attachments' => count($attachmentRows),
            'histories' => count($children['histories']),
            'assignments' => count($children['assignments']),
            'confirmations' => count($children['confirmations']),
            'followers' => count($children['followers']),
            'occurrences' => count($children['occurrences']),
            'merges' => count($children['merges']),
            'redactions' => count($children['redactions']),
            'source_actions' => count($children['source_actions']),
        ], microtime(true) - $startedAt);
    }

    /**
     * @param  Collection<string, TechnicalIssue>  $issues
     * @param  array<string, array{ordinal: int, requester_id: int, requester_index: int, status: TechnicalIssueStatus, summary: string, description: string, catalog_title_id: int, licensed_media_id: ?int, created_at: CarbonImmutable}>  $specs
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function childRows(Collection $issues, array $specs, Collection $users, User $support, DemoDataOptions $options): array
    {
        $rows = [
            'diagnostics' => [], 'messages' => [], 'requester_message_keys' => [], 'histories' => [],
            'assignments' => [], 'confirmations' => [], 'followers' => [], 'occurrences' => [],
            'merges' => [], 'redactions' => [], 'source_actions' => [],
        ];
        /** @var TechnicalIssue|null $canonical */
        $canonical = $issues->first(fn (TechnicalIssue $issue): bool => $issue->status !== TechnicalIssueStatus::Merged);

        foreach ($specs as $submissionKey => $spec) {
            $issue = $issues->get($submissionKey);

            if (! $issue instanceof TechnicalIssue) {
                throw new LogicException('Не удалось найти созданное техническое обращение.');
            }

            $ordinal = $spec['ordinal'];
            $createdAt = $spec['created_at'];
            $participant = $users->get(($spec['requester_index'] % $options->userCount) + 1) ?? $users->first();
            $rows['diagnostics'][] = [
                'technical_issue_id' => $issue->id,
                'authenticated_category' => 'authenticated',
                'browser_family' => $this->stable->pick("issues:{$ordinal}:browser", ['chromium', 'firefox', 'safari', 'edge']),
                'browser_major' => $this->stable->integer("issues:{$ordinal}:browser-major", 110, 140),
                'operating_system' => $this->stable->pick("issues:{$ordinal}:os", ['windows', 'macos', 'android', 'linux']),
                'device_category' => $this->stable->pick("issues:{$ordinal}:device", ['desktop', 'tablet', 'mobile']),
                'viewport_width' => $this->stable->pick("issues:{$ordinal}:width", [360, 768, 1280, 1440]),
                'viewport_height' => $this->stable->pick("issues:{$ordinal}:height", [720, 800, 900, 1080]),
                'timezone' => 'Europe/Vilnius',
                'network_online' => true,
                'player_component' => 'plyr-hls',
                'source_health_code' => 'reachable',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $messageSpecs = [
                [$spec['requester_id'], TechnicalIssueMessageVisibility::RequesterVisible, 'message', $spec['description']],
                [$support->id, TechnicalIssueMessageVisibility::RequesterVisible, 'support_reply', $this->text->supportReply($this->personas->make($spec['requester_index']), $spec['summary'], $ordinal)],
                [$support->id, TechnicalIssueMessageVisibility::Internal, 'internal_note', 'Внутренняя заметка: диагностические данные проверены, обращение направлено профильной команде. '.$spec['summary']],
            ];

            foreach ($messageSpecs as $messageOrdinal => [$authorId, $visibility, $kind, $body]) {
                $messageKey = $this->stable->hash("issues:{$ordinal}:message:{$messageOrdinal}");
                $rows['messages'][] = [
                    'public_id' => $this->stable->uuid("issues:{$ordinal}:message:{$messageOrdinal}:public"),
                    'technical_issue_id' => $issue->id,
                    'author_id' => $authorId,
                    'visibility' => $visibility->value,
                    'kind' => $kind,
                    'body' => $body,
                    'body_hash' => hash('sha256', mb_strtolower($body)),
                    'submission_key' => $messageKey,
                    'redacted_at' => null,
                    'created_at' => $createdAt->addHours($messageOrdinal + 1),
                    'updated_at' => $createdAt->addHours($messageOrdinal + 1),
                ];

                if ($messageOrdinal === 0) {
                    $rows['requester_message_keys'][] = $messageKey;
                }
            }

            $rows['histories'][] = $this->historyRow($issue, $ordinal, null, TechnicalIssueStatus::Submitted, 0, $createdAt);

            if ($spec['status'] !== TechnicalIssueStatus::Submitted) {
                $rows['histories'][] = $this->historyRow($issue, $ordinal, TechnicalIssueStatus::Submitted, $spec['status'], 1, $createdAt->addDay());
            }

            $rows['assignments'][] = [
                'technical_issue_id' => $issue->id,
                'assigned_by_id' => $support->id,
                'assignee_id' => $support->id,
                'support_team' => 'support',
                'ended_at' => $spec['status']->isTerminal() ? $createdAt->addDays(3) : null,
                'created_at' => $createdAt->addHours(2),
                'updated_at' => $createdAt->addHours(2),
            ];
            $engagement = [
                'technical_issue_id' => $issue->id,
                'user_id' => $participant?->id,
                'created_at' => $createdAt->addHours(3),
                'updated_at' => $createdAt->addHours(3),
            ];
            $rows['confirmations'][] = [...$engagement, 'verification_state' => $ordinal % 3 === 0 ? 'fixed' : 'still_broken'];
            $rows['followers'][] = $engagement;
            $rows['occurrences'][] = [
                ...$engagement,
                'browser_family' => 'chromium',
                'browser_major' => 132,
                'operating_system' => 'windows',
                'device_category' => 'desktop',
                'viewport_width' => 1440,
                'viewport_height' => 900,
                'timezone' => 'Europe/Vilnius',
                'network_online' => true,
                'playback_position_seconds' => $this->stable->integer("issues:{$ordinal}:occurrence-position", 0, 2_000),
                'public_error_code' => 'REPRODUCED',
                'source_health_code' => 'reachable',
                'occurred_at' => $createdAt->addHours(3),
                'diagnostics_pruned_at' => null,
            ];

            if ($spec['status'] === TechnicalIssueStatus::Merged && $canonical instanceof TechnicalIssue && $canonical->id !== $issue->id) {
                DB::table('technical_issues')->where('id', $issue->id)->update(['merged_into_id' => $canonical->id]);
                $rows['merges'][] = [
                    'duplicate_issue_id' => $issue->id,
                    'canonical_issue_id' => $canonical->id,
                    'merged_by_id' => $support->id,
                    'created_at' => $createdAt->addDay(),
                ];
            }

            if ($ordinal % 10 === 0) {
                $rows['redactions'][] = [
                    'technical_issue_id' => $issue->id,
                    'technical_issue_message_id' => null,
                    'actor_id' => $support->id,
                    'field' => 'diagnostic_note',
                    'reason_code' => 'personal_information',
                    'before_hash' => $this->stable->hash("issues:{$ordinal}:redaction:before"),
                    'after_hash' => $this->stable->hash("issues:{$ordinal}:redaction:after"),
                    'created_at' => $createdAt->addHours(5),
                ];
            }

            if ($spec['licensed_media_id'] !== null) {
                $action = $this->stable->pick("issues:{$ordinal}:source-action", ['under_review', 'disabled', 'restored']);
                $rows['source_actions'][] = [
                    'technical_issue_id' => $issue->id,
                    'licensed_media_id' => $spec['licensed_media_id'],
                    'actor_id' => $support->id,
                    'action' => $action,
                    'from_health_status' => 'healthy',
                    'to_health_status' => $action === 'disabled' ? 'disabled' : 'checking',
                    'private_note' => 'Источник проверен в рамках демонстрационного сценария обращения.',
                    'created_at' => $createdAt->addHours(6),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<string, TechnicalIssue>  $issues
     * @param  array<string, array{ordinal: int, requester_id: int, requester_index: int, status: TechnicalIssueStatus, summary: string, description: string, catalog_title_id: int, licensed_media_id: ?int, created_at: CarbonImmutable}>  $specs
     * @param  Collection<string, TechnicalIssueMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function attachmentRows(Collection $issues, array $specs, Collection $messages, DemoDataOptions $options): array
    {
        $asset = new DemoRasterAsset($options, $this->stable);
        $rows = [];

        foreach ($specs as $submissionKey => $spec) {
            $issue = $issues->get($submissionKey);
            $messageKey = $this->stable->hash("issues:{$spec['ordinal']}:message:0");
            $message = $messages->get($messageKey);

            if (! $issue instanceof TechnicalIssue || ! $message instanceof TechnicalIssueMessage) {
                throw new LogicException('Не удалось связать снимок экрана с техническим обращением.');
            }

            $stored = $asset->store('issue-screenshot', (string) $spec['ordinal'], 480, 270);
            $rows[] = [
                'public_id' => $this->stable->uuid("issues:{$spec['ordinal']}:attachment:public"),
                'technical_issue_id' => $issue->id,
                'technical_issue_message_id' => $message->id,
                'uploader_id' => $spec['requester_id'],
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'display_name' => 'Снимок экрана с воспроизведённой проблемой.png',
                'mime_type' => $stored['mime_type'],
                'extension' => 'png',
                'size_bytes' => $stored['size'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'content_hash' => $stored['hash'],
                'created_at' => $spec['created_at']->addHour(),
                'updated_at' => $spec['created_at']->addHour(),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function historyRow(TechnicalIssue $issue, int $ordinal, ?TechnicalIssueStatus $from, TechnicalIssueStatus $to, int $step, CarbonImmutable $createdAt): array
    {
        return [
            'technical_issue_id' => $issue->id,
            'actor_id' => $step === 0 ? $issue->requester_id : null,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'public_reason_code' => $step === 0 ? 'submitted' : 'support_reviewed',
            'public_message' => $step === 0 ? 'Обращение зарегистрировано и передано в поддержку.' : 'Поддержка проверила сведения и обновила состояние обращения.',
            'private_note' => $step === 0 ? null : 'Демонстрационная запись внутреннего рабочего процесса.',
            'idempotency_key' => $this->stable->hash("issues:{$ordinal}:history:{$step}"),
            'created_at' => $createdAt,
        ];
    }

    private function issueType(int $ordinal): TechnicalIssueType
    {
        $types = TechnicalIssueType::cases();

        if ($ordinal >= count($types) && $ordinal < count($types) + count(TechnicalIssueTargetType::cases())) {
            return TechnicalIssueType::OtherTechnicalIssue;
        }

        return $types[$ordinal % count($types)];
    }

    private function targetType(TechnicalIssueType $type, int $ordinal): TechnicalIssueTargetType
    {
        $typeCount = count(TechnicalIssueType::cases());
        $targets = TechnicalIssueTargetType::cases();

        if ($ordinal >= $typeCount && $ordinal < $typeCount + count($targets)) {
            return $targets[$ordinal - $typeCount];
        }

        $allowed = $this->registry->rule($type)['targets'];

        return $allowed[$ordinal % count($allowed)];
    }

    private function resolution(int $ordinal, TechnicalIssueStatus $status): TechnicalIssueResolutionType
    {
        $terminalStatuses = array_values(array_filter(TechnicalIssueStatus::cases(), fn (TechnicalIssueStatus $candidate): bool => $candidate->isTerminal()));
        $terminalIndex = array_search($status, $terminalStatuses, true);
        $sequence = intdiv($ordinal, count(TechnicalIssueStatus::cases())) * count($terminalStatuses) + (int) $terminalIndex;

        return $this->enumAt(TechnicalIssueResolutionType::cases(), $sequence);
    }

    private function targetLabel(TechnicalIssueTargetType $target, DemoTitleContext $context): string
    {
        return match ($target) {
            TechnicalIssueTargetType::Title => 'Карточка сериала «'.$context->displayTitle.'»',
            TechnicalIssueTargetType::Season => 'Первый сезон сериала «'.$context->displayTitle.'»',
            TechnicalIssueTargetType::Episode => 'Первая серия «'.$context->displayTitle.'»',
            TechnicalIssueTargetType::Media => 'Плеер серии «'.$context->displayTitle.'»',
            TechnicalIssueTargetType::Translation => 'Перевод сериала «'.$context->displayTitle.'»',
            TechnicalIssueTargetType::Page => 'Страница сериала «'.$context->displayTitle.'»',
            TechnicalIssueTargetType::Account => 'Настройки учётной записи',
            TechnicalIssueTargetType::Notification => 'Центр уведомлений',
            TechnicalIssueTargetType::Calendar => 'Календарь выхода серий',
            TechnicalIssueTargetType::Search => 'Поиск по каталогу',
            TechnicalIssueTargetType::General => 'Общие функции портала',
        };
    }

    private function featureCode(TechnicalIssueTargetType $target): string
    {
        return match ($target) {
            TechnicalIssueTargetType::Media => 'player',
            TechnicalIssueTargetType::Search => 'search',
            TechnicalIssueTargetType::Notification => 'notifications',
            TechnicalIssueTargetType::Calendar => 'calendar',
            TechnicalIssueTargetType::Account => 'account',
            default => 'catalog',
        };
    }

    private function routeName(TechnicalIssueTargetType $target): string
    {
        return match ($target) {
            TechnicalIssueTargetType::Search => 'search.index',
            TechnicalIssueTargetType::Notification => 'notifications.index',
            TechnicalIssueTargetType::Calendar => 'calendar.index',
            TechnicalIssueTargetType::Account => 'account.settings',
            default => 'titles.show',
        };
    }

    private function requiresAudio(TechnicalIssueType $type): bool
    {
        return in_array($type, [TechnicalIssueType::AudioMissing, TechnicalIssueType::AudioLanguageMismatch, TechnicalIssueType::AudioSync, TechnicalIssueType::TranslationStudioMismatch], true);
    }

    private function requiresSubtitles(TechnicalIssueType $type): bool
    {
        return in_array($type, [TechnicalIssueType::SubtitlesMissing, TechnicalIssueType::SubtitleLanguageMismatch, TechnicalIssueType::SubtitleSync, TechnicalIssueType::SubtitleTextError], true);
    }

    private function createdAt(int $userIndex, int $ordinal): CarbonImmutable
    {
        return CarbonImmutable::parse('2025-05-01 09:00:00')->addDays(($userIndex - 1) * 7)->addHours($ordinal);
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
