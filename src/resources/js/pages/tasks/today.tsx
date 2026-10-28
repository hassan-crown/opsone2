import { Head, Link, usePage } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import { ExecHome } from '@/components/tasks/exec-home';
import type { ExecHomeData } from '@/components/tasks/exec-home';
import { useT } from '@/lib/i18n';

/** ExecHomeMobile / TaskEmpty as its own page (the executor's dashboard shows the same block). */
export default function TasksToday(props: ExecHomeData) {
    const t = useT();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('exec.title')} />
            <ExecHome {...props} name={auth.user.name} />
            <p className="mt-6 text-center">
                <Link
                    href={TaskController.index()}
                    className="inline-flex items-center gap-1 text-link hover:underline"
                >
                    <ListChecks className="size-4" aria-hidden="true" />
                    {t('exec.all_tasks')}
                </Link>
            </p>
        </>
    );
}

TasksToday.layout = {
    breadcrumbs: [{ title: 'exec.nav', href: TaskController.today() }],
};
