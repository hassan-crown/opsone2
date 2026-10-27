import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function Breadcrumbs({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    const t = useT();

    if (breadcrumbs.length === 0) {
        return null;
    }

    return (
        <Breadcrumb aria-label={t('ui.breadcrumbs')}>
            <BreadcrumbList>
                {breadcrumbs.map((item, index) => {
                    const isLast = index === breadcrumbs.length - 1;

                    return (
                        <Fragment key={index}>
                            <BreadcrumbItem>
                                {isLast ? (
                                    <BreadcrumbPage>
                                        {t(item.title)}
                                    </BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink asChild>
                                        <Link href={item.href}>
                                            {t(item.title)}
                                        </Link>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && <BreadcrumbSeparator />}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
