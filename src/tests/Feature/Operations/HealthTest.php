<?php

use App\Operations\Health\Heartbeats;
use Illuminate\Support\Facades\Cache;

/*
| /health (ADR-0028): 200 with one word per dependency when healthy, 503 otherwise; no session, no details.
*/

beforeEach(function () {
    Cache::flush();
});

it('reports ok once the scheduler and a worker have beaten', function () {
    config(['queue.default' => 'sync']);
    $this->artisan('ops:heartbeat')->assertSuccessful();

    $this->getJson('/health')->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'scheduler' => 'ok']])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertCookieMissing(config('session.cookie'));
});

it('fails when the scheduler or the queue went quiet, without saying more', function () {
    config(['queue.default' => 'database']);
    Heartbeats::beat(Heartbeats::SCHEDULER);

    $response = $this->getJson('/health')->assertStatus(503)
        ->assertExactJson(['status' => 'fail', 'checks' => ['database' => 'ok', 'scheduler' => 'ok', 'queue' => 'stale']]);

    $this->travel(10)->minutes();
    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.scheduler', 'stale');

    expect($response->getContent())->not->toContain('Exception')->not->toContain('select');
});

it('reports a database failure as one word', function () {
    $this->artisan('ops:heartbeat');
    config([
        'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => ''],
        'database.default' => 'broken',
    ]);

    $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.database', 'fail')
        ->assertJsonMissingPath('message');
});

it('reads a heartbeat the Redis store returns as a numeric string (production cache)', function () {
    // RedisStore keeps integers unserialized, so Cache::get() answers "1730000000", not 1730000000.
    Cache::forever(Heartbeats::SCHEDULER, (string) now()->getTimestamp());
    Cache::forever(Heartbeats::QUEUE, (string) now()->getTimestamp());

    expect(Heartbeats::age(Heartbeats::SCHEDULER))->toBeLessThan(5)
        ->and(Heartbeats::age(Heartbeats::QUEUE))->toBeLessThan(5);
    $this->getJson('/health')->assertJsonPath('checks.scheduler', 'ok');
});
