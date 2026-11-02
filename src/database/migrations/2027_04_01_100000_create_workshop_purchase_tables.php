<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No-Code phase 1b (ADR-0038): external workshops as suppliers, workshop purchase orders followed by the flow, stage
 * responsibility by role or branch, and the facility's own purchase-order document with its emailed copies.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // An external workshop that makes the facility's custom orders (ADR-0038), with its usual lead time.
            $table->boolean('is_workshop')->default(false)->after('supplies');
            $table->unsignedSmallInteger('lead_days')->nullable()->after('is_workshop');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            // The flow reference (workshop_order / supply_follow_up `reference`) this order fulfils, when the order's
            // flow has more than one; null follows the only one of its kind.
            $table->string('flow_reference', 30)->nullable()->after('notes');
        });

        Schema::table('order_stages', function (Blueprint $table) {
            // The stage's responsible as the flow named it (role / per branch are resolved when the stage starts).
            $table->json('follow_up_target')->nullable()->after('follow_up_department_id');
            // The workshop's expected date (latest open workshop purchase order of the stage), the stage's real due.
            $table->date('expected_on')->nullable()->after('target_days');
        });

        Schema::table('order_actions', function (Blueprint $table) {
            // Role / per-branch assignment as defined (resolved when the task is created).
            $table->json('assign_target')->nullable()->after('assign_id');
        });

        // The facility's purchase-order document (ADR-0038): append-only versions; the latest is current. Each
        // generated PDF records the version it used.
        Schema::create('purchase_document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            // Ordered blocks with visibility and their options (see App\Procurement\PurchaseDocumentLayout).
            $table->json('layout');
            // {subject: {ar, en}, body: {ar, en}} with closed placeholders.
            $table->json('email');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One generated PDF per purchase-order content and template version (fingerprint), stored as a private file.
        Schema::create('purchase_order_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedInteger('template_version');
            $table->char('fingerprint', 64);
            $table->string('locale', 2);
            $table->foreignId('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'fingerprint']);
        });

        // Each emailed copy to the supplier: what was sent, by whom, and the outbox row that delivers it (queued,
        // retried with backoff, never sent twice); its final status is mirrored here.
        Schema::create('purchase_order_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('purchase_order_document_id')->constrained('purchase_order_documents')->restrictOnDelete();
            $table->foreignId('outbox_message_id')->nullable()->constrained('outbox_messages')->nullOnDelete();
            $table->string('recipient', 190);
            $table->string('subject', 200);
            $table->text('body');
            $table->string('locale', 2);
            // Mirrors the outbox row (which is pruned after 30 days): queued | sent | failed.
            $table->string('status', 16)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('retry_of')->nullable()->constrained('purchase_order_sends')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_sends');
        Schema::dropIfExists('purchase_order_documents');
        Schema::dropIfExists('purchase_document_versions');
        Schema::table('order_actions', fn (Blueprint $table) => $table->dropColumn('assign_target'));
        Schema::table('order_stages', fn (Blueprint $table) => $table->dropColumn(['follow_up_target', 'expected_on']));
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('flow_reference'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['is_workshop', 'lead_days']));
    }
};
