<?php

use App\Actions\StateMachines\M01Invitation\T02ExpireInvitation;
use App\Actions\StateMachines\M37OwnershipTransfer\T04ExpireOwnershipTransfer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Time service for M-01-T02 (invitation expiry) and M-37-T04 (ownership transfer expiry).
Artisan::command('access:expire', function (T02ExpireInvitation $invitations, T04ExpireOwnershipTransfer $transfers) {
    $this->info(sprintf('Expired %d invitation(s) and %d ownership transfer(s).', $invitations->handleDue(), $transfers->handleDue()));
})->purpose('Expire lapsed staff invitations and ownership transfer requests');

Schedule::command('access:expire')->everyFifteenMinutes()->withoutOverlapping();
