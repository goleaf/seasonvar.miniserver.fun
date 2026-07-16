<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TechnicalIssueAttachmentController
{
    public function __invoke(
        string $technicalIssue,
        string $attachment,
        TechnicalIssueSchema $schema,
    ): StreamedResponse|Response {
        if (! $schema->ready()) {
            return response(__('issues.errors.action_unavailable'), 503, [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
        }

        $issue = TechnicalIssue::query()->where('public_id', $technicalIssue)->firstOrFail();
        $storedAttachment = TechnicalIssueAttachment::query()
            ->where('public_id', $attachment)
            ->where('technical_issue_id', $issue->id)
            ->firstOrFail();
        Gate::authorize('viewAttachment', $storedAttachment);
        $disk = Storage::disk($storedAttachment->disk);
        abort_unless($disk->exists($storedAttachment->path), 404);
        $disposition = $storedAttachment->mime_type === 'image/png'
            || $storedAttachment->mime_type === 'image/jpeg'
            || $storedAttachment->mime_type === 'image/webp'
                ? 'inline'
                : 'attachment';
        $filename = str_replace(['"', "\r", "\n"], '', $storedAttachment->display_name);

        return $disk->response($storedAttachment->path, $filename, [
            'Content-Type' => $storedAttachment->mime_type,
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
