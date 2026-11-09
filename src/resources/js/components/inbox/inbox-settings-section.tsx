import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import IntegrationsController from '@/actions/App/Http/Controllers/Notifications/IntegrationsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { InboxSettingsProps, InboxTemplate } from '@/types/inbox';

type Props = {
    inbox: InboxSettingsProps;
    branches: { id: number; name: string }[];
};

/** Settings → Integrations (P-02): the WhatsApp inbox switch, default branch and approved reply templates (AI-3). */
export function InboxSettingsSection({ inbox, branches }: Props) {
    const t = useT();
    const errors = usePage().props.errors as Record<string, string>;
    const [enabled, setEnabled] = useState(inbox.enabled);
    const [branch, setBranch] = useState<string>(
        inbox.default_branch_id ? String(inbox.default_branch_id) : '',
    );
    const [templates, setTemplates] = useState<InboxTemplate[]>(
        inbox.templates,
    );
    const [busy, setBusy] = useState(false);

    const update = (index: number, patch: Partial<InboxTemplate>) =>
        setTemplates((list) =>
            list.map((tpl, i) => (i === index ? { ...tpl, ...patch } : tpl)),
        );

    const save = () => {
        setBusy(true);
        router.put(
            IntegrationsController.updateWhatsappInbox.url(),
            {
                enabled,
                default_branch_id: branch === '' ? null : Number(branch),
                templates,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <section
            className="space-y-4 border-t border-stroke-weak pt-8"
            data-test="whatsapp-inbox-section"
        >
            <div>
                <h2 className="text-subtitle text-strong">
                    {t('integrations.whatsapp_inbox.title')}
                </h2>
                <p className="text-body text-weak">
                    {t('integrations.whatsapp_inbox.description')}
                </p>
            </div>
            <p className="text-caption text-weak">
                {t('integrations.whatsapp_inbox.webhook')}{' '}
                <bdi dir="ltr" className="font-mono">
                    {inbox.webhook_url}
                </bdi>
            </p>
            <label className="flex items-center gap-2 text-body text-strong">
                <input
                    type="checkbox"
                    checked={enabled}
                    onChange={(e) => setEnabled(e.target.checked)}
                    data-test="whatsapp-inbox-enabled"
                />
                {t('integrations.whatsapp_inbox.enable')}
            </label>
            <div className="grid max-w-sm gap-1">
                <Label htmlFor="inbox-branch">
                    {t('integrations.whatsapp_inbox.default_branch')}
                </Label>
                <select
                    id="inbox-branch"
                    value={branch}
                    onChange={(e) => setBranch(e.target.value)}
                    className="field-control text-control"
                >
                    <option value="">
                        {t('integrations.whatsapp_inbox.no_branch')}
                    </option>
                    {branches.map((b) => (
                        <option key={b.id} value={String(b.id)}>
                            {b.name}
                        </option>
                    ))}
                </select>
                <p className="text-caption text-weak">
                    {t('integrations.whatsapp_inbox.default_branch_hint')}
                </p>
            </div>

            <fieldset className="space-y-3">
                <legend className="mb-1 text-body font-semibold text-strong">
                    {t('integrations.whatsapp_inbox.templates')}
                </legend>
                <p className="text-caption text-weak">
                    {t('integrations.whatsapp_inbox.templates_hint')}
                </p>
                {templates.map((tpl, index) => (
                    <div
                        key={index}
                        className="flex flex-wrap items-end gap-2"
                        data-test="inbox-template-row"
                    >
                        <div className="grid gap-1">
                            <Label htmlFor={`tpl-name-${index}`}>
                                {t('integrations.whatsapp_inbox.template_name')}
                            </Label>
                            <Input
                                id={`tpl-name-${index}`}
                                dir="ltr"
                                value={tpl.name}
                                onChange={(e) =>
                                    update(index, { name: e.target.value })
                                }
                                className="w-56"
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`tpl-lang-${index}`}>
                                {t(
                                    'integrations.whatsapp_inbox.template_language',
                                )}
                            </Label>
                            <Input
                                id={`tpl-lang-${index}`}
                                dir="ltr"
                                value={tpl.language}
                                onChange={(e) =>
                                    update(index, { language: e.target.value })
                                }
                                className="w-20"
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`tpl-params-${index}`}>
                                {t(
                                    'integrations.whatsapp_inbox.template_params',
                                )}
                            </Label>
                            <Input
                                id={`tpl-params-${index}`}
                                type="number"
                                min={0}
                                max={10}
                                value={tpl.params}
                                onChange={(e) =>
                                    update(index, {
                                        params: Number(e.target.value),
                                    })
                                }
                                className="w-20"
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`tpl-label-${index}`}>
                                {t(
                                    'integrations.whatsapp_inbox.template_label',
                                )}
                            </Label>
                            <Input
                                id={`tpl-label-${index}`}
                                value={tpl.label}
                                onChange={(e) =>
                                    update(index, { label: e.target.value })
                                }
                                className="w-48"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            aria-label={t(
                                'integrations.whatsapp_inbox.remove_template',
                            )}
                            onClick={() =>
                                setTemplates((list) =>
                                    list.filter((_, i) => i !== index),
                                )
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                ))}
                {Object.entries(errors)
                    .filter(([key]) => key.startsWith('templates'))
                    .slice(0, 3)
                    .map(([key, message]) => (
                        <p key={key} className="text-caption text-danger-text">
                            {message}
                        </p>
                    ))}
                <Button
                    type="button"
                    variant="secondary"
                    onClick={() =>
                        setTemplates((list) => [
                            ...list,
                            { name: '', language: 'ar', params: 0, label: '' },
                        ])
                    }
                >
                    <Plus className="size-4" />
                    {t('integrations.whatsapp_inbox.add_template')}
                </Button>
            </fieldset>

            <Button
                type="button"
                loading={busy}
                onClick={save}
                data-test="save-whatsapp-inbox"
            >
                {t('integrations.whatsapp_inbox.save')}
            </Button>
        </section>
    );
}
