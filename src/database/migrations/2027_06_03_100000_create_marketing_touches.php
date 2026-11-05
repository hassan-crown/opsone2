<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant marketing readiness, batch 4 (ADR-0045): where a lead, appointment request, store checkout, customer or
 * order came from. Two rows per record — the visit that first brought the visitor (`first`) and the last one that came
 * from a campaign or another site (`last`) — copied from the first-party `opsone_src` cookie (Q-79) when the record is
 * created, and inherited by the order / customer a lead becomes. Append-only: a record's touches never change.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_touches', function (Blueprint $table) {
            $table->id();
            $table->morphs('touchable');
            $table->string('kind', 8); // first | last
            $table->string('source', 100);
            $table->string('medium', 100);
            $table->string('campaign', 150)->nullable();
            $table->string('term', 150)->nullable();
            $table->string('content', 150)->nullable();
            // gclid / gbraid / wbraid / fbclid / ttclid / sccid and its value (for a later server-side conversion).
            $table->string('click_id_type', 10)->nullable();
            $table->string('click_id', 255)->nullable();
            $table->string('referrer_host', 190)->nullable();
            $table->string('landing_path', 255)->nullable();
            $table->timestamp('touched_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['touchable_type', 'touchable_id', 'kind']);
            $table->index(['kind', 'source', 'medium']);
            $table->index('campaign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_touches');
    }
};
