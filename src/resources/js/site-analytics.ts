/**
 * Consent banner and tag loader of the facility's public site and store (ADR-0045).
 *
 * - Consent Mode v2: every Google signal starts "denied"; the visitor's choice (necessary / analytics / marketing) is
 *   kept 12 months in the first-party cookie `opsone_consent` and can be changed from the footer.
 * - Google tags (GTM, GA4, Ads) load at once and obey Consent Mode; Meta, TikTok and Snap load only after marketing
 *   consent (and then receive this page's events too).
 * - Events come from the server in the GA4 e-commerce shape, without personal data, each with an id the platforms
 *   use to drop duplicates. Clicks on `[data-track]` links add `click_whatsapp` / `click_call`.
 * - Only IDs validated by the server are used; nothing here evaluates text from the page.
 */

type Tags = {
    gtm: string | null;
    ga4: string | null;
    meta: string | null;
    tiktok: string | null;
    snap: string | null;
    ads: string | null;
    ads_purchase: string | null;
    ads_lead: string | null;
};

type TrackEvent = {
    name: string;
    id: string;
    params: Record<string, unknown> & { value?: number; currency?: string };
};

type Config = { tags: Tags; events: TrackEvent[]; secure: boolean };

type Consent = { analytics: boolean; marketing: boolean };

type Queue = ((...args: unknown[]) => void) & {
    queue?: unknown[];
    callMethod?: (...args: unknown[]) => void;
    push?: unknown;
    loaded?: boolean;
    version?: string;
};

type TikTok = {
    load: (id: string) => void;
    page: () => void;
    track: (event: string, params?: object, options?: object) => void;
};

declare global {
    interface Window {
        dataLayer: unknown[];
        gtag: (...args: unknown[]) => void;
        fbq?: Queue;
        _fbq?: Queue;
        snaptr?: Queue;
        ttq?: TikTok & unknown[];
        TiktokAnalyticsObject?: string;
    }
}

const COOKIE = 'opsone_consent';

/** GA4 name → [Meta, TikTok, Snap] standard event (null = not sent there). */
const MAP: Record<string, [string | null, string | null, string | null]> = {
    view_item: ['ViewContent', 'ViewContent', 'VIEW_CONTENT'],
    view_item_list: [null, null, null],
    add_to_cart: ['AddToCart', 'AddToCart', 'ADD_CART'],
    begin_checkout: ['InitiateCheckout', 'InitiateCheckout', 'START_CHECKOUT'],
    purchase: ['Purchase', 'CompletePayment', 'PURCHASE'],
    generate_lead: ['Lead', 'SubmitForm', 'SIGN_UP'],
    appointment_request: ['Schedule', 'SubmitForm', 'RESERVE'],
    click_whatsapp: ['Contact', 'Contact', null],
    click_call: ['Contact', 'Contact', null],
};

const element = document.getElementById('opsone-tracking');
const config: Config | null = element
    ? (JSON.parse(element.textContent ?? 'null') as Config)
    : null;

function readConsent(): Consent | null {
    const match = document.cookie.match(
        /(?:^|; )opsone_consent=v1\.a([01])\.m([01])/,
    );

    return match
        ? { analytics: match[1] === '1', marketing: match[2] === '1' }
        : null;
}

function storeConsent(consent: Consent, secure: boolean): void {
    const value = `v1.a${consent.analytics ? 1 : 0}.m${consent.marketing ? 1 : 0}`;
    document.cookie = `${COOKIE}=${value};path=/;max-age=31536000;samesite=lax${secure ? ';secure' : ''}`;
}

function script(src: string): void {
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    document.head.appendChild(s);
}

function googleConsent(consent: Consent): Record<string, string> {
    const ads = consent.marketing ? 'granted' : 'denied';

    return {
        analytics_storage: consent.analytics ? 'granted' : 'denied',
        ad_storage: ads,
        ad_user_data: ads,
        ad_personalization: ads,
    };
}

function loadGoogle(tags: Tags): void {
    if (tags.gtm) {
        window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
        script(
            `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(tags.gtm)}`,
        );
    }
    const direct = tags.ga4 ?? tags.ads;
    if (direct) {
        script(
            `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(direct)}`,
        );
        window.gtag('js', new Date());
        if (tags.ga4) {
            window.gtag('config', tags.ga4);
        }
        if (tags.ads) {
            window.gtag('config', tags.ads);
        }
    }
}

function loadMeta(id: string): void {
    if (window.fbq) {
        return;
    }
    const fbq: Queue = (...args: unknown[]) => {
        if (fbq.callMethod) {
            fbq.callMethod(...args);
        } else {
            fbq.queue?.push(args);
        }
    };
    fbq.push = fbq;
    fbq.loaded = true;
    fbq.version = '2.0';
    fbq.queue = [];
    window.fbq = fbq;
    window._fbq = fbq;
    script('https://connect.facebook.net/en_US/fbevents.js');
    // No automatic configuration: the pixel must not read form fields (advanced matching) on its own.
    fbq('set', 'autoConfig', false, id);
    fbq('init', id);
    fbq('track', 'PageView');
}

function loadTikTok(id: string): void {
    if (window.ttq) {
        return;
    }
    window.TiktokAnalyticsObject = 'ttq';
    const queue: unknown[] = [];
    const stub =
        (method: string) =>
        (...args: unknown[]) => {
            queue.push([method, ...args]);
        };
    const ttq = Object.assign(queue, {
        load: stub('load'),
        page: stub('page'),
        track: stub('track'),
    }) as TikTok & unknown[];
    window.ttq = ttq;
    script(
        `https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=${encodeURIComponent(id)}&lib=ttq`,
    );
    ttq.load(id);
    ttq.page();
}

