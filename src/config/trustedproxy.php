<?php

/*
 * Proxies whose X-Forwarded-* headers are believed (TrustProxies). In every environment the app is reached only
 * through nginx on the private Compose network, behind Traefik on dev/stage/prod, so the private ranges are the
 * default; without this, every visitor shares the proxy's address and the per-IP limits become global ones.
 * Set TRUSTED_PROXIES to narrow it to the Traefik network.
 */
return [
    'proxies' => env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1'),
];
