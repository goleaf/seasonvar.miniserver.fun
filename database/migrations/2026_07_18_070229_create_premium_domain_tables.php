<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type', 32);
            $table->unsignedInteger('duration_days')->nullable();
            $table->string('billing_interval', 16)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->json('entitlement_codes');
            $table->string('provider_code', 32)->nullable();
            $table->string('provider_product_id', 191)->nullable();
            $table->string('provider_price_id', 191)->nullable();
            $table->json('region_codes')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_public')->default(false);
            $table->boolean('is_legacy')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'is_public', 'display_order'], 'premium_plans_public_order_idx');
            $table->unique(['provider_code', 'provider_product_id'], 'premium_plans_provider_product_uq');
            $table->unique(['provider_code', 'provider_price_id'], 'premium_plans_provider_price_uq');
        });

        Schema::create('premium_promotions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->unsignedInteger('duration_days');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('total_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at'], 'premium_promotions_active_window_idx');
        });

        Schema::create('premium_coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('premium_promotion_id')->constrained('premium_promotions')->restrictOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('code_hint', 24);
            $table->unsignedInteger('redemption_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['premium_promotion_id', 'is_active'], 'premium_coupons_promotion_active_idx');
        });

        Schema::create('premium_checkout_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('premium_plan_id')->constrained('premium_plans')->restrictOnDelete();
            $table->string('provider_code', 32);
            $table->string('provider_session_id', 191)->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 32)->default('created');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('locale', 8);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'provider_session_id'], 'premium_checkouts_provider_session_uq');
            $table->index(['user_id', 'status', 'created_at'], 'premium_checkouts_user_status_time_idx');
        });

        Schema::create('premium_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('premium_plan_id')->constrained('premium_plans')->restrictOnDelete();
            $table->string('provider_code', 32);
            $table->string('provider_customer_id', 191)->nullable();
            $table->string('provider_subscription_id', 191);
            $table->string('status', 32)->default('pending');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'provider_subscription_id'], 'premium_subscriptions_provider_id_uq');
            $table->index(['user_id', 'status', 'current_period_end'], 'premium_subscriptions_user_status_end_idx');
            $table->index(['provider_code', 'provider_customer_id'], 'premium_subscriptions_provider_customer_idx');
        });

        Schema::create('premium_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('premium_plan_id')->nullable()->constrained('premium_plans')->nullOnDelete();
            $table->foreignId('premium_checkout_session_id')->nullable()->constrained('premium_checkout_sessions')->nullOnDelete();
            $table->foreignId('premium_subscription_id')->nullable()->constrained('premium_subscriptions')->nullOnDelete();
            $table->string('provider_code', 32);
            $table->string('provider_payment_id', 191);
            $table->string('status', 32)->default('created');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'provider_payment_id'], 'premium_payments_provider_id_uq');
            $table->index(['user_id', 'status', 'created_at'], 'premium_payments_user_status_time_idx');
            $table->index(['user_id', 'created_at', 'id'], 'premium_payments_user_time_idx');
            $table->index(['premium_subscription_id', 'confirmed_at'], 'premium_payments_subscription_confirmed_idx');
        });

        Schema::create('premium_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('premium_payment_id')->constrained('premium_payments')->restrictOnDelete();
            $table->string('provider_refund_id', 191);
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason_code', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['premium_payment_id', 'provider_refund_id'], 'premium_refunds_payment_provider_uq');
            $table->index(['premium_payment_id', 'status'], 'premium_refunds_payment_status_idx');
        });

        Schema::create('premium_disputes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('premium_payment_id')->constrained('premium_payments')->restrictOnDelete();
            $table->string('provider_dispute_id', 191);
            $table->string('status', 32)->default('open');
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['premium_payment_id', 'provider_dispute_id'], 'premium_disputes_payment_provider_uq');
            $table->index(['status', 'opened_at'], 'premium_disputes_status_opened_idx');
        });

        Schema::create('premium_coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('premium_promotion_id')->constrained('premium_promotions')->restrictOnDelete();
            $table->foreignId('premium_coupon_id')->constrained('premium_coupons')->restrictOnDelete();
            $table->char('idempotency_key', 64)->unique();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->unique(['user_id', 'premium_coupon_id'], 'premium_redemptions_user_coupon_uq');
            $table->index(['premium_promotion_id', 'user_id', 'redeemed_at'], 'premium_redemptions_promotion_user_time_idx');
        });

        Schema::create('premium_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('premium_plan_id')->nullable()->constrained('premium_plans')->nullOnDelete();
            $table->foreignId('premium_subscription_id')->nullable()->constrained('premium_subscriptions')->nullOnDelete();
            $table->foreignId('premium_payment_id')->nullable()->constrained('premium_payments')->nullOnDelete();
            $table->foreignId('premium_coupon_redemption_id')->nullable()->constrained('premium_coupon_redemptions')->nullOnDelete();
            $table->string('feature_code', 64);
            $table->string('source', 32);
            $table->string('source_reference', 191)->nullable();
            $table->char('application_key', 64)->unique();
            $table->string('reason_code', 64)->nullable();
            $table->text('private_note')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_lifetime')->default(false);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason_code', 64)->nullable();
            $table->text('revocation_private_note')->nullable();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'feature_code', 'revoked_at', 'ends_at'], 'premium_entitlements_user_feature_active_idx');
            $table->index(['premium_payment_id', 'feature_code'], 'premium_entitlements_payment_feature_idx');
            $table->index(['premium_subscription_id', 'feature_code'], 'premium_entitlements_subscription_feature_idx');
            $table->index(['source', 'source_reference'], 'premium_entitlements_source_reference_idx');
        });

        Schema::create('premium_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_code', 32);
            $table->string('provider_event_id', 191);
            $table->string('event_type', 96);
            $table->string('environment', 24);
            $table->string('status', 24)->default('received');
            $table->string('object_type', 32)->nullable();
            $table->string('object_id', 191)->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_category', 64)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'provider_event_id'], 'premium_provider_events_identity_uq');
            $table->index(['status', 'created_at'], 'premium_provider_events_status_time_idx');
            $table->index(['provider_code', 'object_type', 'object_id'], 'premium_provider_events_object_idx');
        });

        Schema::create('premium_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('resource_type', 32);
            $table->string('resource_public_id', 191)->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->json('context');
            $table->timestamp('occurred_at');

            $table->index(['user_id', 'occurred_at'], 'premium_audit_user_time_idx');
            $table->index(['resource_type', 'resource_public_id', 'occurred_at'], 'premium_audit_resource_time_idx');
            $table->index(['action', 'occurred_at'], 'premium_audit_action_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_audit_events');
        Schema::dropIfExists('premium_provider_events');
        Schema::dropIfExists('premium_entitlements');
        Schema::dropIfExists('premium_coupon_redemptions');
        Schema::dropIfExists('premium_disputes');
        Schema::dropIfExists('premium_refunds');
        Schema::dropIfExists('premium_payments');
        Schema::dropIfExists('premium_subscriptions');
        Schema::dropIfExists('premium_checkout_sessions');
        Schema::dropIfExists('premium_coupons');
        Schema::dropIfExists('premium_promotions');
        Schema::dropIfExists('premium_plans');
    }
};
