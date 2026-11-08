<?php

namespace App\Help\Pdf;

use App\Help\HelpAudience;
use App\Help\HelpCategory;
use App\Help\KnowledgeBase\ArticleFile;

/**
 * The articles of one role's PDF, in print order: the role guide first, then the rest by category and `order`.
 */
final readonly class RoleBook
{
    /**
     * @param  list<ArticleFile>  $guide  role_guide articles naming this role
     * @param  array<string, list<ArticleFile>>  $sections  category value => articles
     */
    public function __construct(
        public string $role,
        public string $title,
        public HelpAudience $audience,
        public array $guide,
        public array $sections,
    ) {}

    /**
     * @return list<ArticleFile>
     */
    public function articles(): array
    {
        $all = $this->guide;
        foreach ($this->sections as $articles) {
            array_push($all, ...$articles);
        }

        return $all;
    }

    /**
     * Section order in the book (the role guide has its own place before them).
     *
     * @return list<HelpCategory>
     */
    public static function categoryOrder(): array
    {
        return [HelpCategory::GettingStarted, HelpCategory::DailyWork, HelpCategory::Settings, HelpCategory::Faq];
    }
}
