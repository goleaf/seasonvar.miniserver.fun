<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class CommentDirectLinkResponder
{
    public function __construct(
        private CommentSchema $schema,
        private CommentDirectLinkResolver $links,
    ) {}

    public function response(Request $request, string $comment): RedirectResponse
    {
        abort_unless($this->schema->writable(), 404);
        abort_unless(ctype_digit($comment) && (int) $comment > 0, 404);
        $viewer = $request->user();
        $locale = $request->route('locale');

        return redirect()->to($this->links->resolve(
            (int) $comment,
            $viewer instanceof User ? $viewer : null,
            is_string($locale) ? $locale : null,
        ))->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
