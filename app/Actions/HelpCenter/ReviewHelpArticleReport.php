<?php

declare(strict_types=1);

namespace App\Actions\HelpCenter;

use App\Models\HelpArticleReport;
use App\Models\User;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReviewHelpArticleReport
{
    public function handle(User $editor, HelpArticleReport $report, string $status, ?string $note): HelpArticleReport
    {
        Gate::forUser($editor)->authorize('manage-help-center');

        if (! in_array($status, ['reviewed', 'dismissed'], true)) {
            throw ValidationException::withMessages(['reportStatus' => [__('help.errors.invalid_action')]]);
        }

        $clean = UserPlainText::description($note);
        $report->forceFill([
            'status' => $status,
            'private_note' => is_string($clean) ? Str::limit($clean, 2_000, '') : null,
            'reviewed_by_id' => $editor->id,
            'reviewed_at' => now(),
        ])->save();

        return $report->fresh();
    }
}
