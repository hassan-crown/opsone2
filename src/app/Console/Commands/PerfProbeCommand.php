<?php

namespace App\Console\Commands;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Portal\PortalSession;
use App\Store\Cart;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL/TESTING ONLY (P9, ADR-0027): replays the hot paths in-process through the HTTP kernel against the current
 * database (the PerformanceSeeder dataset) and reports server time p50/p95, query count and payload size per page.
 * Time covers routing, middleware, controllers, queries and serialization — not PHP-FPM start-up or the network.
 */
class PerfProbeCommand extends Command
{
    protected $signature = 'perf:probe
        {--runs=15 : Measured runs per page (after two warm-up runs)}
        {--only= : Comma-separated page keys}
        {--json= : Also write the results to this file}
        {--top=0 : Print the N most repeated statements of each page (finds N+1)}';

    protected $description = 'Measure the hot paths in-process: p50/p95 server time, queries, payload (local/testing only)';

    public function handle(Kernel $kernel): int
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('perf:probe runs in the local and testing environments only.');
        }

        $owner = User::query()->where('email', 'owner@demo.test')->firstOrFail();
        $sales = User::query()->where('email', 'like', 'perf-sales-%')->orderBy('id')->firstOrFail();
        $executor = User::query()->where('email', 'like', 'perf-executor-%')->orderBy('id')->firstOrFail();
        $supervisor = User::query()->where('email', 'supervisor@demo.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@demo.test')->firstOrFail();
        $order = Order::query()->where('kind', 'custom')->where('status', 'in_progress')->orderByDesc('id')->firstOrFail();
        // The portal customer with the most orders: the heaviest workspace a customer can open.
        $customerId = (int) DB::table('orders')->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('customers.portal_login_enabled', true)->groupBy('orders.customer_id')
            ->orderByRaw('COUNT(*) DESC')->orderBy('orders.customer_id')->value('orders.customer_id');
        $customer = Customer::query()->findOrFail($customerId);
        $portalOrder = Order::query()->where('customer_id', $customer->id)->where('kind', 'custom')->orderByDesc('id')->first()
            ?? Order::query()->where('customer_id', $customer->id)->orderByDesc('id')->firstOrFail();
        $variants = DB::table('catalog_ready_variants')->where('code', 'like', 'PERF-%')->orderBy('id')->limit(3)->pluck('id')->all();
        $branch = (int) $order->branch_id;

        $pages = [
            'dashboard' => ['/dashboard', $owner],
            'orders' => ['/orders', $owner],
            'orders_filtered' => ["/orders?status=in_progress&branch={$branch}&page=20", $owner],
            'orders_search' => ['/orders?q=محمد', $owner],
            'orders_deep_page' => ['/orders?page=900', $owner],
            'orders_scoped' => ['/orders', $sales],
            'order_show' => ["/orders/{$order->id}", $owner],
            'customers' => ['/customers', $owner],
            'customers_search' => ['/customers?q=فهد', $owner],
            'search' => ['/search?q=فهد', $owner],
            'tasks' => ['/tasks', $owner],
            'tasks_inbox' => ['/tasks?view=inbox', $owner],
            'tasks_executor' => ['/tasks', $executor],
            'tasks_supervisor' => ['/tasks?view=inbox', $supervisor],
            'dashboard_executor' => ['/dashboard', $executor],
            'calendar' => ['/calendar', $owner],
            'finance' => ['/finance', $owner],
            'finance_scoped' => ['/finance', $finance],
            'stock' => ['/stock', $owner],
            'store' => ['/ar/store', null],
            'store_checkout' => ['/ar/store/checkout', $customer, array_fill_keys($variants, 2)],
            'portal_home' => ['/portal', $customer],
            'portal_orders' => ['/portal/orders', $customer],
            'portal_order' => ["/portal/orders/{$portalOrder->id}", $customer],
        ];
        $only = array_filter(explode(',', (string) $this->option('only')));
        if ($only !== []) {
            $pages = array_intersect_key($pages, array_flip($only));
        }

        $queries = 0;
        $queryMs = 0.0;
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$queries, &$queryMs, &$statements) {
            $queries++;
            $queryMs += $query->time;
            $statements[$query->sql] = ($statements[$query->sql] ?? 0) + 1;
        });

        $runs = max(1, (int) $this->option('runs'));
        $results = [];
        foreach ($pages as $key => $page) {
            [$uri, $actor] = $page;
            $times = [];
            $count = 0;
            $sqlMs = 0.0;
            $bytes = 0;
            $status = 0;
            $once = [];
            $firstKb = 0.0;
            for ($i = 0; $i < $runs + 2; $i++) {
                $queries = 0;
                $queryMs = 0.0;
                $statements = [];
                $request = $this->request($uri, $actor, $page[2] ?? null);
                if ($once !== []) {
                    // Later visits: the client already holds the once-props (translations) from the first one.
                    $request->headers->set('X-Inertia-Except-Once-Props', implode(',', $once));
                }
                $start = hrtime(true);
                $response = $kernel->handle($request);
                $elapsed = (hrtime(true) - $start) / 1e6;
                $kernel->terminate($request, $response);
                if ($i >= 2) {
                    $times[] = $elapsed;
                    $count = $queries;
                    $sqlMs += $queryMs;
                }
                $status = $response->getStatusCode();
                $bytes = strlen((string) $response->getContent());
                if ($once === [] && $status === 200) {
                    $firstKb = round($bytes / 1024, 1);
                    $page0 = json_decode((string) $response->getContent(), true);
                    $once = is_array($page0) ? array_keys((array) ($page0['onceProps'] ?? [])) : [];
                }
            }
            sort($times);
            if ((int) $this->option('top') > 0) {
                /** @var array<string, int> $statements filled by the listener */
                arsort($statements);
                $this->line("<info>{$key}</info>");
                foreach (array_slice($statements, 0, (int) $this->option('top'), true) as $sql => $n) {
                    $this->line(sprintf('%5d × %s', $n, mb_substr($sql, 0, 220)));
                }
            }
            $results[$key] = [
                'uri' => $uri,
                'status' => $status,
                'p50_ms' => round($this->percentile($times, 50), 1),
                'p95_ms' => round($this->percentile($times, 95), 1),
                'queries' => $count,
                'sql_ms' => round($sqlMs / $runs, 1),
                'kb' => round($bytes / 1024, 1),
                'first_kb' => $firstKb,
            ];
        }

        $this->table(['page', 'status', 'p50 ms', 'p95 ms', 'queries', 'sql ms', 'KB', 'first KB'], array_map(
            fn (string $key, array $r) => [$key, $r['status'], $r['p50_ms'], $r['p95_ms'], $r['queries'], $r['sql_ms'], $r['kb'], $r['first_kb']],
            array_keys($results), $results,
        ));
        if (is_string($this->option('json')) && $this->option('json') !== '') {
            file_put_contents($this->option('json'), (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    /**
     * A fresh request as the actor: staff on the web guard, a customer on the portal guard with its session stamp.
     * Request-scoped services (the Gate memo, the license) are rebuilt as they would be for a new request.
     *
     * @param  array<int, int>|null  $cart
     */
    private function request(string $uri, User|Customer|null $actor, ?array $cart): Request
    {
        app()->forgetScopedInstances();
        Auth::forgetGuards();
        $session = app('session')->driver();
        $session->flush();

        if ($actor instanceof User) {
            Auth::guard('web')->setUser($actor);
        } elseif ($actor instanceof Customer) {
            Auth::guard(PortalSession::GUARD)->setUser($actor);
            $session->put(PortalSession::STAMP, $actor->portal_session_version);
        }
        if ($cart !== null) {
            $session->put(Cart::KEY, $cart);
        }

        $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'text/html', 'HTTP_HOST' => (string) parse_url((string) config('app.url'), PHP_URL_HOST)]);
        if (! str_starts_with($uri, '/ar/') && ! str_starts_with($uri, '/en/')) {
            // Inertia visit: JSON page object only (what a navigation inside the app costs).
            $request->headers->set('X-Inertia', 'true');
            $request->headers->set('X-Inertia-Version', (string) app(HandleInertiaRequests::class)->version($request));
        }

        return $request;
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, int $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $index = (int) ceil($p / 100 * count($sorted)) - 1;

        return $sorted[max(0, min(count($sorted) - 1, $index))];
    }
}
