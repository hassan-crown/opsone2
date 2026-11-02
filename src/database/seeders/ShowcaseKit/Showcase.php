<?php

namespace Database\Seeders\ShowcaseKit;

use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared state and the ordered phases of the showcase dataset.
 */
final class Showcase
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public Rng $rng;

    public Timeline $timeline;

    public Assets $assets;

    public Catalog $catalog;

    public People $people;

    public CustomOrders $customOrders;

    public Leads $leads;

    public Supply $supply;

    public ReadyOrders $ready;

    public Storefront $store;

    public Specials $specials;

    public Support $support;

    public Site $site;

    public Portal $portal;

    public Messaging $messaging;

    public EInvoicing $einvoicing;

    public Installations $installations;

    /** @var array<string, User> */
    public array $users = [];

    /** @var array<string, string> user key => role template key */
    public array $roles = [];

    /** @var array<string, Branch> */
    public array $branches = [];

    /** @var array<string, Department> */
    public array $departments = [];

    public function __construct(public readonly float $scale, public readonly bool $images, public readonly ?Command $console)
    {
        $this->start = CarbonImmutable::parse('2026-05-01 08:00', 'Asia/Riyadh');
        $this->end = CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Riyadh');
        $this->rng = new Rng(20261101);
        $this->timeline = new Timeline($this->end);
        $this->timeline->trace = (bool) getenv('SHOWCASE_TRACE');
        $this->assets = new Assets($images);
        $this->catalog = new Catalog($this);
        $this->people = new People($this->rng);
        $this->customOrders = new CustomOrders($this);
        $this->leads = new Leads($this);
        $this->supply = new Supply($this);
        $this->ready = new ReadyOrders($this);
        $this->store = new Storefront($this);
        $this->specials = new Specials($this);
        $this->support = new Support($this);
        $this->site = new Site($this);
        $this->portal = new Portal($this);
        $this->messaging = new Messaging;
        $this->einvoicing = new EInvoicing($this);
        $this->installations = new Installations($this);
    }

    public function n(int $full): int
    {
        return max(1, (int) round($full * $this->scale));
    }

    public function info(string $message): void
    {
        if ($this->console !== null) {
            $this->console->info($message);
        } elseif (! app()->runningUnitTests()) {
            fwrite(STDERR, $message.PHP_EOL);
        }
    }

    public function warn(string $message): void
    {
        if ($this->console !== null) {
            $this->console->warn($message);
        } elseif (! app()->runningUnitTests()) {
            fwrite(STDERR, '! '.$message.PHP_EOL);
        }
    }

    public function run(): void
    {
        // The historical walk loads sibling records the screens would eager-load; lazy loading stays refused in the app.
        Model::preventLazyLoading(false);

        $this->info('Organisation…');
        (new Foundation($this))->run();
        $this->messaging->enable();
        $this->einvoicing->enable();
        $this->info('Catalogue…');
        $this->catalog->run();
        $this->store->setup();
        $this->info('Public site…');
        $this->site->run();
        $this->info('Procurement and stock…');
        $this->supply->run();
        $this->info('Leads and custom orders…');
        $this->leads->run();
        $this->ready->run();
        $this->store->run();
        $this->specials->run();
        $this->support->run();
        $this->portal->run();
        $this->timeline->run();
        $this->messaging->settle();
        $this->einvoicing->settle();
        foreach ($this->timeline->failures as $failure) {
            $this->warn($failure);
        }
        $this->info(count($this->timeline->failures).' story failures');
    }

    public function finish(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
