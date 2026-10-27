<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('category', 40)->index();
            // Route name of the page this article explains (contextual help, SHR-07); null for general articles.
            $table->string('page_key', 120)->nullable()->index();
            $table->string('title_ar', 200);
            $table->string('title_en', 200)->nullable();
            $table->text('summary_ar')->nullable();
            $table->text('summary_en')->nullable();
            $table->longText('body_ar');
            $table->longText('body_en')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('whats_new_entries', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->date('released_on');
            $table->string('title_ar', 200);
            $table->string('title_en', 200)->nullable();
            $table->text('body_ar');
            $table->text('body_en')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('subject', 150);
            $table->text('message');
            $table->string('page_path', 300)->nullable();
            $table->string('locale', 5);
            // Delivery goes through notifications (P3 part B) later: pending → sent.
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });

        // Q-26: search is MariaDB FULLTEXT limited to what the user's grants allow. The test suite runs on SQLite,
        // where the search falls back to LIKE.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('help_articles', function (Blueprint $table) {
                $table->fullText(['title_ar', 'summary_ar', 'body_ar'], 'help_articles_ft_ar');
                $table->fullText(['title_en', 'summary_en', 'body_en'], 'help_articles_ft_en');
            });
            Schema::table('branches', fn (Blueprint $table) => $table->fullText(['name_ar', 'name_en', 'code'], 'branches_ft'));
            Schema::table('users', fn (Blueprint $table) => $table->fullText(['name', 'email'], 'users_ft'));
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropFullText('branches_ft'));
            Schema::table('users', fn (Blueprint $table) => $table->dropFullText('users_ft'));
        }

        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('whats_new_entries');
        Schema::dropIfExists('help_articles');
    }
};
