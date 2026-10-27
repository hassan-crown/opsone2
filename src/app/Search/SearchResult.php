<?php

namespace App\Search;

/**
 * One row of the global search. Built only after the provider has checked the user's access to the record.
 */
final readonly class SearchResult
{
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public ?string $subtitle = null,
    ) {}

    /**
     * @return array{type: string, title: string, url: string, subtitle: string|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'title' => $this->title, 'url' => $this->url, 'subtitle' => $this->subtitle];
    }
}
