<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Site\SitePage;
use App\Site\Seo\SiteIndexing;
use App\Site\SiteContext;
use App\Store\StoreCatalogue;
use App\Store\StoreGate;
use Illuminate\Http\Response;

/**
 * sitemap.xml (every published language of every live page, the store and its products on sale with their images,
 * hreflang alternates) and robots.txt.
 */
final class SeoController extends Controller
{
    public function __construct(private readonly SiteContext $site, private readonly StoreGate $store, private readonly StoreCatalogue $catalogue) {}

    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        $pages = SitePage::query()->with('locales')->whereNull('archived_at')->where('policy', '!=', SitePage::POLICY_SYSTEM)->orderBy('sort')->get();
        foreach ($pages as $page) {
            $locales = $this->site->publishedLocales($page);
            foreach ($locales as $locale) {
                $xml .= '  <url><loc>'.e($this->site->url($locale, $page)).'</loc>';
                foreach ($locales as $alt) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="'.$alt.'" href="'.e($this->site->url($alt, $page)).'"/>';
                }
                $xml .= '<xhtml:link rel="alternate" hreflang="x-default" href="'.e($this->site->url(in_array($this->site->defaultLocale(), $locales, true) ? $this->site->defaultLocale() : $locales[0], $page)).'"/>';
                $xml .= "</url>\n";
            }
        }
        // The store and every product on sale, with their pictures (ADR-0045), while the store is selling.
        if ($this->store->open()) {
            $base = $this->site->baseUrl();
            $xml .= $this->entry(['ar' => $base.'/ar/store', 'en' => $base.'/en/store'], []);
            foreach ($this->catalogue->products($this->site->defaultLocale()) as $product) {
                $urls = ['ar' => $base.StoreCatalogue::path('ar', $product['id'], $product['slugs']['ar']), 'en' => $base.StoreCatalogue::path('en', $product['id'], $product['slugs']['en'])];
                $images = array_values(array_map(fn (string $uuid) => route('site.media', ['attachment' => $uuid]), array_slice($product['images'], 0, 10)));
                $xml .= $this->entry($urls, $images);
            }
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * One `<url>` per language with the alternates of all languages and x-default.
     *
     * @param  array<string, string>  $urls  locale => absolute URL
     * @param  list<string>  $images
     */
    private function entry(array $urls, array $images): string
    {
        $out = '';
        $default = $urls[$this->site->defaultLocale()] ?? (string) reset($urls);
        foreach ($urls as $url) {
            $out .= '  <url><loc>'.e($url).'</loc>';
            foreach ($urls as $alt => $href) {
                $out .= '<xhtml:link rel="alternate" hreflang="'.$alt.'" href="'.e($href).'"/>';
            }
            $out .= '<xhtml:link rel="alternate" hreflang="x-default" href="'.e($default).'"/>';
            foreach ($images as $image) {
                $out .= '<image:image><image:loc>'.e($image).'</image:loc></image:image>';
            }
            $out .= "</url>\n";
        }

        return $out;
    }

    public function robots(): Response
    {
        // Outside production (or with indexing switched off) the whole host is closed to crawlers.
        if (! SiteIndexing::enabled()) {
            return response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $lines = ['User-agent: *'];
        foreach (SiteIndexing::DISALLOW as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = 'Sitemap: '.$this->site->baseUrl().'/sitemap.xml';

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
