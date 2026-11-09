<?php

use App\Licensing\CurrentLicense;
use App\Licensing\LicenseEnvelope;
use App\Licensing\Sync\HeartbeatCollector;
use App\Licensing\Sync\LicenseSync;
use App\Licensing\Sync\SyncKey;
use App\Licensing\Sync\SyncRequestSigner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\LicenseFactory;

uses(RefreshDatabase::class);

/**
 * @return array<mixed>
 */
function syncContract(string $file): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/sync-contract/v1/'.$file)), true, 64, JSON_THROW_ON_ERROR);
}

/**
 * Everything a failed sync must leave untouched.
 *
 * @return array<string, mixed>
 */
function licenseStateFingerprint(): array
{
    return [
        'file' => @file_get_contents((string) config('license.path')),
        'state' => (array) DB::table('license_states')->first(),
        'audits' => DB::table('audit_events')->count(),
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08T09:00:00Z'));
    $this->keys = syncContract('keys.json');
    $this->deployment = $this->keys['deployment_id'];

    config([
        'license.deployment_id' => $this->deployment,
        'license.sync.url' => 'https://sync.opsone.test',
        'license.sync.secret_key' => $this->keys['secret_key_base64'],
    ]);

    LicenseFactory::install(['deployment_id' => $this->deployment, 'license_id' => 'lic-current']);
    Sleep::fake();
});

it('signs exactly like the central contract vectors', function () {
    $key = SyncKey::fromBase64($this->keys['secret_key_base64']);
    expect($key?->publicBase64())->toBe($this->keys['public_key_base64']);

    foreach (syncContract('vectors.json') as $vector) {
        $headers = $vector['headers'];
        $body = base64_decode($vector['body_base64'], true);
        $message = SyncRequestSigner::message(
            $vector['method'], $vector['path'], $headers['X-OpsOne-Deployment'],
            (int) $headers['X-OpsOne-Timestamp'], $headers['X-OpsOne-Nonce'], (string) $body,
        );

        expect(hash('sha256', (string) $body))->toBe($vector['body_sha256'], $vector['name'])
            ->and($message)->toBe($vector['message'], $vector['name']);

        $signature = LicenseEnvelope::decode($headers['X-OpsOne-Signature']);
        $valid = $signature !== null && sodium_crypto_sign_verify_detached($signature, $message, $key->publicKey);
        expect($valid)->toBe($vector['signature_valid'], $vector['name']);

        if ($vector['signature_valid']) {
            expect(SyncRequestSigner::sign($message, $key))->toBe($headers['X-OpsOne-Signature'], $vector['name']);
        }
    }
});

it('sends a signed heartbeat with bounded timeouts and no retry', function () {
    $options = null;
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(syncContract('responses.json')['200_up_to_date']);
    });

    $this->artisan('license:sync')->expectsOutputToContain('up to date')->assertSuccessful();

    expect($options)->toMatchArray(['connect_timeout' => 5, 'timeout' => 15]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $key = SyncKey::fromBase64($this->keys['secret_key_base64']);
        $message = SyncRequestSigner::message(
            $request->method(), (string) parse_url($request->url(), PHP_URL_PATH), $request->header('X-OpsOne-Deployment')[0],
            (int) $request->header('X-OpsOne-Timestamp')[0], $request->header('X-OpsOne-Nonce')[0], $request->body(),
        );
        $signature = LicenseEnvelope::decode($request->header('X-OpsOne-Signature')[0]);

        return $request->url() === 'https://sync.opsone.test/v1/sync'
            && $request->method() === 'POST'
            && $request->header('X-OpsOne-Deployment')[0] === $this->deployment
            && (int) $request->header('X-OpsOne-Timestamp')[0] === now()->getTimestamp()
            && preg_match('/^[A-Za-z0-9_-]{22}$/', $request->header('X-OpsOne-Nonce')[0]) === 1
            && $signature !== null
            && sodium_crypto_sign_verify_detached($signature, $message, $key->publicKey)
            && strlen($request->body()) <= HeartbeatCollector::MAX_BYTES
            && $request->data()['license']['license_id'] === 'lic-current';
    });
});

