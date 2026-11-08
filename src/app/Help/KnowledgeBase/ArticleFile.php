<?php

namespace App\Help\KnowledgeBase;

use App\Help\HelpAudience;
use App\Help\HelpCategory;

/**
 * One article as its Markdown files describe it: the Arabic file (authoritative, carries all metadata) and an
 * optional English file with the same slug (title, summary and body only).
 */
final readonly class ArticleFile
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $slug,
        public HelpAudience $audience,
        public HelpCategory $category,
        public string $titleAr,
        public string $summaryAr,
        public string $bodyAr,
        public array $roles,
        public array $permissions,
        public ?string $capability,
        public ?string $pageKey,
        public int $order,
        public string $path,
        public ?string $titleEn = null,
        public ?string $summaryEn = null,
        public ?string $bodyEn = null,
    ) {}

    public function withEnglish(string $title, ?string $summary, string $body): self
    {
        return new self(
            $this->slug, $this->audience, $this->category, $this->titleAr, $this->summaryAr, $this->bodyAr,
            $this->roles, $this->permissions, $this->capability, $this->pageKey, $this->order, $this->path,
            $title, $summary, $body,
        );
    }

    /**
     * Columns of help_articles this file sets (published_at is left to the caller).
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'audience' => $this->audience->value,
            'category' => $this->category,
            'page_key' => $this->pageKey,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'capability' => $this->capability,
            'source_path' => $this->path,
            'title_ar' => $this->titleAr,
            'summary_ar' => $this->summaryAr,
            'body_ar' => $this->bodyAr,
            'title_en' => $this->titleEn,
            'summary_en' => $this->summaryEn,
            'body_en' => $this->bodyEn,
            'sort' => $this->order,
        ];
    }
}
