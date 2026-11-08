import { cn } from '@/lib/utils';

/**
 * An article body rendered by the server (App\Help\HelpMarkdown): raw HTML is stripped there, unsafe links are
 * dropped and images only point at the authorized help image route, so the markup is safe to insert.
 */
export function ArticleBody({
    html,
    className,
}: {
    html: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 text-body text-strong',
                '[&_h1]:text-subtitle [&_h2]:mt-2 [&_h2]:text-subtitle [&_h3]:font-semibold',
                '[&_li]:mt-1 [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6',
                '[&_a]:text-brand [&_a]:underline [&_strong]:font-semibold',
                '[&_img]:my-2 [&_img]:max-w-full [&_img]:rounded-lg [&_img]:border [&_img]:border-stroke-weak',
                '[&_blockquote]:border-s-4 [&_blockquote]:border-stroke-weak [&_blockquote]:ps-4 [&_blockquote]:text-weak',
                '[&_code]:rounded [&_code]:bg-neutral-bg [&_code]:px-1',
                '[&_table]:w-full [&_td]:border [&_td]:border-stroke-weak [&_td]:p-2 [&_th]:border [&_th]:border-stroke-weak [&_th]:p-2 [&_th]:text-start',
                className,
            )}
            data-test="help-article-body"
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
