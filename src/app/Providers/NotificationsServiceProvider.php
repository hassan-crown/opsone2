<?php

namespace App\Providers;

use App\Notifications\Channels\LogSmsChannel;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\NotificationTargets;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationTargets::class);
        // Q-24: the provider is chosen before P8; until then only the development log driver exists.
        $this->app->bind(SmsChannel::class, LogSmsChannel::class);
    }
}
