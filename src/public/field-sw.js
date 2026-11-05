/*
 * OpsOne field app service worker (ADR-0036), registered by the field layout with scope /field.
 *
 * - App shell: built assets (/build/*, hashed, immutable) and the field icons are cache-first.
 * - Field pages (/field, /field/visits/{id}; full page loads and Inertia JSON visits): network-first, falling back to
 *   the last copy so today's visits stay readable offline. Nothing is edited offline.
 * - Photo uploads queued by the page in IndexedDB (`opsone-field` / `uploads`) are retried on Background Sync where the
 *   browser supports it; the page also retries when it comes back online or opens.
 * - A field page answered by a redirect (signed out, revoked) wipes the cached pages: customer details never outlive
 *   the session on the device.
 * - The layout registers this file as /field-sw.js?v=<asset version>: every build is a new worker with its own cache
 *   names, so an old shell or page copy never survives a deploy.
 * - A page served from the cache tells its tab (`stale`), and a network answer clears it (`fresh`), so the technician
 *   knows the screen may be old.
 */
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const SHELL = `opsone-field-shell-${VERSION}`;
const PAGES = `opsone-field-pages-${VERSION}`;
const NETWORK_TIMEOUT_MS = 3000;
const INERTIA_SUFFIX = '#inertia';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keep = [SHELL, PAGES];
            for (const key of await caches.keys()) {
                if (key.startsWith('opsone-field-') && !keep.includes(key)) {
                    await caches.delete(key);
                }
            }
            await self.clients.claim();
        })(),
    );
});

const isFieldPage = (url) =>
    url.pathname === '/field' ||
    url.pathname === '/field/' ||
    url.pathname === '/field/install' ||
    /^\/field\/(visits|leads)\/\d+$/.test(url.pathname);

const isShell = (url) =>
    url.pathname.startsWith('/build/') ||
    url.pathname.startsWith('/field-icons/');

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (isShell(url)) {
        event.respondWith(cacheFirst(request));
    } else if (isFieldPage(url)) {
        event.respondWith(
            networkFirst(
                request,
                url,
                event.clientId || event.resultingClientId,
            ),
        );
    }
});

/** Tabs whose current page came from the cache; a full page load asks for its state once it is running. */
const staleClients = new Set();

async function tell(clientId, stale) {
    if (!clientId) {
        return;
    }
    if (stale) {
        staleClients.add(clientId);
    } else {
        staleClients.delete(clientId);
    }
    const client = await self.clients.get(clientId);
    client?.postMessage({ type: stale ? 'stale' : 'fresh' });
}

async function cacheFirst(request) {
    const cache = await caches.open(SHELL);
    const hit = await cache.match(request);
    if (hit) {
        return hit;
    }
    const response = await fetch(request);
    if (response.ok) {
        await cache.put(request, response.clone());
    }

    return response;
}

/** Inertia visits and full loads of the same URL are different documents: keep them apart. */
function pageKey(request, url) {
    const inertia = request.headers.get('X-Inertia') === 'true';

    return (
        url.origin + url.pathname + url.search + (inertia ? INERTIA_SUFFIX : '')
    );
}

/**
 * Network first. After NETWORK_TIMEOUT_MS a saved copy of the same page is shown (and marked stale) while the network
 * answer still refreshes the cache; with no saved copy the page keeps waiting for a slow network instead of claiming
 * to be offline. Only a failed request (really offline) falls back to the visit list or the offline notice.
 */
async function networkFirst(request, url, clientId) {
    const cache = await caches.open(PAGES);
    const key = pageKey(request, url);
    const network = fetch(request).then(async (response) => {
        if (
            response.redirected ||
            response.status === 401 ||
            response.status === 403 ||
            response.status === 419
        ) {
            await caches.delete(PAGES);
        } else if (response.ok) {
            await cache.put(key, response.clone());
        }

        return response;
    });
    try {
        const response = await withTimeout(network, NETWORK_TIMEOUT_MS);
        await tell(clientId, false);

        return response;
    } catch {
        const copy = await cache.match(key);
        if (copy) {
            network.catch(() => undefined);
            await tell(clientId, true);

            return copy;
        }
    }
    try {
        const response = await network;
        await tell(clientId, false);

        return response;
    } catch {
        const list =
            request.mode === 'navigate'
                ? await cache.match(url.origin + '/field')
                : undefined;
        if (list) {
            await tell(clientId, true);

            return list;
        }

        return new Response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
                '<title>OpsOne</title><body style="font-family:system-ui;padding:24px;text-align:center">' +
                '<p>لا يوجد اتصال، وهذه الصفحة غير محفوظة على الجهاز.</p><p>You are offline and this page is not saved on the device.</p>',
            {
                status: 503,
                headers: { 'Content-Type': 'text/html; charset=utf-8' },
            },
        );
    }
}

