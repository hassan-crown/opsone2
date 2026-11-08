<?php

namespace App\Console\Commands;

use App\Help\KnowledgeBase\ArticleLibrary;
use App\Help\Pdf\RoleBookBuilder;
use App\Help\Pdf\RoleBookPdf;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * One training/handover PDF per role from the knowledge base files (not the database: it works on whatever content
 * is in resources/help and is simply run again after an update). Output: storage/app/help-pdf/ar/{role}.pdf.
 */
#[Signature('help:pdf {role? : owner, manager, supervisor, sales, executor, finance, inventory, content, field_sales or customer} {--all : One PDF for every role and for customers} {--path= : Knowledge base folder (default: resources/help)} {--output= : Output folder (default: storage/app/help-pdf/ar)}')]
#[Description('Generate the Arabic PDF guide of a role (or all roles) from the knowledge base')]
class HelpPdfCommand extends Command
{
    public function handle(): int
    {
        $role = $this->argument('role');
        $roles = $this->option('all') ? RoleBookBuilder::roles() : (is_string($role) && $role !== '' ? [$role] : []);
        if ($roles === []) {
            $this->components->error('Name a role ('.implode(', ', RoleBookBuilder::roles()).') or pass --all.');

            return self::FAILURE;
        }

        $path = $this->option('path');
        $root = is_string($path) && $path !== '' ? $path : ArticleLibrary::defaultRoot();

        ['articles' => $articles, 'errors' => $errors] = (new ArticleLibrary($root))->load();
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }
            $this->components->error(count($errors).' problem(s) in the knowledge base: no PDF was written.');

            return self::FAILURE;
        }

        $output = $this->option('output');
        $directory = rtrim(is_string($output) && $output !== '' ? $output : storage_path('app/help-pdf/ar'), '/');
        File::ensureDirectoryExists($directory);

        foreach ($roles as $key) {
            try {
                $book = (new RoleBookBuilder)->build($key, $articles);
            } catch (InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            if ($book->articles() === []) {
                $this->components->warn("«{$key}»: no articles, skipped.");

                continue;
            }

            $file = $directory.'/'.$key.'.pdf';
            File::put($file, (new RoleBookPdf)->render($book, $root));
            $this->components->info(sprintf('%s: %d articles → %s (%d KB)', $key, count($book->articles()), $file, intdiv((int) filesize($file), 1024)));
        }

        return self::SUCCESS;
    }
}