it('writes nothing when the central app answers envelope = null', function () {
    $before = licenseStateFingerprint();
    Http::fake(['*' => Http::response(syncContract('responses.json')['200_up_to_date'])]);

    $result = app(LicenseSync::class)->run();

    expect($result->ok)->toBeTrue()
        ->and($result->installed)->toBeFalse()
        ->and(licenseStateFingerprint())->toBe($before)
        ->and(app(LicenseSync::class)->status()->lastSyncError)->toBeNull()
        ->and(app(LicenseSync::class)->status()->lastSyncAt?->toIso8601ZuluString())->toBe('2026-11-08T09:00:00Z');
});

it('installs a newer license for this deployment through the installer', function () {
    $envelope = LicenseFactory::signed([
        'deployment_id' => $this->deployment,
        'license_id' => 'lic-renewed',
        'issued_at' => now()->subDay()->toIso8601ZuluString(),
        'ends_at' => now()->addDays(700)->toIso8601ZuluString(),
    ]);
    Http::fake(['*' => Http::response(['license_id' => 'lic-renewed', 'envelope' => $envelope])]);
    $audits = DB::table('audit_events')->count();

    $this->artisan('license:sync')->expectsOutputToContain('Installed license lic-renewed')->assertSuccessful();

    expect(file_get_contents((string) config('license.path')))->toBe($envelope)
        ->and(app(CurrentLicense::class)->refresh()->payload?->licenseId)->toBe('lic-renewed')
        ->and(DB::table('audit_events')->count())->toBe($audits + 1);
});

it('rejects an older envelope or one for another deployment and keeps the state', function (array $overrides, string $error) {
    $before = licenseStateFingerprint();
    $envelope = LicenseFactory::signed([
        'deployment_id' => $this->deployment,
        'license_id' => 'lic-other',
        ...$overrides,
    ]);
    Http::fake(['*' => Http::response(['license_id' => 'lic-other', 'envelope' => $envelope])]);

    $this->artisan('license:sync')->expectsOutputToContain($error)->assertFailed();

    expect(licenseStateFingerprint())->toBe($before)
        ->and(app(LicenseSync::class)->status()->lastSyncError)->toBe($error);
})->with([
    'older (replayed answer)' => [['issued_at' => '2026-01-01T00:00:00Z'], 'rejected_superseded'],
    'another deployment' => [['deployment_id' => '01JD5YNC00000000000000XTHR'], 'rejected_deployment_mismatch'],
]);

it('keeps the state on a network failure, 5xx or 401', function (Closure $fake, string $error) {
    $before = licenseStateFingerprint();
    Http::fake(['*' => $fake]);

    $this->artisan('license:sync')->expectsOutputToContain($error)->assertFailed();

    // One attempt only: no retry inside the run (a thrown connection error is not recorded by the fake).
    if ($error !== 'network') {
        Http::assertSentCount(1);
    }
    $status = app(LicenseSync::class)->status();
    expect(licenseStateFingerprint())->toBe($before)
        ->and($status->lastSyncError)->toBe($error)
        ->and($status->lastSyncAt)->not->toBeNull()
        ->and($status->lastSuccessAt)->toBeNull();
})->with([
    'connection refused or timeout' => [fn () => fn () => throw new ConnectionException('timed out'), 'network'],
    '500' => [fn () => fn () => Http::response('oops', 500), 'http_500'],
    '503' => [fn () => fn () => Http::response('', 503), 'http_503'],
    '401 (revoked key)' => [fn () => fn () => Http::response(['error' => 'unauthorized'], 401), 'http_401'],
    'not json' => [fn () => fn () => Http::response('<html>', 200), 'invalid_response'],
    'no envelope key' => [fn () => fn () => Http::response(['license_id' => 'x'], 200), 'invalid_response'],
]);

