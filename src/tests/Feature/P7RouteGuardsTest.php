<?php

/**
 * Every P7 route (stock, procurement, delivery, returns, cancellation) needs a verified login, and every non-GET one
 * — including the cancellation review, which reads all of the order's sources — is rate limited.
 */

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

it('authenticates every P7 route and throttles every P7 write', function () {
    $p7 = collect(Router::getRoutes()->getRoutes())->filter(function (Route $route) {
        $name = (string) $route->getName();

        return str_starts_with($name, 'stock.') || str_starts_with($name, 'suppliers.') || str_starts_with($name, 'purchases.')
            || str_starts_with($name, 'deliveries.') || str_starts_with($name, 'returns.') || str_starts_with($name, 'cancellations.')
            || str_starts_with($name, 'settlement.') || str_starts_with($name, 'orders.cancellation') || str_starts_with($name, 'orders.materials')
            || in_array($name, ['orders.ready.confirm', 'orders.ready.cancel'], true);
    });

    expect($p7->count())->toBeGreaterThan(50);
    foreach ($p7 as $route) {
        /** @var Route $route */
        $middleware = $route->gatherMiddleware();
        expect(in_array('auth', $middleware, true) && in_array('verified', $middleware, true))->toBeTrue("{$route->uri()} is not behind auth + verified");
        if (array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
            expect(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue("{$route->uri()} is not throttled");
        }
    }
});
