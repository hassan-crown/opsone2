import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import SearchController from '@/actions/App/Http/Controllers/SearchController';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Group = {
    key: string;
    results: {
        type: string;
        title: string;
        url: string;
        subtitle: string | null;
    }[];
};

const MIN_LENGTH = 2;

/**
 * Global search box (ShellSearch). Suggestions come from the server, already filtered by the user's grants;
 * the box never shows counts. Enter opens the full results page.
 */
export function ShellSearch() {
    const t = useT();
    const listId = useId();
    const [term, setTerm] = useState('');
    const [groups, setGroups] = useState<Group[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const wrapper = useRef<HTMLDivElement>(null);

    const flat = groups.flatMap((group) => group.results);
    const query = term.trim();

    useEffect(() => {
        if (query.length < MIN_LENGTH) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(SearchController.suggest.url({ query: { q: query } }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data: { groups: Group[] } | null) => {
                    setGroups(data?.groups ?? []);
                    setActive(-1);
                    setOpen(true);
                })
                .catch(() => undefined);
        }, 200);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (!wrapper.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const showPanel = open && query.length >= MIN_LENGTH;

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' && flat.length > 0) {
            event.preventDefault();
            setActive((index) => (index + 1) % flat.length);
        } else if (event.key === 'ArrowUp' && flat.length > 0) {
            event.preventDefault();
            setActive((index) => (index <= 0 ? flat.length - 1 : index - 1));
        } else if (event.key === 'Escape') {
            setOpen(false);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            setOpen(false);
            if (active >= 0 && flat[active]) {
                router.visit(flat[active].url);
            } else if (query.length >= MIN_LENGTH) {
                router.visit(
                    SearchController.index.url({ query: { q: query } }),
                );
            }
        }
    };

    let index = -1;

    return (
        <>
            <Button
                variant="ghost"
                size="icon"
                className="text-strong md:hidden"
                asChild
            >
                <Link
                    href={SearchController.index()}
                    aria-label={t('search.label')}
                >
                    <Icon icon={Search} />
                </Link>
            </Button>
            <div
                ref={wrapper}
                role="search"
                className="relative w-full max-w-sm max-md:hidden"
            >
                <Icon
                    icon={Search}
                    size="sm"
                    className="pointer-events-none absolute inset-s-3 top-1/2 -translate-y-1/2 text-weak"
                />
                <input
                    type="search"
                    value={term}
                    onChange={(event) => {
                        setTerm(event.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                    role="combobox"
                    aria-expanded={showPanel}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        active >= 0 ? `${listId}-${active}` : undefined
                    }
                    aria-label={t('search.label')}
                    placeholder={t('search.placeholder')}
                    maxLength={100}
                    autoComplete="off"
                    data-test="shell-search-input"
                    className="field-control w-full ps-9 text-control"
                />
                {showPanel && (
                    <div
                        id={listId}
                        role="listbox"
                        aria-label={t('search.title')}
                        className="absolute inset-x-0 top-full z-30 mt-1 max-h-96 overflow-y-auto rounded-lg border border-stroke-weak bg-raised p-2 shadow-raised"
                    >
                        {groups.length === 0 ? (
                            <p className="px-3 py-2 text-body text-weak">
                                {t('search.no_results')}
                            </p>
                        ) : (
                            groups.map((group) => (
                                <div key={group.key} role="group">
                                    <p className="px-3 pt-2 pb-1 text-caption font-semibold text-weak">
                                        {t(
                                            `search.group.${group.key}` as TranslationKey,
                                        )}
                                    </p>
                                    {group.results.map((result) => {
                                        index += 1;
                                        const position = index;

                                        return (
                                            <Link
                                                key={result.url + result.title}
                                                id={`${listId}-${position}`}
                                                role="option"
                                                aria-selected={
                                                    active === position
                                                }
                                                href={result.url}
                                                onClick={() => setOpen(false)}
                                                className={cn(
                                                    'flex flex-col rounded-md px-3 py-2 text-strong no-underline hover:bg-hover',
                                                    active === position &&
                                                        'bg-hover',
                                                )}
                                            >
                                                <span className="text-control">
                                                    {result.title}
                                                </span>
                                                {result.subtitle && (
                                                    <span className="text-caption text-weak">
                                                        {result.subtitle}
                                                    </span>
                                                )}
                                            </Link>
                                        );
                                    })}
                                </div>
                            ))
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
