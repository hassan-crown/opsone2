<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge base per role (ADR-0048): articles live as Markdown files under resources/help and `help:sync` mirrors
 * them here. The frontmatter's `order` is the existing `sort` column.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('help_articles', function (Blueprint $table) {
            // Who reads the article: facility staff or the customer portal (App\Help\HelpAudience).
            $table->string('audience', 20)->default('staff')->after('slug')->index();
            // Role keys of PermissionCatalogue::ROLES, for «My guide» only; never an access rule.
            $table->json('roles')->nullable()->after('page_key');
            // P-xx codes: shown to whoever holds any of them; empty = every reader of the audience.
            $table->json('permissions')->nullable()->after('roles');
            // License capability the article's feature needs; unlicensed = owner and manager only, with a badge.
            $table->string('capability', 60)->nullable()->after('permissions');
            // File the article came from (relative to resources/help); null for rows not managed by help:sync.
            $table->string('source_path', 300)->nullable()->after('capability');
        });

        Schema::table('users', function (Blueprint $table) {
            // «Start here» checklist closed by the user (per user, every device).
            $table->timestamp('help_start_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('help_start_dismissed_at'));
        Schema::table('help_articles', function (Blueprint $table) {
            $table->dropIndex(['audience']);
            $table->dropColumn(['audience', 'roles', 'permissions', 'capability', 'source_path']);
        });
    }
};
