<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Comments\CommentDirectLinkResolver;
use App\Services\Comments\CommentSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CommentRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        string $comment,
        CommentSchema $schema,
        CommentDirectLinkResolver $links,
    ): RedirectResponse {
        abort_unless($schema->writable(), 404);
        abort_unless(ctype_digit($comment) && (int) $comment > 0, 404);
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $locale = $request->route('locale');
        $locale = is_string($locale) ? $locale : null;

        return redirect()->to($links->resolve((int) $comment, $viewer, $locale))
            ->withHeaders([
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}
