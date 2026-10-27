import { Form } from '@inertiajs/react';
import BranchController from '@/actions/App/Http/Controllers/Org/BranchController';
import { SelectField, TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

export type BranchRow = {
    id: number;
    name_ar: string;
    name_en: string | null;
    code: string | null;
    region_id?: number | null;
};

/** Create / edit a branch. Server errors (including the plan's branch limit) show inside the dialog. */
export function BranchFormDialog({
    open,
    onOpenChange,
    branch,
    regions,
    from,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branch: BranchRow | null;
    regions: { id: number; name: string }[];
    from?: 'wizard';
}) {
    const t = useT();
    const target = branch
        ? BranchController.update.form({ branch: branch.id })
        : BranchController.store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {branch
                            ? t('org.branches.edit_title')
                            : t('org.branches.new_title')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('org.branches.description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...target}
                    key={branch?.id ?? 'new'}
                    data-test="branch-form"
                    className="grid gap-4"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing, errors }) => (
                        <>
                            {from && (
                                <input type="hidden" name="from" value={from} />
                            )}
                            {errors.license && (
                                <p
                                    role="alert"
                                    className="text-body text-danger-text"
                                >
                                    <strong>
                                        {t('org.branches.limit_title')}:{' '}
                                    </strong>
                                    {errors.license}
                                </p>
                            )}
                            <TextField
                                label={t('org.branches.name_ar')}
                                name="name_ar"
                                defaultValue={branch?.name_ar ?? ''}
                                required
                                autoFocus
                                error={errors.name_ar}
                            />
                            <TextField
                                label={t('org.branches.name_en')}
                                name="name_en"
                                dir="ltr"
                                defaultValue={branch?.name_en ?? ''}
                                optional
                                error={errors.name_en}
                            />
                            <TextField
                                label={t('org.branches.code')}
                                name="code"
                                dir="ltr"
                                defaultValue={branch?.code ?? ''}
                                optional
                                error={errors.code}
                            />
                            {!branch && regions.length > 0 && (
                                <SelectField
                                    label={t('org.branches.region')}
                                    name="region_id"
                                    defaultValue=""
                                    optional
                                    options={[
                                        {
                                            value: '',
                                            label: t('org.branches.no_region'),
                                        },
                                        ...regions.map((r) => ({
                                            value: String(r.id),
                                            label: r.name,
                                        })),
                                    ]}
                                    error={errors.region_id}
                                />
                            )}
                            {errors.branch && (
                                <p
                                    role="alert"
                                    className="text-caption text-danger-text"
                                >
                                    {errors.branch}
                                </p>
                            )}
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('org.branches.cancel')}
                                </Button>
                                <Button
                                    loading={processing}
                                    data-test="branch-save"
                                >
                                    {t('org.branches.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