function loadSnap(id: string): void {
    if (window.snaptr) {
        return;
    }
    const snaptr: Queue = (...args: unknown[]) => {
        if (snaptr.callMethod) {
            snaptr.callMethod(...args);
        } else {
            snaptr.queue?.push(args);
        }
    };
    snaptr.queue = [];
    window.snaptr = snaptr;
    script('https://sc-static.net/scevent.min.js');
    snaptr('init', id, {});
    snaptr('track', 'PAGE_VIEW');
}

function sendGoogle(tags: Tags, event: TrackEvent): void {
    const params = { ...event.params, event_id: event.id };
    if (tags.gtm) {
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push({
            event: event.name,
            event_id: event.id,
            ecommerce: event.params,
        });
    }
    if (tags.ga4) {
        window.gtag('event', event.name, params);
    }
    const label =
        event.name === 'purchase'
            ? tags.ads_purchase
            : event.name === 'generate_lead' ||
                event.name === 'appointment_request'
              ? tags.ads_lead
              : null;
    if (tags.ads && label) {
        window.gtag('event', 'conversion', {
            send_to: `${tags.ads}/${label}`,
            value: event.params.value,
            currency: event.params.currency,
            transaction_id: event.name === 'purchase' ? event.id : undefined,
        });
    }
}

function sendAds(tags: Tags, event: TrackEvent): void {
    const [meta, tiktok, snap] = MAP[event.name] ?? [null, null, null];
    const money =
        event.params.value !== undefined
            ? { value: event.params.value, currency: event.params.currency }
            : {};
    const items = Array.isArray(event.params.items)
        ? (event.params.items as { item_id: string; quantity: number }[])
        : [];
    if (tags.meta && meta && window.fbq) {
        window.fbq(
            'track',
            meta,
            {
                ...money,
                content_ids: items.map((i) => i.item_id),
                content_type: 'product',
            },
            { eventID: event.id },
        );
    }
    if (tags.tiktok && tiktok && window.ttq) {
        window.ttq.track(
            tiktok,
            {
                ...money,
                contents: items.map((i) => ({
                    content_id: i.item_id,
                    quantity: i.quantity,
                })),
            },
            { event_id: event.id },
        );
    }
    if (tags.snap && snap && window.snaptr) {
        window.snaptr('track', snap, {
            price: event.params.value,
            currency: event.params.currency,
            item_ids: items.map((i) => i.item_id),
            transaction_id: event.name === 'purchase' ? event.id : undefined,
            client_dedup_id: event.id,
        });
    }
}

function start(cfg: Config): void {
    const { tags } = cfg;
    const sent: TrackEvent[] = [];
    let adsLoaded = false;

    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() {
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer.push(arguments);
    };
    window.gtag('consent', 'default', {
        ...googleConsent({ analytics: false, marketing: false }),
        wait_for_update: 500,
    });

    const apply = (consent: Consent) => {
        window.gtag('consent', 'update', googleConsent(consent));
        if (consent.marketing && !adsLoaded) {
            adsLoaded = true;
            if (tags.meta) {
                loadMeta(tags.meta);
            }
            if (tags.tiktok) {
                loadTikTok(tags.tiktok);
            }
            if (tags.snap) {
                loadSnap(tags.snap);
            }
            sent.forEach((event) => sendAds(tags, event));
        }
    };

    const track = (event: TrackEvent) => {
        sent.push(event);
        sendGoogle(tags, event);
        if (adsLoaded) {
            sendAds(tags, event);
        }
    };

    const stored = readConsent();
    if (stored) {
        apply(stored);
    }
    loadGoogle(tags);
    cfg.events.forEach(track);

    document.addEventListener('click', (e) => {
        const link = (e.target as Element | null)?.closest('[data-track]');
        const name = link?.getAttribute('data-track');
        if (name === 'click_whatsapp' || name === 'click_call') {
            track({ name, id: crypto.randomUUID(), params: {} });
        }
    });

    const banner = document.getElementById('consent-banner');
    if (!banner) {
        return;
    }
    const choices = document.getElementById('consent-choices');
    const box = (name: string) =>
        banner.querySelector<HTMLInputElement>(`input[name="${name}"]`);
    const save = banner.querySelector<HTMLElement>('[data-consent="save"]');
    const decide = (consent: Consent) => {
        storeConsent(consent, cfg.secure);
        apply(consent);
        banner.hidden = true;
    };
    banner.addEventListener('click', (e) => {
        const action = (e.target as Element | null)
            ?.closest('[data-consent]')
            ?.getAttribute('data-consent');
        if (action === 'all') {
            decide({ analytics: true, marketing: true });
        } else if (action === 'necessary') {
            decide({ analytics: false, marketing: false });
        } else if (action === 'customize' && choices && save) {
            choices.hidden = false;
            save.hidden = false;
        } else if (action === 'save') {
            decide({
                analytics: box('analytics')?.checked ?? false,
                marketing: box('marketing')?.checked ?? false,
            });
        }
    });
    document.querySelectorAll('[data-consent-open]').forEach((button) =>
        button.addEventListener('click', () => {
            const current = readConsent();
            const analytics = box('analytics');
            const marketing = box('marketing');
            if (analytics) {
                analytics.checked = current?.analytics ?? false;
            }
            if (marketing) {
                marketing.checked = current?.marketing ?? false;
            }
            banner.hidden = false;
        }),
    );
    if (!stored) {
        banner.hidden = false;
    }
}

if (config) {
    start(config);
}

export {};
