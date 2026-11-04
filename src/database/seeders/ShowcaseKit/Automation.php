<?php

namespace Database\Seeders\ShowcaseKit;

use App\Access\TransitionRecorder;
use App\Actions\Rules\PublishConfigRelease;
use App\Actions\Rules\SaveAutomationRule;
use App\Automation\AutomationEngine;
use App\Automation\AutomationRules;
use App\Automation\AutomationTimers;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigSnapshot;
use App\Models\Automation\AutomationEvent;
use App\Models\Automation\AutomationRule;
use App\Support\RequestCache;
use Carbon\CarbonImmutable;

/**
 * No-Code phase 3 (ADR-0042) in the story: in mid-July the owner turns the five ready templates into rules plus a
 * «thank you» message of their own, and publishes them; from then on the scheduler's daily pass raises the timers and
 * the worker runs every event (here, at the daily pass, since the walk has no worker). In September the owner stops
 * the «thank you» rule (it duplicated the WhatsApp quote message) with the kill switch.
 */
final class Automation
{
    public const PUBLISH_AT = '2026-07-15 09:00';

    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        $story = new Story('automation');
        $this->s->timeline->at(CarbonImmutable::parse(self::PUBLISH_AT, 'Asia/Riyadh'), $story, 'rules', fn () => $this->rules());
        $this->s->timeline->at(CarbonImmutable::parse('2026-09-10 12:00', 'Asia/Riyadh'), $story, 'stop', fn () => $this->stopThanks());
        for ($day = CarbonImmutable::parse(self::PUBLISH_AT, 'Asia/Riyadh')->setTime(10, 30); $day->lessThanOrEqualTo($this->s->end); $day = $day->addDay()) {
            $this->s->timeline->at($day, new Story('automation-tick-'.$day->format('md')), 'tick', fn () => $this->tick());
        }
    }

    private function rules(): void
    {
        $owner = $this->s->users['owner'];
        $save = app(SaveAutomationRule::class);
        foreach (AutomationRules::TEMPLATES as $key => $template) {
            $save->handle($owner, null, [...$template, 'key' => $key, 'enabled' => true], null, TransitionRecorder::SOURCE_SEEDER);
        }
        $save->handle($owner, null, [
            'key' => 'quote_thanks', 'name' => ['ar' => 'رسالة شكر بعد اعتماد العرض', 'en' => 'Thank-you message after quote approval'],
            'trigger' => 'document.approved', 'params' => [],
            'conditions' => ['match' => 'all', 'items' => [['field' => 'document.kind', 'op' => 'eq', 'value' => 'quote']]],
            'actions' => [['type' => 'send_message', 'template' => 'quote_ready', 'channel' => 'auto']], 'enabled' => true,
        ], null, TransitionRecorder::SOURCE_SEEDER);
        app(PublishConfigRelease::class)->publish($owner, 'قواعد الأتمتة: تذكير الدفعات، متابعة العملاء المحتملين والتحصيل، إبلاغ العميل بموعد التركيب.',
            ConfigReleases::latest()->number ?? 0, ConfigSnapshot::hash(ConfigSnapshot::capture()), TransitionRecorder::SOURCE_SEEDER);
    }

    private function stopThanks(): void
    {
        $rule = AutomationRule::query()->where('key', 'quote_thanks')->first();
        if ($rule !== null) {
            app(SaveAutomationRule::class)->kill($this->s->users['owner'], $rule, 'تتكرر مع رسالة واتساب «العرض جاهز» التي يرسلها النظام.', TransitionRecorder::SOURCE_SEEDER);
        }
    }

    /** The scheduler's `automation:tick` and the queue worker, once a day in the story. */
    private function tick(): void
    {
        app(RequestCache::class)->flush();
        app(AutomationTimers::class)->tick(now());
        $engine = app(AutomationEngine::class);
        AutomationEvent::query()->whereNull('processed_at')->orderBy('id')->pluck('id')->each(function (int $id) use ($engine) {
            try {
                $engine->process($id);
            } catch (\RuntimeException) {
                // A failed action is retried by the next pass, as the queue would.
            }
        });
    }
}
