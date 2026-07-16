<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_profiles')) {
            return;
        }

        DB::table('users')
            ->orderBy('id')
            ->chunkById(250, function ($users): void {
                foreach ($users as $user) {
                    if (DB::table('user_profiles')->where('user_id', $user->id)->exists()) {
                        continue;
                    }

                    $stableIdentity = is_string($user->public_id ?? null)
                        ? $user->public_id
                        : hash('sha256', (string) $user->id);
                    $username = $this->availableUsername((string) $user->name, $stableIdentity);
                    $now = now();

                    DB::table('user_profiles')->insertOrIgnore([
                        'user_id' => $user->id,
                        'username' => $username,
                        'normalized_username' => $username,
                        'profile_visibility' => 'public',
                        'biography_visibility' => 'private',
                        'member_since_visibility' => 'private',
                        'collections_visibility' => 'public',
                        'reviews_visibility' => 'private',
                        'comments_visibility' => 'private',
                        'watching_visibility' => 'private',
                        'completed_visibility' => 'private',
                        'activity_visibility' => 'private',
                        'moderation_status' => 'active',
                        'avatar_version' => 0,
                        'cover_version' => 0,
                        'content_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Backfilled profiles are retained; the schema migration owns table removal.
    }

    private function availableUsername(string $name, string $stableIdentity): string
    {
        $base = Str::of(Str::ascii($name))
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->replaceMatches('/_+/', '_')
            ->limit(24, '')
            ->toString();

        if (strlen($base) < 3 || in_array($base, $this->reservedUsernames(), true)) {
            $base = 'user_'.substr(str_replace('-', '', $stableIdentity), 0, 12);
        }

        if (DB::table('user_profiles')->where('normalized_username', $base)->doesntExist()) {
            return $base;
        }

        return Str::limit($base, 24, '').'_'.substr(hash('sha256', $stableIdentity), 0, 7);
    }

    /** @return list<string> */
    private function reservedUsernames(): array
    {
        return [
            'admin', 'administrator', 'api', 'auth', 'catalog', 'collections', 'login', 'logout',
            'moderator', 'profile', 'profiles', 'register', 'search', 'settings', 'support', 'system',
        ];
    }
};
