<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ZATCA (Fatoora) e-invoicing (ADR-0032): the seller's tax profile, the onboarded EGS devices (CSR, encrypted key and
 * CSIDs), the issued invoices / credit notes / debit notes (immutable, hash-chained) and the buyer's national address.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            // fake | simulation | production (only those config('zatca.environments') allows)
            $table->string('environment', 16)->default('fake');
            $table->string('seller_name_ar', 200)->nullable();
            $table->string('seller_name_en', 200)->nullable();
            $table->string('vat_number', 15)->nullable();
            $table->string('commercial_registration', 10)->nullable();
            $table->string('street', 120)->nullable();
            $table->string('building_number', 4)->nullable();
            $table->string('additional_number', 4)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('postal_code', 5)->nullable();
            // CSR organizationalUnitName (branch) and businessCategory (industry).
            $table->string('branch_name', 120)->nullable();
            $table->string('industry', 120)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('zatca_devices', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 16);
            // draft (key + CSR) → compliance (compliance CSID, checks running) → active (production CSID) | retired
            $table->string('status', 16);
            $table->string('egs_serial', 120);
            $table->string('common_name', 120);
            $table->text('csr');
            // Encrypted with APP_KEY (model cast), never logged, never in a payload.
            $table->text('private_key');
            $table->string('compliance_request_id', 64)->nullable();
            $table->text('compliance_token')->nullable();
            $table->text('compliance_secret')->nullable();
            $table->json('compliance_checks')->nullable();
            $table->text('production_token')->nullable();
            $table->text('production_secret')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->string('last_error', 500)->nullable();
            // One live (non-retired) device per facility.
            $table->unsignedTinyInteger('live_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // INV-YYYY-000001 / CRN-YYYY-000001 / DBN-YYYY-000001 (gapless per kind and year)
            $table->string('number', 32)->unique();
            // invoice | credit_note | debit_note
            $table->string('kind', 16);
            // standard (B2B, cleared) | simplified (B2C, reported)
            $table->string('type', 16);
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            // One credit note per executed refund (idempotent listener).
            $table->foreignId('refund_id')->nullable()->unique()->constrained('refunds')->restrictOnDelete();
            $table->foreignId('original_invoice_id')->nullable()->constrained('e_invoices')->restrictOnDelete();
            // = order_id for kind invoice: one tax invoice per order, even under concurrency.
            $table->unsignedBigInteger('order_invoice_key')->nullable()->unique();
            $table->text('reason')->nullable();
            $table->timestamp('issued_at');
            // Local (facility time zone) date and time written into the XML and the QR.
            $table->date('issue_date');
            $table->string('issue_time', 8);
            $table->string('currency', 3);
            $table->json('seller');
            $table->json('buyer');
            $table->json('lines');
            $table->unsignedSmallInteger('vat_rate_bp');
            $table->bigInteger('net_minor');
            $table->bigInteger('vat_minor');
            $table->bigInteger('total_minor');
            $table->string('payment_means_code', 4);
            // The hash chain (per device; null device = phase 1 generation before onboarding).
            $table->foreignId('device_id')->nullable()->constrained('zatca_devices')->restrictOnDelete();
            $table->unsignedBigInteger('icv');
            // Base64 text: 44 chars for a SHA-256 digest, 88 for the first PIH (Base64 of the hex digest of "0").
            $table->string('previous_hash', 100);
            $table->string('hash', 100);
            $table->boolean('signed')->default(false);
            $table->longText('xml');
            $table->text('qr');
            // not_required | pending | reported | cleared | rejected | failed (+ warnings flag)
            $table->string('submission_status', 16);
            $table->boolean('submission_warnings')->default(false);
            $table->unsignedSmallInteger('submission_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->json('zatca_response')->nullable();
            $table->longText('cleared_xml')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['device_id', 'icv']);
            $table->index(['order_id', 'kind']);
            $table->index(['submission_status', 'id']);
            $table->index('issued_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Saudi national address for standard (B2B) tax invoices: street, building, district, city, postal code.
            $table->json('national_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('national_address');
        });
        Schema::dropIfExists('e_invoices');
        Schema::dropIfExists('zatca_devices');
        Schema::dropIfExists('zatca_settings');
    }
};
