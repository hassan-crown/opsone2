import { router, useForm } from '@inertiajs/react';
import { Eraser, FileText, PenLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import {
    CheckboxField,
    FieldError,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { sign, signCode } from '@/routes/customer/documents';
import type { PortalDocument, PortalSigning } from '@/types/portal';

const PAD_HEIGHT = 180;

/**
 * Drawing surface for the signature: pointer events (mouse, pen, finger), scaled for the screen density. Reports a
 * PNG data URL after each stroke, or null once cleared.
 */
function SignaturePad({
    label,
    clearLabel,
    onChange,
    invalid,
}: {
    label: string;
    clearLabel: string;
    onChange: (dataUrl: string | null) => void;
    invalid?: boolean;
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const [empty, setEmpty] = useState(true);

    useEffect(() => {
        const el = canvas.current;
        if (!el) {
            return;
        }
        const ratio = window.devicePixelRatio || 1;
        el.width = el.offsetWidth * ratio;
        el.height = PAD_HEIGHT * ratio;
        const ctx = el.getContext('2d');
        if (ctx) {
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#14203c';
        }
    }, []);

    const point = (e: ReactPointerEvent<HTMLCanvasElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();

        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    };

    const start = (e: ReactPointerEvent<HTMLCanvasElement>) => {
        const ctx = canvas.current?.getContext('2d');
        if (!ctx) {
            return;
        }
        e.currentTarget.setPointerCapture(e.pointerId);
        drawing.current = true;
        const { x, y } = point(e);
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineTo(x + 0.1, y + 0.1);
        ctx.stroke();
    };

    const move = (e: ReactPointerEvent<HTMLCanvasElement>) => {
        const ctx = canvas.current?.getContext('2d');
        if (!drawing.current || !ctx) {
            return;
        }
        const { x, y } = point(e);
        ctx.lineTo(x, y);
        ctx.stroke();
    };

    const end = () => {
        if (!drawing.current || !canvas.current) {
            return;
        }
        drawing.current = false;
        setEmpty(false);
        onChange(canvas.current.toDataURL('image/png'));
    };

    const clear = () => {
        const el = canvas.current;
        el?.getContext('2d')?.clearRect(0, 0, el.width, el.height);
        setEmpty(true);
        onChange(null);
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between">
                <span className="text-label text-strong">{label}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={clear}
                    disabled={empty}
                >
                    <Icon icon={Eraser} size="sm" />
                    {clearLabel}
                </Button>
            </div>
            <canvas
                ref={canvas}
                aria-label={label}
                role="img"
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerCancel={end}
                onPointerLeave={end}
                style={{ height: PAD_HEIGHT }}
                className={`w-full touch-none rounded-md border bg-white ${invalid ? 'border-danger-text' : 'border-stroke-control'}`}
            />
        </div>
    );
}

/**
 * DocSign (ADR-0029): read the PDF, type the name, draw the signature, accept the statement, then confirm with a
 * fresh code. The PDF hash and content hash shown here travel with the signature, so a changed document is refused.
 */
export function SignPanel({
    doc,
    signing,
    open: initiallyOpen,
}: {
    doc: PortalDocument;
    signing: PortalSigning;
    open: boolean;
}) {
    const t = useT();
    const [open, setOpen] = useState(initiallyOpen);
    const [codeSent, setCodeSent] = useState(false);
    const [sending, setSending] = useState(false);
    const form = useForm({
        signer_name: '',
        signature: '',
        consent: false,
        code: '',
        content_hash: doc.content_hash,
        pdf_sha256: signing.pdf_sha256 ?? '',
        locale: signing.locale,
    });

    if (!open) {
        return (
            <Card className="sticky bottom-20 md:bottom-4">
                <p className="text-body text-weak">
                    {t('esign.ui.sign_intro')}
                </p>
                <div>
                    <Button type="button" onClick={() => setOpen(true)}>
                        <Icon icon={PenLine} size="sm" />
                        {t('esign.ui.start')}
                    </Button>
                </div>
            </Card>
        );
    }

    const requestCode = () => {
        setSending(true);
        router.post(
            signCode.url(doc.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setCodeSent(true),
                onError: (errors) => {
                    if (errors.code) {
                        form.setError('code', errors.code);
                    }
                },
                onFinish: () => setSending(false),
            },
        );
    };

    const submit = () => {
        form.post(sign.url(doc.id), { preserveScroll: true });
    };

    // Refusals of the signing service (already signed, expired…) come under a key outside the form fields.
    const errors = form.errors as Partial<Record<string, string>>;
    const ready =
        form.data.signer_name.trim().length >= 2 &&
        form.data.signature !== '' &&
        form.data.consent;

    return (
        <Card className="flex flex-col gap-4">
            <div>
                <h2 className="text-label font-semibold text-strong">
                    {t('esign.ui.sign_title')}
                </h2>
                <p className="text-body text-weak">
                    {t('esign.ui.sign_intro')}
                </p>
            </div>
            <a
                href={signing.pdfs[signing.locale]}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-2 text-label text-link underline-offset-4 hover:underline"
            >
                <Icon icon={FileText} size="sm" />
                {t('esign.ui.view_pdf')}
            </a>
            <TextField
                label={t('esign.ui.name')}
                name="signer_name"
                autoComplete="name"
                required
                maxLength={120}
                value={form.data.signer_name}
                onChange={(e) => form.setData('signer_name', e.target.value)}
                error={form.errors.signer_name}
            />
            <div className="flex flex-col gap-1">
                <SignaturePad
                    label={t('esign.ui.draw')}
                    clearLabel={t('esign.ui.clear')}
                    invalid={!!form.errors.signature}
                    onChange={(value) => form.setData('signature', value ?? '')}
                />
                {form.errors.signature && (
                    <FieldError>{form.errors.signature}</FieldError>
                )}
            </div>
            <CheckboxField
                label={t('esign.ui.consent')}
                hint={t('esign.consent.text')}
                name="consent"
                checked={form.data.consent}
                onChange={(e) => form.setData('consent', e.target.checked)}
                error={form.errors.consent}
            />
            <div className="flex flex-col gap-3 border-t border-stroke-weak pt-3">
                {signing.destination && (
                    <p className="text-caption text-weak">
                        {t('esign.ui.code_to', { to: '' })}
                        <bdi dir="ltr">{signing.destination}</bdi>
                    </p>
                )}
                <div>
                    <Button
                        type="button"
                        variant={codeSent ? 'outline' : 'default'}
                        disabled={!ready || sending}
                        onClick={requestCode}
                    >
                        {sending && <Spinner />}
                        {codeSent
                            ? t('esign.ui.resend_code')
                            : t('esign.ui.send_code')}
                    </Button>
                </div>
                <TextField
                    label={t('esign.ui.code')}
                    name="code"
                    dir="ltr"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    value={form.data.code}
                    onChange={(e) =>
                        form.setData('code', e.target.value.replace(/\D/g, ''))
                    }
                    error={form.errors.code}
                />
                {(errors.transition || form.errors.pdf_sha256) && (
                    <FieldError>
                        {errors.transition ?? form.errors.pdf_sha256}
                    </FieldError>
                )}
                <div>
                    <Button
                        type="button"
                        disabled={
                            !ready ||
                            form.data.code.length !== 6 ||
                            form.processing
                        }
                        onClick={submit}
                    >
                        {form.processing && <Spinner />}
                        {t('esign.ui.submit')}
                    </Button>
                </div>
            </div>
        </Card>
    );
}
