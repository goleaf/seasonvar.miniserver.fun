<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_issues', function (Blueprint $table): void {
            $table->index(
                ['requester_id', 'updated_at', 'id'],
                'technical_issues_requester_updated_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('technical_issues', function (Blueprint $table): void {
            $table->dropIndex('technical_issues_requester_updated_idx');
        });
    }
};
