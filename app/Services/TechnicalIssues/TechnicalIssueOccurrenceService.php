<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\TechnicalIssueInput;
use App\DTOs\TechnicalIssues\TechnicalIssueTargetData;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueOccurrence;
use App\Models\User;

final class TechnicalIssueOccurrenceService
{
    public function record(
        TechnicalIssue $issue,
        User $user,
        TechnicalIssueInput $input,
        TechnicalIssueTargetData $target,
    ): void {
        TechnicalIssueOccurrence::query()->updateOrCreate(
            ['technical_issue_id' => $issue->id, 'user_id' => $user->id],
            [
                'browser_family' => $input->diagnosticsConsent ? $input->browserFamily : null,
                'browser_major' => $input->diagnosticsConsent ? $input->browserMajor : null,
                'operating_system' => $input->diagnosticsConsent ? $input->operatingSystem : null,
                'device_category' => $input->diagnosticsConsent ? $input->deviceCategory : null,
                'viewport_width' => $input->diagnosticsConsent ? $input->viewportWidth : null,
                'viewport_height' => $input->diagnosticsConsent ? $input->viewportHeight : null,
                'timezone' => $input->diagnosticsConsent ? $input->timezone : null,
                'network_online' => $input->diagnosticsConsent ? $input->networkOnline : null,
                'playback_position_seconds' => $input->playbackPositionSeconds,
                'public_error_code' => $input->publicErrorCode,
                'source_health_code' => $target->sourceHealthCode,
                'occurred_at' => now(),
                'diagnostics_pruned_at' => null,
            ],
        );
    }

    public function mergeIssues(TechnicalIssue $source, TechnicalIssue $canonical): void
    {
        TechnicalIssueOccurrence::query()
            ->where('technical_issue_id', $source->id)
            ->eachById(function (TechnicalIssueOccurrence $occurrence) use ($canonical): void {
                $existing = TechnicalIssueOccurrence::query()
                    ->where('technical_issue_id', $canonical->id)
                    ->where('user_id', $occurrence->user_id)
                    ->first();

                if ($existing instanceof TechnicalIssueOccurrence) {
                    if ($occurrence->occurred_at->isAfter($existing->occurred_at)) {
                        $existing->fill($occurrence->only([
                            'browser_family', 'browser_major', 'operating_system', 'device_category',
                            'viewport_width', 'viewport_height', 'timezone', 'network_online',
                            'playback_position_seconds', 'public_error_code', 'source_health_code',
                            'occurred_at', 'diagnostics_pruned_at',
                        ]))->save();
                    }

                    $occurrence->delete();

                    return;
                }

                $occurrence->technical_issue_id = $canonical->id;
                $occurrence->save();
            });
    }

    public function mergeUsers(User $source, User $canonical): void
    {
        TechnicalIssueOccurrence::query()
            ->where('user_id', $source->id)
            ->eachById(function (TechnicalIssueOccurrence $occurrence) use ($canonical): void {
                $existing = TechnicalIssueOccurrence::query()
                    ->where('technical_issue_id', $occurrence->technical_issue_id)
                    ->where('user_id', $canonical->id)
                    ->first();

                if ($existing instanceof TechnicalIssueOccurrence) {
                    $occurrence->delete();

                    return;
                }

                $occurrence->user_id = $canonical->id;
                $occurrence->save();
            });
    }
}