const SYNC_CONTRACT_RESPONSES = [
    // name in responses.json => [HTTP status, expected error; null = up to date, false = rejected without install]
    '200_new_licence' => [200, false], // placeholder envelope: never installs
    '200_up_to_date' => [200, null],
    '200_nothing_issued' => [200, null],
    '401' => [401, 'http_401'],
    '413' => [413, 'http_413'],
    '422' => [422, 'http_422'],
    '429' => [429, 'http_429'],
];

it('covers every response shape in the central contract', function () {
    expect(array_keys(syncContract('responses.json')))->toEqual(array_keys(SYNC_CONTRACT_RESPONSES));
});

it('handles each contract response without touching the state unless it installs', function (string $name) {
    [$status, $error] = SYNC_CONTRACT_RESPONSES[$name];
    $before = licenseStateFingerprint();
    Http::fake(['*' => Http::response(syncContract('responses.json')[$name], $status)]);

    $result = app(LicenseSync::class)->run();

    expect(licenseStateFingerprint())->toBe($before)
        ->and($result->installed)->toBeFalse();
    if ($error === null) {
        expect($result->ok)->toBeTrue();
    } elseif ($error === false) {
        expect($result->ok)->toBeFalse();
    } else {
        expect($result->ok)->toBeFalse()->and($result->error)->toBe($error);
    }
})->with(array_map('strval', array_keys(SYNC_CONTRACT_RESPONSES)));

it('sends only the reviewed heartbeat keys', function () {
    Http::fake(['*' => Http::response(syncContract('responses.json')['200_up_to_date'])]);

    app(LicenseSync::class)->run();

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent) {
        $sent = json_decode($request->body(), true);

        return true;
    });

    $shape = fn (array $body) => collect($body)->map(fn ($value) => is_array($value) && ! array_is_list($value) ? array_keys($value) : null)->all();

    // The reviewed list, written out: a new field must be added here, in FIELDS and in the central contract.
    expect($shape($sent))->toBe([
        'schema_version' => null,
        'deployment_id' => null,
        'app' => ['code_version', 'last_migration'],
        'license' => ['license_id', 'status', 'problem'],
        'usage' => ['active_seats', 'active_branches', 'storage_mb', 'capabilities'],
        'health' => [
            'database_ok', 'redis_ok', 'failed_jobs', 'oldest_pending_job_seconds', 'scheduler_last_run_at',
            'backup_last_at', 'backup_verified', 'disk_free_percent', 'clock_skew_seconds',
        ],
    ])
        ->and($shape($sent))->toBe(HeartbeatCollector::FIELDS)
        ->and($shape($sent))->toBe($shape(syncContract('heartbeat.example.json')));

    $schema = syncContract('heartbeat.schema.json');
    // `ai` is optional and absent here (no AI call this month); its shape has its own tests below.
    expect(array_keys($sent))->toBe(array_keys(array_diff_key($schema['properties'], ['ai' => 1])));
    foreach (array_diff_key($schema['properties'], ['ai' => 1]) as $section => $definition) {
        if (($definition['type'] ?? null) === 'object') {
            expect(array_keys($sent[$section]))->toBe(array_keys($definition['properties']), $section);
        }
    }

    expect($sent['schema_version'])->toBe(1)
        ->and($sent['deployment_id'])->toBe($this->deployment)
        ->and($sent['license']['status'])->toBe('active')
        ->and($sent['usage']['capabilities'])->toBe(['ready_goods', 'branch_comparisons', 'marketing_reports'])
        ->and($sent['health']['database_ok'])->toBeTrue();
});

it('reports the clock skew from the last answer in the next heartbeat', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['license_id' => 'lic-current', 'envelope' => null], 200, ['Date' => now()->subSeconds(42)->toRfc7231String()])
        ->push(['license_id' => 'lic-current', 'envelope' => null], 200)]);

    app(LicenseSync::class)->run();
    app(LicenseSync::class)->run();

    $bodies = [];
    Http::assertSent(function (Request $request) use (&$bodies) {
        $bodies[] = json_decode($request->body(), true);

        return true;
    });

    expect($bodies[0]['health']['clock_skew_seconds'])->toBeNull()
        ->and($bodies[1]['health']['clock_skew_seconds'])->toBe(42);
});

