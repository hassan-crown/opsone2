<?php

namespace App\Console\Commands;

use App\Ai\AiSuggestions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:expire-suggestions')]
#[Description('Mark AI suggestions nobody acted on as expired')]
class AiExpireSuggestionsCommand extends Command
{
    public function handle(AiSuggestions $suggestions): int
    {
        $this->components->info($suggestions->expireDue().' suggestion(s) expired.');

        return self::SUCCESS;
    }
}
