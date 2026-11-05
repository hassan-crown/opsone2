# Hosting marketing/site: headers, caching and what not to publish (suggestion only)

Nothing in this repository's infrastructure serves this folder yet. When a host is chosen, apply the following on that
host (Nginx, a CDN, or a static host's headers file). Serve the folder `marketing/site/` as the site root (the 404 page uses root paths).

## Compression

Serve text with Brotli (gzip as fallback): `text/html`, `text/css`, `text/javascript`, `application/json`, `image/svg+xml`,
`application/xml`, `application/manifest+json`, `text/plain`. Do not compress `woff2`, `webp`, `png`, `jpg`, `webm`.

## Cache

| Path | Cache-Control |
| --- | --- |
| `/`, every `index.html`, `/404.html` | `no-cache` (revalidate every visit) |
| `/config.js` | `no-cache` (it holds the IDs and the form endpoint) |
| `/robots.txt`, `/sitemap.xml`, `/site.webmanifest` | `public, max-age=3600` |
| `/styles.css`, `/main.js`, `/site.js`, `/tags.js`, `/solutions/sector.*`, `/solutions/*/*.css|js` | `public, max-age=86400` until file names carry a version; then `max-age=31536000, immutable` |
| `/assets/**`, `/solutions/*/img/**`, `/solutions/*/og-*.jpg` | `public, max-age=31536000, immutable` (rename a file when it changes) |

## Security headers

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
X-Frame-Options: DENY
Cross-Origin-Opener-Policy: same-origin
```

### Content-Security-Policy

Every page has two inline scripts (on sector pages the first also sets `window.OPSONE_PAGE`, so each sector page has its own hash; list them all, or move that line into a small file) (the `js` class switch and the JSON-LD block, which browsers do not execute) and inline
`style` attributes. The tag loader adds scripts and pixels from the marketing hosts below only after consent. Replace
`https://api.example` with the demo-form endpoint's origin.

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'sha256-<hash of the inline js-class script>' https://www.googletagmanager.com https://connect.facebook.net https://analytics.tiktok.com https://sc-static.net https://snap.licdn.com;
  connect-src 'self' https://api.example https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com https://www.facebook.com https://*.tiktok.com https://*.tiktokw.us https://tr.snapchat.com https://*.snapchat.com https://px.ads.linkedin.com;
  img-src 'self' data: https://www.google-analytics.com https://www.googletagmanager.com https://www.facebook.com https://*.tiktok.com https://tr.snapchat.com https://px.ads.linkedin.com;
  style-src 'self' 'unsafe-inline';
  font-src 'self';
  media-src 'self';
  frame-src https://www.googletagmanager.com;
  form-action 'self' mailto:;
  base-uri 'self';
  frame-ancestors 'none';
  object-src 'none';
  upgrade-insecure-requests
```

Compute the hash of the one-line inline script (`document.documentElement.classList.add('js')`) once, for example
`printf "%s" "document.documentElement.classList.add('js')" | openssl dgst -sha256 -binary | openssl base64`, and keep the
script text byte-identical. Test the policy first as `Content-Security-Policy-Report-Only`; pixels add hosts over time.
Remove the hosts of any platform you do not use.

## Not found

Answer unknown paths with `/404.html` and status 404 (Nginx: `error_page 404 /404.html;`).

## Do not publish the sources

The folder also holds the build sources and research notes. Deny them (404) on the host, or publish a copy without them:

- `*.md`, `*.mjs`, `/site/` (sectors.json and the partials)
- `/solutions/*/data.json`, `/solutions/*/credits.json`, `/solutions/*/_og.html`
- `/assets/photos/credits.json`

Nginx example:

```
location ~ (\.md|\.mjs|/data\.json|/credits\.json|/_og\.html)$ { return 404; }
location ^~ /site/ { return 404; }
```

## Contact links

The WhatsApp button opens `https://wa.me/…` in a new tab and the call link is `tel:`; neither needs a CSP entry.
