<?php

namespace Database\Seeders;

use App\Help\KnowledgeBase\HelpSync;
use App\Models\WhatsNewEntry;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Help articles and the first release note. Idempotent. Articles live as Markdown files in resources/help
 * (ADR-0048, same as `php artisan help:sync`); add or change an article by editing its file, never here.
 */
class HelpSeeder extends Seeder
{
    public function run(): void
    {
        // Articles are Markdown files under resources/help (ADR-0048); help:sync mirrors them and fails on any
        // invalid file, which stops the seed loudly rather than shipping a broken help center.
        $result = HelpSync::fromDefaultRoot()->run();
        if ($result['errors'] !== []) {
            throw new RuntimeException("help:sync failed:\n".implode("\n", $result['errors']));
        }

        WhatsNewEntry::query()->updateOrCreate(['version' => '0.3.0'], [
            'released_on' => '2026-11-01',
            'title_ar' => 'المساعدة والبحث ومركز العمل',
            'title_en' => 'Help, search and the work center',
            'body_ar' => 'مركز المساعدة بمقالات عربية وإنجليزية، وبحث عام ضمن صلاحياتك، وصفحة رئيسية جديدة للمهام والتنبيهات.',
            'body_en' => 'A help center with Arabic and English articles, global search limited to what you may see, and a new home page for tasks and alerts.',
            'published_at' => now(),
        ]);
    }
}
