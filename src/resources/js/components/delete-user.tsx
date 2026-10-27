import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field } from '@/components/opsone/field';
import { useT } from '@/lib/i18n';

export default function DeleteUser() {
    const t = useT();
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('delete.title')}
                description={t('delete.description')}
            />
            <div className="space-y-4 rounded-lg border border-stroke-weak bg-danger-bg p-4 contrast:border-danger-text">
                <div className="relative space-y-0.5 text-danger-text">
                    <p className="font-semibold">{t('delete.warning')}</p>
                    <p className="text-body">{t('delete.warning_text')}</p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            {t('delete.title')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>{t('delete.confirm_title')}</DialogTitle>
                        <DialogDescription>
                            {t('delete.confirm_description')}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <Field
                                        label={t('auth.password')}
                                        error={errors.password}
                                        required
                                    >
                                        {(control) => (
                                            <PasswordInput
                                                {...control}
                                                name="password"
                                                ref={passwordInput}
                                                autoComplete="current-password"
                                            />
                                        )}
                                    </Field>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                {t('ui.cancel')}
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            loading={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                {t('delete.title')}
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
