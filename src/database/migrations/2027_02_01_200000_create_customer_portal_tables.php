<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Customer workspace (P8 part B, ADR-0022): passwordless sign-in with one-time codes (Q-47), a session stamp that ends
| every customer session at once, and the customer's own appointment change request (P-70).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // First successful sign-in (WEB-08); staff creating the customer never activates the account.
            $table->timestamp('portal_activated_at')->nullable()->after('portal_login_enabled');
            // Set when a code sent to the email was used: receiving it proves the address.
            $table->timestamp('email_verified_at')->nullable()->after('email');
            // Every customer session carries this stamp; bumping it ends them all («sign out everywhere»).
            $table->unsignedInteger('portal_session_version')->default(1)->after('portal_activated_at');
            $table->string('portal_locale', 2)->nullable()->after('portal_session_version');
            $table->timestamp('portal_last_login_at')->nullable()->after('portal_locale');
        });

        Schema::create('customer_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // sha256 of the normalized identifier the customer typed (mobile E.164 or lower-case email).
            $table->char('identifier_hash', 64);
            // login | activation
            $table->string('purpose', 16);
            // sms | email
            $table->string('channel', 8);
            // HMAC of the 6-digit code with the app key; the code itself is never stored.
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            // Used, burned after too many wrong codes, or superseded by a newer code.
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['identifier_hash', 'consumed_at']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            // The customer's request to move the appointment (P-70): {note, preferred, requested_at}. The original
            // time stays booked; staff answer it with a proposal (M-16-T03) or keep the time.
            $table->json('customer_request')->nullable()->after('pending_change');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('customer_request');
        });
        Schema::dropIfExists('customer_otp_challenges');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_activated_at', 'email_verified_at', 'portal_session_version', 'portal_locale', 'portal_last_login_at']);
        });
    }
};
