<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The facility's bank accounts for customer transfers (ADR-0046). One or more accounts; exactly one active account is
 * the default, and only that one is shown to customers (portal pay page, quote/contract/invoice PDFs, due reminders).
 * The IBAN is stored normalised (uppercase, no spaces). Edited with P-02, audited.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name', 100);
            $table->string('beneficiary_name', 150);
            $table->string('iban', 34)->unique();
            $table->string('account_number', 40)->nullable();
            $table->string('swift_code', 11)->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->string('transfer_note', 300)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_bank_accounts');
    }
};
