<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ready-goods store, checkout, basket links, coupons and the Moyasar gateway sessions (P8c, domain 07, ADR-0023).
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row: the facility's store setup. Closed until a branch and a handler are chosen.
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // Branch that receives online orders (its locations and the facility-wide ones serve the stock).
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            // Member following online orders without a basket link (assignee, receives the store notices).
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('reservation_minutes')->default(30);
            $table->unsignedSmallInteger('link_hours_default')->default(24);
            $table->unsignedSmallInteger('link_days_max')->default(7);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // M-31: a sales basket and its purchase link (SAL-01..05). The token is shown to its owner only; lookups use
        // the hash. `lines` are variant ids and whole quantities, priced again at checkout.
        Schema::create('store_links', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            // draft | active | checking_out | used | expired | cancelled
            $table->string('status', 16)->index();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->json('lines');
            $table->string('note', 500)->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('used_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('replaces_id')->nullable()->constrained('store_links')->restrictOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['owner_id', 'id']);
        });

        // SAL-06: ready-goods coupon. `uses_count` counts reserved + consumed usages (the cap is checked under the
        // coupon's row lock).
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->json('title');
            // percent (value in basis points) | amount (value in halalas)
            $table->string('kind', 8);
            $table->unsignedBigInteger('value');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // null = every ready variant; otherwise the variant ids it applies to.
            $table->json('variant_ids')->nullable();
            $table->unsignedBigInteger('min_subtotal_minor')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        // A checkout attempt: one ready order awaiting an online payment (M-05-T02) with its reservation window.
        Schema::create('store_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->uuid('reference')->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('link_id')->nullable()->constrained('store_links')->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            // pending_payment | paid | expired | abandoned | needs_attention
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('vat_minor');
            $table->unsignedBigInteger('total_minor');
            // Per-line price breakdown with each line's discount contribution, fixed at the start (SAL-07).
            $table->json('pricing');
            $table->timestamp('expires_at')->index();
            // Provider time of the successful payment (SAL-03, SAL-18).
            $table->timestamp('paid_at')->nullable();
            $table->string('idempotency_key', 64);
            $table->unsignedTinyInteger('provider_failures')->default(0);
            $table->string('attention_reason', 40)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['customer_id', 'idempotency_key']);
        });

        // M-32: one coupon use per checkout.
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignId('checkout_id')->unique()->constrained('store_checkouts')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // reserved | consumed | released
            $table->string('status', 10);
            $table->unsignedBigInteger('discount_minor');
            $table->timestamps();

            $table->index(['coupon_id', 'customer_id', 'status']);
        });

        // The provider's object behind a pending online payment (a Moyasar invoice).
        Schema::create('payment_gateway_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->string('gateway', 30);
            $table->string('provider_ref', 80);
            $table->text('checkout_url');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'provider_ref']);
        });

        // Q-48 (a): a checkout code may go to a mobile with no account yet (created on a correct code).
        Schema::table('customer_otp_challenges', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('payment_gateway_sessions');
        Schema::dropIfExists('store_checkouts');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('store_links');
        Schema::dropIfExists('store_settings');
    }
};
