import { Head, router, useForm } from '@inertiajs/react';
import {
    Archive,
    ExternalLink,
    Globe,
    Lock,
    Pencil,
    Plus,
    RotateCcw,
} from 'lucide-react';
import { useState } from 'react';
import { PageStateBadge, SiteNav } from '@/components/site/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { CheckboxField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale, useT } from '@/lib/i18n';
import type { PageRow, SiteTabs } from '@/types/site';

type Props = {
    pages: PageRow[];
    tabs: SiteTabs;
    canCreate: boolean;
    canPublish: boolean;
};

export default function SitePages({
    pages,
    tabs,
    canCreate,
    canPublish,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [creating, setCreating] = useState(false);
    const form = useForm({
        slug: '',
        title: { ar: '', en: '' },
        show_in_nav: false,
    });

    const label = (page: PageRow) =>
        page.title[locale as 'ar' | 'en'] ||
        page.title.ar ||
        page.title.en ||
        `/${page.slug}`;

    return (
        <>
            <Head title={t('site.pages.title')} />
            <PageHeader
                title={t('site.pages.title')}
                description={t('site.pages.description')}
                actions={
                    canCreate && (
                        <Button onClick={() => setCreating(true)}>
                            <Icon icon={Plus} />
                            {t('site.pages.new')}
                        </Button>
                    )
                }
            />
            <SiteNav active="pages" tabs={tabs} />

            {pages.length === 0 ? (
                <EmptyState icon={Globe} title={t('site.pages.empty')} />
            ) : (
                <TableWrap>
                    <Table caption={t('site.pages.title')}>
                        <thead>
                            <tr>
                                <Th>{t('site.pages.col_page')}</Th>
                                <Th>{t('site.pages.col_path')}</Th>
                                <Th>{t('site.pages.col_state')}</Th>
                                <Th>{t('site.pages.col_languages')}</Th>
                                <Th>
                                    <span className="sr-only">
                                        {t('site.pages.col_actions')}
                                    </span>
                                </Th>
                            </tr>
                        </thead>
                        <tbody>
                            {pages.map((page) => (
                                <tr key={page.id}>
                                    <Td className="font-medium text-strong">
                                        {page.policy === 'system' && (
                                            <Icon
                                                icon={Lock}
                                                size="sm"
                                                className="me-1 inline"
                                            />
                                        )}
                                        {label(page)}
                                    </Td>
                                    <Td dir="ltr" className="text-start">
                                        {page.slug === ''
                                            ? '/'
                                            : `/${page.slug}`}
                                    </Td>
                                    <Td>
                                        <PageStateBadge state={page.state} />
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            {(['ar', 'en'] as const).map(
                                                (l) => (
                                                    <Badge
                                                        key={l}
                                                        variant={
                                                            page.locales[l]
                                                                .published
                                                                ? 'success'
                                                                : page.locales[
                                                                        l
                                                                    ].draft
                                                                  ? 'warning'
                                                                  : 'neutral'
                                                        }
                                                    >
                                                        {l.toUpperCase()}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-2">
                                            {page.can_edit && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={`/site-admin/pages/${page.id}`}
                                                        data-test="page-edit"
                                                    >
                                                        <Icon
                                                            icon={Pencil}
                                                            size="sm"
                                                        />
                                                        {t('site.pages.edit')}
                                                    </a>
                                                </Button>
                                            )}
                                            {page.can_edit && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={`/site-admin/pages/${page.id}/preview/${locale}`}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <Icon
                                                            icon={ExternalLink}
                                                            size="sm"
                                                        />
                                                        {t(
                                                            'site.pages.preview',
                                                        )}
                                                    </a>
                                                </Button>
                                            )}
                                            {canPublish && page.can_archive && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/site-admin/pages/${page.id}/archive`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Icon
                                                        icon={Archive}
                                                        size="sm"
                                                    />
                                                    {t('site.pages.archive')}
                                                </Button>
                                            )}
                                            {canPublish && page.can_restore && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/site-admin/pages/${page.id}/restore`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Icon
                                                        icon={RotateCcw}
                                                        size="sm"
                                                    />
                                                    {t('site.pages.restore')}
                                                </Button>
                                            )}
                                            {page.policy === 'system' && (
                                                <span className="text-caption text-weak">
                                                    {t('site.pages.protected')}
                                                </span>
                                            )}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </TableWrap>
            )}

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/site-admin/pages', {
                                onSuccess: () => setCreating(false),
                            });
                        }}
                        className="grid gap-4"
                    >
                        <DialogHeader>
                            <DialogTitle>{t('site.pages.new')}</DialogTitle>
                            <DialogDescription>
                                {t('site.pages.new_hint')}
                            </DialogDescription>
                        </DialogHeader>
                        <TextField
                            label={t('site.pages.field_slug')}
                            hint={t('site.pages.field_slug_hint')}
                            dir="ltr"
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData(
                                    'slug',
                                    e.target.value.toLowerCase(),
                                )
                            }
                            error={form.errors.slug}
                            required
                        />
                        <TextField
                            label={t('site.pages.field_title_ar')}
                            lang="ar"
                            dir="rtl"
                            value={form.data.title.ar}
                            onChange={(e) =>
                                form.setData('title', {
                                    ...form.data.title,
                                    ar: e.target.value,
                                })
                            }
                            error={form.errors['title.ar']}
                        />
                        <TextField
                            label={t('site.pages.field_title_en')}
                            lang="en"
                            dir="ltr"
                            value={form.data.title.en}
                            onChange={(e) =>
                                form.setData('title', {
                                    ...form.data.title,
                                    en: e.target.value,
                                })
                            }
                            error={form.errors['title.en']}
                        />
                        <CheckboxField
                            label={t('site.pages.field_nav')}
                            checked={form.data.show_in_nav}
                            onChange={(e) =>
                                form.setData('show_in_nav', e.target.checked)
                            }
                        />
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreating(false)}
                            >
                                {t('ui.cancel')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {t('site.pages.create')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
