<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\SaveMessageTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\MessageTemplateRequest;
use App\Http\Requests\Rules\WhatsappTemplatesRequest;
use App\Messages\MessageCatalogue;
use App\Messages\MessageTemplates;
use App\Models\MessageTemplate;
use App\Whatsapp\WhatsAppEvent;
use App\Whatsapp\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › messages (ADR-0037): per event, channel and language the built-in text, the facility's wording, the
 * allowed variables and a preview with sample values; plus the approved WhatsApp template name per event.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request, WhatsAppSettings $whatsapp): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $saved = MessageTemplate::query()->get()->keyBy(fn (MessageTemplate $t) => "{$t->event}|{$t->channel}|{$t->locale}");

        $events = [];
        foreach (MessageCatalogue::events() as $event => $definition) {
            $texts = [];
            foreach ($definition['channels'] as $channel) {
                foreach (MessageCatalogue::LOCALES as $locale) {
                    $own = $saved->get("{$event}|{$channel}|{$locale}");
                    $texts[] = [
                        'channel' => $channel, 'locale' => $locale,
                        'default_subject' => $definition['subject'] === null || $channel === 'sms' ? null : MessageTemplates::defaultText($definition['subject'], $locale),
                        'default_body' => $definition['body'] === '' ? '' : MessageTemplates::defaultText($definition['body'], $locale),
                        'subject' => $own?->subject, 'body' => $own?->body,
                    ];
                }
            }
            $events[] = ['event' => $event, 'audience' => $definition['audience'], 'variables' => $definition['variables'], 'texts' => $texts];
        }

        $names = $whatsapp->templateNames();

        return Inertia::render('rules/messages', [
            'events' => $events,
            'whatsapp' => array_map(fn (WhatsAppEvent $e) => ['event' => $e->value, 'name' => $names[$e->value] ?? null, 'default' => $e->defaultTemplate()], WhatsAppEvent::cases()),
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function update(MessageTemplateRequest $request, SaveMessageTemplate $save): RedirectResponse
    {
        $save->handle($request->user(), $request->validated());

        return $this->saved();
    }

    public function reset(Request $request, SaveMessageTemplate $save): RedirectResponse
    {
        $data = Validator::make($request->all(), [
            'event' => ['required', 'string', 'max:48'],
            'channel' => ['required', 'in:'.implode(',', MessageCatalogue::CHANNELS)],
            'locale' => ['required', 'in:'.implode(',', MessageCatalogue::LOCALES)],
        ])->validate();
        $save->reset($request->user(), $data['event'], $data['channel'], $data['locale']);

        return $this->saved();
    }

    /**
     * The text with sample values, for the editor. Writes nothing.
     */
    public function preview(Request $request): JsonResponse
    {
        RulesAccess::authorizeView($request->user());
        $data = Validator::make($request->all(), [
            'event' => ['required', 'string', 'max:48'],
            'locale' => ['required', 'in:'.implode(',', MessageCatalogue::LOCALES)],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:1000'],
        ])->validate();
        $definition = MessageCatalogue::get($data['event']);
        abort_if($definition === null, 422);
        MessageTemplates::checkVariables($data['body'], $definition['variables'], 'body');
        $samples = array_intersect_key(MessageCatalogue::samples($data['locale']), array_flip($definition['variables']));

        return response()->json([
            'subject' => isset($data['subject']) ? MessageTemplates::fill($data['subject'], $samples) : null,
            'body' => MessageTemplates::fill($data['body'], $samples),
        ]);
    }

    public function whatsapp(WhatsappTemplatesRequest $request, WhatsAppSettings $whatsapp): RedirectResponse
    {
        RulesAccess::authorize($request->user());
        /** @var array<string, string|null> $names */
        $names = $request->validated('templates');
        $whatsapp->saveTemplateNames($request->user(), $names);

        return $this->saved();
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.messages');
    }
}
