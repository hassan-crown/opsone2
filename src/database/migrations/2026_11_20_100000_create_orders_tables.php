<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gapless numbering (orders now, receipts later): one row per sequence and period, locked (upsert) inside the
        // transaction that uses the number, so a rollback gives the number back (ADR-0011).
        Schema::create('sequence_counters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->unsignedSmallInteger('period');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['name', 'period']);
        });

        // Replay protection for create endpoints: the first request with a key stores its result; a retry with the
        // same key and payload gets the same result, a different payload is refused.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 60);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->json('result')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['scope', 'user_id', 'key']);
            $table->index('created_at');
        });

        // A shared purchase reference groups a ready order and a custom order sold together (COM-10). It is not an
        // order and carries no amount of its own.
        Schema::create('purchase_references', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            // custom (M-04) | ready (M-05); fixed once work starts.
            $table->string('kind', 16);
            $table->string('status', 32);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('purchase_reference_id')->nullable()->constrained('purchase_references')->restrictOnDelete();
            $table->text('need_summary')->nullable();
            $table->boolean('needs_measurement')->default(false);
            $table->boolean('needs_design')->default(false);
            // Preferred invoice document for an organization customer (tax | simplified); never rewrites a document.
            $table->string('invoice_type', 16)->nullable();
            // Ready orders only: sum of active lines in minor units. Null for custom orders (no price is not zero).
            $table->unsignedBigInteger('total_minor')->nullable();
            // Optimistic lock: every write states the version it read; a stale one is refused.
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['branch_id', 'id']);
            $table->index(['customer_id', 'id']);
            $table->index(['kind', 'status']);
            $table->index('assignee_id');
            $table->index('created_by');
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('position');
            // custom_product | custom_need | ready_variant
            $table->string('line_type', 24);
            // The exact catalog version the line was added from (immutable; catalog edits write new versions).
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->restrictOnDelete();
            $table->string('catalog_item_type', 60)->nullable();
            $table->unsignedBigInteger('catalog_item_id')->nullable();
            // Frozen specification at add time: catalog payload, chosen options, unit.
            $table->json('snapshot');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 16)->nullable();
            $table->unsignedBigInteger('unit_price_minor')->nullable();
            $table->unsignedBigInteger('line_total_minor')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            // Lines are never edited or deleted: an unwanted line is removed (kept for history) and a new one added.
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'position']);
            $table->index(['catalog_item_type', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('purchase_references');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('sequence_counters');
    }
};
