<?php

namespace App\Providers;

use App\Inbox\ConversationCustomers;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Order;
use App\Notifications\Channels\LogSmsChannel;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsApp\CloudApiWhatsAppChannel;
use App\Notifications\Channels\WhatsApp\FakeWhatsAppChannel;
use App\Notifications\Channels\WhatsApp\LogWhatsAppChannel;
use App\Notifications\Channels\WhatsApp\WhatsAppChannel;
use App\Notifications\NotificationTargets;
use App\Whatsapp\CustomerEvents;
use App\Whatsapp\Inbox\CloudConversationTransport;
use App\Whatsapp\Inbox\ConversationTransport;
use App\Whatsapp\Inbox\FakeConversationTransport;
use App\Whatsapp\Inbox\LogConversationTransport;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationTargets::class);
        // Q-24: the provider is chosen before P8; until then only the development log driver exists.
        $this->app->bind(SmsChannel::class, LogSmsChannel::class);
        // ADR-0030: the driver comes from WHATSAPP_DRIVER (log | fake | cloud).
        $this->app->singleton(WhatsAppChannel::class, fn () => match (config('whatsapp.driver')) {
            'cloud' => new CloudApiWhatsAppChannel,
            'fake' => new FakeWhatsAppChannel,
            default => new LogWhatsAppChannel,
        });
        // AI-3 inbox: same driver switch, same facility credentials (Q-72).
        $this->app->singleton(ConversationTransport::class, fn () => match (config('whatsapp.driver')) {
            'cloud' => new CloudConversationTransport(new CloudApiWhatsAppChannel),
            'fake' => new FakeConversationTransport,
            default => new LogConversationTransport,
        });
    }

    public function boot(): void
    {
        // Customer WhatsApp/SMS notifications for domain events, without touching the actions that cause them.
        Appointment::saved(CustomerEvents::appointmentSaved(...));
        AgreementDocument::saved(CustomerEvents::documentSaved(...));
        Order::saved(CustomerEvents::orderSaved(...));
        // A conversation follows its customer's branch (AI-3): a branch transfer moves the conversation's scope too.
        Customer::updated(ConversationCustomers::customerUpdated(...));
    }
}
