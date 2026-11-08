<?php

namespace App\Help;

use App\Help\KnowledgeBase\ArticleLibrary;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;

/**
 * Renders an article body (Markdown) to safe HTML: raw HTML is stripped, unsafe link schemes are dropped, and an
 * image may only be one of our knowledge base files (`images/{slug}-{n}.png`), served through the authorized image
 * route of the reader's side. Any other image becomes its alt text. A link to another article is written
 * `[title](article:slug)` and points at that article's page on the reader's side (staff or portal).
 */
final class HelpMarkdown
{
    public const ARTICLE_LINK = '/^article:([a-z0-9]+(?:-[a-z0-9]+)*)$/';

    public static function toHtml(string $markdown, HelpAudience $audience): string
    {
        $imageRoute = $audience === HelpAudience::Customer ? 'customer.help.image' : 'help.image';
        $articleUrl = fn (string $slug) => $audience === HelpAudience::Customer
            ? route('customer.help.article', ['slug' => $slug], false)
            : route('help.article', ['article' => $slug], false);

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event) use ($imageRoute, $articleUrl) {
            $images = [];
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Image) {
                    $images[] = $node;
                } elseif ($node instanceof Link && preg_match(self::ARTICLE_LINK, $node->getUrl(), $m)) {
                    // The target page re-checks access; an article the reader may not read answers 404 there.
                    $node->setUrl($articleUrl($m[1]));
                }
            }
            foreach ($images as $node) {
                $url = $node->getUrl();
                $file = str_starts_with($url, 'images/') ? substr($url, 7) : '';
                if (preg_match(ArticleLibrary::IMAGE, $file)) {
                    $node->setUrl(route($imageRoute, ['file' => $file], false));

                    continue;
                }
                $alt = '';
                foreach ($node->children() as $child) {
                    if ($child instanceof Text) {
                        $alt .= $child->getLiteral();
                    }
                }
                $node->replaceWith(new Text($alt));
            }
        });

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
