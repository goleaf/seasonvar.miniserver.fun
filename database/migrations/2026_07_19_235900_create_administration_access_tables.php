<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'code'], 'admin_roles_active_code_idx');
        });

        Schema::create('admin_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('sensitivity', 32);
            $table->timestamps();

            $table->index(['sensitivity', 'code'], 'admin_permissions_sensitivity_code_idx');
        });

        Schema::create('admin_role_permissions', function (Blueprint $table): void {
            $table->foreignId('admin_role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->foreignId('admin_permission_id')->constrained('admin_permissions')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->primary(['admin_role_id', 'admin_permission_id'], 'admin_role_permissions_primary');
            $table->index(['admin_permission_id', 'admin_role_id'], 'admin_role_permissions_permission_idx');
        });

        Schema::create('admin_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_role_id')->constrained('admin_roles')->restrictOnDelete();
            $table->string('status', 24);
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 64);
            $table->timestamp('assigned_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'admin_role_id'], 'admin_user_roles_user_role_unique');
            $table->index(['user_id', 'status', 'expires_at'], 'admin_user_roles_user_effective_idx');
            $table->index(['admin_role_id', 'status', 'expires_at'], 'admin_user_roles_role_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_roles');
        Schema::dropIfExists('admin_role_permissions');
        Schema::dropIfExists('admin_permissions');
        Schema::dropIfExists('admin_roles');
    }
};
