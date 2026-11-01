<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Branded quote/contract PDFs and the customer's electronic signature from the portal (ADR-0029): generated PDFs kept
| as private attachments with their fingerprint, unguessable signing links, the signature record, and the subject a
| step-up code is bound to.
*/
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_otp_challenges', function (Blueprint $table) {
            // What a step-up code authorizes (`document:{id}`); null for sign-in codes.
            $table->string('subject', 40)->nullable()->after('purpose');
        });

        Schema::create('document_pdfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_document_id')->constrained('agreement_documents')->restrictOnDelete();
            // original | signed (the original pages plus the signature certificate)
            $table->string('variant', 10);
            $table->string('locale', 2);
            // sha256 of everything the PDF shows plus the layout version: a new PDF only when it changes.
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('layout_version');
            $table->foreignId('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['agreement_document_id', 'variant', 'locale', 'fingerprint'], 'document_pdfs_source_unique');
        });

        Schema::create('document_sign_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_document_id')->constrained('agreement_documents')->restrictOnDelete();
            // sha256 of the random token; the token itself is only in the message sent to the customer.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // sms | email
            $table->string('channel', 8);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['agreement_document_id', 'revoked_at']);
        });

        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            // One signature per document: a signed document is final.
            $table->foreignId('agreement_document_id')->unique()->constrained('agreement_documents')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('customer_decision_id')->constrained('customer_decisions')->restrictOnDelete();
            $table->string('signer_name', 120);
            $table->string('locale', 2);
            $table->timestamp('signed_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('content_hash', 64);
            // The PDF the customer saw and signed, and its hash.
            $table->foreignId('document_pdf_id')->constrained('document_pdfs')->restrictOnDelete();
            $table->char('pdf_sha256', 64);
            $table->foreignId('otp_challenge_id')->constrained('customer_otp_challenges')->restrictOnDelete();
            $table->string('otp_channel', 8);
            $table->string('consent_version', 16);
            $table->foreignId('signature_attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->char('signature_sha256', 64);
            $table->foreignId('signed_pdf_id')->nullable()->constrained('document_pdfs')->restrictOnDelete();
            $table->foreignId('sign_link_id')->nullable()->constrained('document_sign_links')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
        Schema::dropIfExists('document_sign_links');
        Schema::dropIfExists('document_pdfs');
        Schema::table('customer_otp_challenges', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
