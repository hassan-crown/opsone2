<?php

namespace App\Licensing\Sync;

use App\Access\TransitionRecorder;
use App\Licensing\CurrentLicense;
use App\Licensing\LicenseInstaller;
use App\Licensing\LicenseVerificationException;
use App\Licensing\LicenseVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

/**
 * Pulls this deployment's latest signed license from the central app (ADR-0047 §2–§6).
 *
 * One signed `POST {LICENSE_SYNC_URL}/v1/sync` carrying the heartbeat; the answer is `{license_id, envelope|null}`.
 * Trust comes from the license signature, never the channel: the envelope goes through LicenseVerifier and
 * LicenseInstaller with every ADR-0004 check (signature, kid, domains, deployment_id, superseded). Any failure —
 * network, timeout, 5xx, 401, malformed answer, rejected envelope — leaves the license state exactly as it was and
 * only records `last_sync_at` / `last_sync_error`. No retry inside the run: the next attempt is the next schedule.
 */
final readonly class LicenseSync
{
    public const string PATH = '/v1/sync';

    public const string ERROR_NOT_CONFIGURED = 'not_configured';

    public const string ERROR_INSECURE_URL = 'insecure_url';

    public const string ERROR_PAYLOAD_TOO_LARGE = 'payload_too_large';

    public const string ERROR_NETWORK = 'network';

    public const string ERROR_INVALID_RESPONSE = 'invalid_response';

    public const string ERROR_INSTALL_FAILED = 'install_failed';

    public function __construct(
        private Http $http,
        private HeartbeatCollector $heartbeat,
        private LicenseInstaller $installer,
        private CurrentLicense $license,
        private Cache $cache,
        private ?string $url,
        private ?SyncKey $key,
        private ?string $deploymentId,
        private bool $allowInsecure,
        private int $connectTimeoutSeconds = 5,
        private int $timeoutSeconds = 15,
        private string $statusKey = 'license.sync',
    ) {}

    public function configured(): bool
    {
        return $this->endpoint() !== null && $this->key !== null && (string) $this->deploymentId !== '';
    }

    public function status(): SyncStatus
    {
        return SyncStatus::load($this->cache, $this->statusKey);
    }

    public function run(): SyncResult
    {
        $previous = $this->status();
        $startedAt = CarbonImmutable::now('UTC');
        $skew = $previous->clockSkewSeconds;

        $result = $this->attempt($previous, $skew);

        (new SyncStatus(
            lastSyncAt: $startedAt,
            lastSyncError: $result->error,
            lastSuccessAt: $result->ok ? $startedAt : $previous->lastSuccessAt,
            clockSkewSeconds: $skew,
        ))->save($this->cache, $this->statusKey);

        return $result;
    }

    /**
     * @param-out ?int $skew
     */
    private function attempt(SyncStatus $previous, ?int &$skew): SyncResult
    {
        $endpoint = $this->endpoint();
        $deploymentId = (string) $this->deploymentId;

        if ($endpoint === null || $this->key === null || $deploymentId === '') {
            return SyncResult::failed(self::ERROR_NOT_CONFIGURED);
        }

        if (! $this->allowInsecure && ! str_starts_with(strtolower($endpoint), 'https://')) {
            return SyncResult::failed(self::ERROR_INSECURE_URL);
        }

        $body = json_encode(
            $this->heartbeat->collect($deploymentId, $previous->clockSkewSeconds),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        if ($body === false || strlen($body) > HeartbeatCollector::MAX_BYTES) {
            return SyncResult::failed(self::ERROR_PAYLOAD_TOO_LARGE);
        }

        $path = (string) (parse_url($endpoint, PHP_URL_PATH) ?: self::PATH);
        $headers = SyncRequestSigner::headers(
            'POST', $path, $deploymentId, CarbonImmutable::now('UTC')->getTimestamp(), SyncRequestSigner::nonce(), $body, $this->key,
        );

        try {
            $response = $this->http
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($endpoint);
        } catch (Throwable) {
            return SyncResult::failed(self::ERROR_NETWORK);
        }

        $skew = self::clockSkew($response) ?? $skew;

        if ($response->status() !== 200) {
            return SyncResult::failed('http_'.$response->status());
        }

        try {
            $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return SyncResult::failed(self::ERROR_INVALID_RESPONSE);
        }

        $envelope = is_array($data) ? ($data['envelope'] ?? null) : false;
        $licenseId = is_array($data) && is_string($data['license_id'] ?? null) ? $data['license_id'] : null;

        if (! is_array($data) || ! array_key_exists('envelope', $data) || ($envelope !== null && ! is_string($envelope))
            || (is_string($envelope) && strlen($envelope) > LicenseVerifier::MAX_BYTES)) {
            return SyncResult::failed(self::ERROR_INVALID_RESPONSE);
        }

        // Nothing newer than what is installed: write nothing.
        if ($envelope === null || $envelope === $this->installedContents()) {
            return SyncResult::upToDate($licenseId);
        }

        try {
            $snapshot = $this->installer->install($envelope, source: TransitionRecorder::SOURCE_SYSTEM);
        } catch (LicenseVerificationException $e) {
            return SyncResult::failed('rejected_'.$e->problem->value);
        } catch (Throwable) {
            return SyncResult::failed(self::ERROR_INSTALL_FAILED);
        }

        return SyncResult::installed($snapshot->payload->licenseId ?? (string) $licenseId);
    }

    /**
     * The full sync URL, or null when unset or malformed.
     */
    private function endpoint(): ?string
    {
        $base = rtrim(trim((string) $this->url), '/');

        if ($base === '' || parse_url($base, PHP_URL_HOST) === null || parse_url($base, PHP_URL_QUERY) !== null) {
            return null;
        }

        return $base.self::PATH;
    }

    private function installedContents(): ?string
    {
        $path = $this->license->path();
        if (! is_file($path) || ! is_readable($path) || filesize($path) > LicenseVerifier::MAX_BYTES) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Facility clock minus the response `Date` header, in seconds; null when absent or unparsable.
     */
    private static function clockSkew(Response $response): ?int
    {
        $date = $response->header('Date');
        if ($date === '') {
            return null;
        }

        try {
            $server = CarbonImmutable::parse($date);
        } catch (Throwable) {
            return null;
        }

        return CarbonImmutable::now('UTC')->getTimestamp() - $server->getTimestamp();
    }
}
