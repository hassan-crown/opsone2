<?php

namespace App\Console\Commands;

use App\Help\KnowledgeBase\ArticleLibrary;
use App\Help\KnowledgeBase\HelpSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Loads the knowledge base Markdown files (resources/help) into help_articles. Safe to run on every deploy.
 */
#[Signature('help:sync {--path= : Knowledge base folder (default: resources/help)} {--dry-run : Validate and report without writing}')]
#[Description('Sync help articles from the Markdown knowledge base files')]
class HelpSyncCommand extends Command
{
    public function handle(): int
    {
        $path = $this->option('path');
        $root = is_string($path) && $path !== '' ? $path : ArticleLibrary::defaultRoot();

        $result = (new HelpSync(new ArticleLibrary($root)))->run((bool) $this->option('dry-run'));

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                $this->components->error($error);
            }
            $this->components->error(count($result['errors']).' problem(s): nothing was synced.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%sCreated %d, updated %d, unchanged %d, deleted %d.',
            $this->option('dry-run') ? '[dry run] ' : '',
            $result['created'], $result['updated'], $result['unchanged'], $result['deleted'],
        ));

        return self::SUCCESS;
    }
}
