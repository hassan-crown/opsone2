<?php

namespace App\Console\Commands;

use App\Actions\Organization\CreateOwnerInvitation;
use App\Actions\Organization\SendInvitationLink;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('org:invite-owner {email : The first owner\'s email} {--name= : Display name for the invitation page}')]
#[Description('Provisioning: email the facility\'s first owner a single-use invitation link (valid 7 days)')]
class OrgInviteOwnerCommand extends Command
{
    public function handle(CreateOwnerInvitation $create, SendInvitationLink $send): int
    {
        try {
            [$invitation, $token] = $create->handle((string) $this->argument('email'), $this->option('name') ?: null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $send->handle($invitation, $token);
        $this->info("Owner invitation emailed to {$invitation->email}; it expires {$invitation->expires_at->toDateString()}.");

        return self::SUCCESS;
    }
}