function withTimeout(promise, ms) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('timeout')), ms);
        promise.then(
            (value) => {
                clearTimeout(timer);
                resolve(value);
            },
            (error) => {
                clearTimeout(timer);
                reject(error);
            },
        );
    });
}

/*
 * The list page asks to keep today's visit screens for offline reading: {type: 'warm', urls: [...], version}.
 * Each is fetched as an Inertia visit (with the page's asset version, so the server does not answer 409).
 */
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type === 'warm' && Array.isArray(data.urls)) {
        event.waitUntil(warm(data.urls, data.version || ''));
    } else if (data.type === 'forget') {
        event.waitUntil(caches.delete(PAGES));
    } else if (data.type === 'flush') {
        event.waitUntil(flushUploads());
    } else if (data.type === 'status' && event.source) {
        event.source.postMessage({
            type: staleClients.has(event.source.id) ? 'stale' : 'fresh',
        });
    }
});

async function warm(urls, version) {
    const cache = await caches.open(PAGES);
    for (const path of urls.slice(0, 30)) {
        const url = new URL(path, self.location.origin);
        if (!isFieldPage(url)) {
            continue;
        }
        try {
            const response = await fetch(url.href, {
                credentials: 'same-origin',
                headers: {
                    'X-Inertia': 'true',
                    'X-Inertia-Version': version,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'text/html, application/xhtml+xml',
                },
            });
            if (response.ok && !response.redirected) {
                await cache.put(
                    url.origin + url.pathname + url.search + INERTIA_SUFFIX,
                    response,
                );
            }
        } catch (error) {
            return; // offline: try again on the next visit to the list
        }
    }
}

/* ---- Photo upload queue (same store as resources/js/lib/field-queue.ts) ---- */

self.addEventListener('sync', (event) => {
    if (event.tag === 'field-uploads') {
        event.waitUntil(flushUploads());
    }
});

function openQueue() {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open('opsone-field', 1);
        open.onupgradeneeded = () =>
            open.result.createObjectStore('uploads', { keyPath: 'id' });
        open.onsuccess = () => resolve(open.result);
        open.onerror = () => reject(open.error);
    });
}

function tx(db, mode, run) {
    return new Promise((resolve, reject) => {
        const t = db.transaction('uploads', mode);
        const result = run(t.objectStore('uploads'));
        t.oncomplete = () =>
            resolve(result && 'result' in result ? result.result : undefined);
        t.onerror = () => reject(t.error);
    });
}

async function flushUploads() {
    const db = await openQueue();
    const items = (await tx(db, 'readonly', (store) => store.getAll())) || [];
    let sent = 0;
    for (const item of items) {
        if (item.failed) {
            continue; // refused by the server: the page shows it until the technician discards it
        }
        const form = new FormData();
        form.append('phase', item.phase);
        form.append('client_uuid', item.id);
        form.append('file', item.blob, item.name);
        let response;
        try {
            response = await fetch(item.url, {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': item.xsrf || '',
                },
            });
        } catch (error) {
            break; // still offline
        }
        // 419 (session token changed) or a server error: leave it for the next try (the page uses its current token).
        if (response.ok) {
            await tx(db, 'readwrite', (store) => store.delete(item.id));
            sent++;
        } else if ([403, 404, 409, 422].includes(response.status)) {
            await tx(db, 'readwrite', (store) =>
                store.put({ ...item, failed: response.status }),
            );
        }
    }
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach((client) =>
        client.postMessage({ type: 'uploads-flushed', sent }),
    );
}
