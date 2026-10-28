<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Site\SitePage;
use App\Site\SiteContext;
use Illuminate\Http\Response;

/**
 * sitemap.xml (every published language of every live page, with hreflang alternates) and robots.txt.
 */
final class SeoController extends Controller
{
    public function __construct(private readonly SiteContext $site) {}

    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
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
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /site-admin/',
            'Disallow: /dashboard',
            'Disallow: /settings/',
            'Disallow: /api/',
            'Sitemap: '.$this->site->baseUrl().'/sitemap.xml',
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