it('does nothing until sync is configured', function () {
    config(['license.sync.secret_key' => null]);
    Http::fake();

    $this->artisan('license:sync')->expectsOutputToContain('not configured')->assertFailed();

    Http::assertNothingSent();
});

it('refuses plain http outside local and testing', function () {
    app()->instance('env', 'production');
    config(['license.sync.url' => 'http://sync.opsone.test']);
    Http::fake();

    $this->artisan('license:sync')->expectsOutputToContain('insecure_url')->assertFailed();

    Http::assertNothingSent();
});

it('waits a random delay of at most ten minutes when scheduled', function () {
    Http::fake(['*' => Http::response(syncContract('responses.json')['200_up_to_date'])]);

    $this->artisan('license:sync', ['--jitter' => true])->assertSuccessful();

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 0 && $duration->totalSeconds <= 600);
});

it('is scheduled hourly without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'license:sync --jitter'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});

it('shows the last sync in license:status', function () {
    Http::fake(['*' => Http::response('', 503)]);
    app(LicenseSync::class)->run();

    $this->artisan('license:status')
        ->expectsOutputToContain('2026-11-08T09:00:00Z')
        ->expectsOutputToContain('http_503')
        ->assertSuccessful();
});

it('generates a sync key into .env and prints only the public key', function () {
    $dir = storage_path('framework/testing/env-'.bin2hex(random_bytes(4)));
    @mkdir($dir, 0755, true);
    file_put_contents($dir.'/.env', "APP_NAME=OpsOne\nLICENSE_SYNC_SECRET_KEY=\n");
    app()->useEnvironmentPath($dir);
    config(['license.sync.secret_key' => null]);

    try {
        $this->artisan('license:sync-keygen')->expectsOutputToContain('Public key')->assertSuccessful();

        preg_match('/^LICENSE_SYNC_SECRET_KEY=(.+)$/m', (string) file_get_contents($dir.'/.env'), $m);
        $key = SyncKey::fromBase64($m[1] ?? null);

        expect($key)->not->toBeNull()
            ->and(substr_count((string) file_get_contents($dir.'/.env'), 'LICENSE_SYNC_SECRET_KEY='))->toBe(1);

        // A second run keeps the key and shows the same public key.
        $this->artisan('license:sync-keygen')
            ->expectsOutputToContain($key->publicBase64())
            ->doesntExpectOutputToContain($key->secretBase64())
            ->assertSuccessful();

        expect((string) file_get_contents($dir.'/.env'))->toContain($m[1]);
    } finally {
        @unlink($dir.'/.env');
        @rmdir($dir);
    }
});

/**
 * @return array<string, mixed>
 */
function sentHeartbeat(): array
{
    Http::fake(['*' => Http::response(syncContract('responses.json')['200_up_to_date'])]);

    app(LicenseSync::class)->run();

    $sent = null;
    Http::assertSent(function (Request $request) use (&$sent) {
        $sent = json_decode($request->body(), true);

        return true;
    });

    return $sent;
}

function aiCall(string $model, string $outcome, array $overrides = []): void
{
    DB::table('ai_usage')->insert(array_merge([
        'task' => 'help', 'model' => $model, 'driver' => 'fake', 'outcome' => $outcome,
        'input_tokens' => 100, 'output_tokens' => 20, 'cache_read_tokens' => 10, 'cache_write_tokens' => 5,
        'cost_micro_usd' => 1500, 'credits' => $outcome === 'ok' ? 2 : 0, 'duration_ms' => 300,
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

it('omits the ai block when the facility made no AI call this month', function () {
    aiCall('claude-haiku-5-5', 'ok', ['created_at' => now()->subMonth(), 'updated_at' => now()->subMonth()]);

    expect(sentHeartbeat())->not->toHaveKey('ai');
});

it('sends the current month AI totals in the shape of the central contract', function () {
    aiCall('claude-haiku-5-5', 'ok');
    aiCall('claude-haiku-5-5', 'ok');
    aiCall('claude-haiku-5-5', 'failed', ['credits' => 0]);
    aiCall('claude-sonnet-5-5', 'ok', ['input_tokens' => 7, 'output_tokens' => 3, 'cache_read_tokens' => 0, 'cost_micro_usd' => 40]);
    // Last month and the next month never count.
    aiCall('claude-haiku-5-5', 'ok', ['created_at' => '2026-10-31 20:00:00', 'updated_at' => '2026-10-31 20:00:00']);

    $sent = sentHeartbeat();
    $example = syncContract('heartbeat.ai.example.json');

    expect($sent['ai']['period'])->toBe('2026-11')
        ->and($sent['ai']['credits_used'])->toBe(6)
        ->and($sent['ai']['cost_usd_micro'])->toBe(4540)
        ->and($sent['ai']['suggestions_accepted'])->toBe(0)
        ->and($sent['ai']['models'])->toBe([
            ['model' => 'claude-haiku-5-5', 'input_tokens' => 300, 'output_tokens' => 60, 'cached_tokens' => 30],
            ['model' => 'claude-sonnet-5-5', 'input_tokens' => 7, 'output_tokens' => 3, 'cached_tokens' => 0],
        ])
        ->and(array_keys($sent['ai']))->toBe(HeartbeatCollector::AI_FIELDS)
        ->and(array_keys($sent['ai']))->toBe(array_keys($example['ai']))
        ->and(array_keys($sent['ai']['models'][0]))->toBe(HeartbeatCollector::AI_MODEL_FIELDS)
        ->and(array_keys($sent['ai']['models'][0]))->toBe(array_keys($example['ai']['models'][0]))
        ->and(array_keys($sent))->toBe(array_keys($example));

    // Every value fits the contract's closed schema.
    $schema = syncContract('heartbeat.schema.json')['properties']['ai'];
    expect(array_keys($sent['ai']))->toBe(array_keys($schema['properties']))
        ->and($sent['ai']['period'])->toMatch('/'.$schema['properties']['period']['pattern'].'/')
        ->and(count($sent['ai']['models']))->toBeLessThanOrEqual($schema['properties']['models']['maxItems']);
    foreach ($schema['properties']['models']['items']['properties'] as $key => $rule) {
        foreach ($sent['ai']['models'] as $model) {
            is_string($model[$key]) ? expect($model[$key])->toMatch('/'.$rule['pattern'].'/') : expect($model[$key])->toBeInt()->toBeLessThanOrEqual($rule['maximum']);
        }
    }
});

it('counts accepted and edited suggestions decided this month', function () {
    aiCall('claude-haiku-5-5', 'ok');
    foreach (['accepted' => now(), 'edited' => now(), 'rejected' => now(), 'accepted ' => now()->subMonth()] as $status => $at) {
        DB::table('ai_suggestions')->insert([
            'task' => 'help', 'status' => trim($status), 'payload' => 'x', 'decided_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    expect(sentHeartbeat()['ai']['suggestions_accepted'])->toBe(2);
});

it('never sends prompts, answers, users or subjects in the ai block', function () {
    aiCall('claude-haiku-5-5', 'ok', ['subject_type' => 'order', 'subject_id' => 99, 'driver' => 'secret-driver-name']);
    DB::table('ai_suggestions')->insert([
        'task' => 'help', 'status' => 'accepted', 'payload' => 'SECRET-PROMPT-TEXT', 'final_payload' => 'SECRET-ANSWER-TEXT',
        'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $body = json_encode(sentHeartbeat());

    expect($body)->not->toContain('SECRET')->not->toContain('secret-driver-name')->not->toContain('subject')
        ->and(strlen($body))->toBeLessThanOrEqual(HeartbeatCollector::MAX_BYTES);
});

it('still sends a heartbeat the old central accepts when ai is omitted', function () {
    $sent = sentHeartbeat();

    expect($sent)->not->toHaveKey('ai')
        ->and(array_keys($sent))->toBe(array_keys(syncContract('heartbeat.example.json')));
});
