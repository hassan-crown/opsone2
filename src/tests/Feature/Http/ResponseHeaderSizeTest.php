<?php

/**
 * Regression: with a production build the order pages answered nginx 502 «upstream sent too big header» — the preload
 * `Link` header listed every chunk and, with the cookies, overflowed the FastCGI header buffer (4 KiB on a 4 KiB-page
 * host). The header is now bounded in bytes, so the whole header block stays well under that buffer.
 */

use App\Http\Middleware\AddPreloadLinkHeaders;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Illuminate\Support\HtmlString;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

/** A build with many chunks, as the order pages have (each with a long hashed URL). */
function manyChunkVite(int $chunks): void
{
    ViteFacade::clearResolvedInstance();
    app()->instance(Vite::class, new class($chunks) extends Vite
    {
        public function __construct(private readonly int $chunks) {}

        public function __invoke($entrypoints, $buildDirectory = null)
        {
            return new HtmlString('');
        }

        public function preloadedAssets()
        {
            $assets = [];
            for ($i = 0; $i < $this->chunks; $i++) {
                $assets["https://tenant.example.sa/build/assets/order-section-{$i}-".str_repeat('a', 8).'.js'] = ['rel="modulepreload"', 'crossorigin'];
            }

            return $assets;
        }
    });
}

/** Bytes of the response's header block as PHP-FPM hands it to nginx (`Name: value\r\n` per line). */
function headerBytes(TestResponse $response): int
{
    $bytes = 0;
    foreach ($response->baseResponse->headers->allPreserveCaseWithoutCookies() as $name => $values) {
        foreach ($values as $value) {
            $bytes += strlen($name) + 2 + strlen((string) $value) + 2;
        }
    }
    foreach ($response->baseResponse->headers->getCookies() as $cookie) {
        $bytes += strlen('Set-Cookie: ') + strlen((string) $cookie) + 2;
    }

    return $bytes;
}

it('keeps the order pages\' headers under the FastCGI buffer however many chunks the build preloads', function (string $route) {
    $f = financeFixture();
    manyChunkVite(150);

    $response = $this->actingAs($f->owner)->get(route($route, $f->order));

    $response->assertOk();
    $link = (string) $response->headers->get('Link');
    expect($link)->toContain('rel="modulepreload"')
        ->and(strlen($link))->toBeLessThanOrEqual(AddPreloadLinkHeaders::MAX_BYTES)
        ->and(headerBytes($response))->toBeLessThan(3_072);
})->with(['orders.show', 'orders.agreement', 'orders.finance']);

it('keeps whole entries only, in the build\'s order', function () {
    manyChunkVite(150);
    $f = financeFixture();

    $link = (string) $this->actingAs($f->owner)->get(route('orders.show', $f->order))->headers->get('Link');

    $entries = explode(', ', $link);
    expect($entries[0])->toBe('<https://tenant.example.sa/build/assets/order-section-0-aaaaaaaa.js>; rel="modulepreload"; crossorigin')
        ->and(end($entries))->toEndWith('crossorigin')
        ->and(count($entries))->toBeLessThan(150);
});
