import { router, useForm } from '@inertiajs/react';
import { Eraser, FileText, PenLine, Undo2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import {
    CheckboxField,
    FieldError,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { ActionBar } from '@/components/portal/parts';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { sign, signCode } from '@/routes/customer/documents';
import type { PortalDocument, PortalSigning } from '@/types/portal';

type Point = { x: number; y: number };
/** One stroke with the pad size it was drawn at, so a resized pad redraws it in proportion instead of stretched. */
type Stroke = { width: number; height: number; points: Point[] };

/**
 * Drawing surface for the signature: pointer events (mouse, pen, finger), scaled for the screen density. The strokes
 * are kept, so a rotation or resize re-measures the canvas and redraws them, and «undo» removes the last one. Reports
 * a PNG data URL after each change, or null once empty.
 */
export function SignaturePad({
    label,
    clearLabel,
    undoLabel,
    onChange,
    invalid,
}: {
    label: string;
    clearLabel: string;
    undoLabel?: string;
    onChange: (dataUrl: string | null) => void;
    invalid?: boolean;
}) {
    const t = useT();
    const canvas = useRef<HTMLCanvasElement>(null);
    const strokes = useRef<Stroke[]>([]);
    const current = useRef<Stroke | null>(null);
    const [count, setCount] = useState(0);

    const context = () => canvas.current?.getContext('2d') ?? null;

    const paint = (ctx: CanvasRenderingContext2D, stroke: Stroke) => {
        const el = canvas.current;
        if (!el || stroke.points.length === 0) {
            return;
        }
        const scale = Math.min(
            el.offsetWidth / stroke.width,
            el.offsetHeight / stroke.height,
        );
        const [first, ...rest] = stroke.points;
        ctx.beginPath();
        ctx.moveTo(first.x * scale, first.y * scale);
        ctx.lineTo(first.x * scale + 0.1, first.y * scale + 0.1);
        rest.forEach((p) => ctx.lineTo(p.x * scale, p.y * scale));
        ctx.stroke();
    };

    const redraw = () => {
        const el = canvas.current;
        const ctx = context();
        if (!el || !ctx) {
            return;
        }
        const ratio = window.devicePixelRatio || 1;
        el.width = el.offsetWidth * ratio;
        el.height = el.offsetHeight * ratio;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#14203c';
        strokes.current.forEach((stroke) => paint(ctx, stroke));
    };

    useEffect(() => {
        const el = canvas.current;
        if (!el) {
            return;
        }
        redraw();
        const observer = new ResizeObserver(() => redraw());
        observer.observe(el);

        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const report = () => {
        setCount(strokes.current.length);
        onChange(
            strokes.current.length > 0 && canvas.current
                ? canvas.current.toDataURL('image/png')
                : null,
        );
    };

    const point = (e: ReactPointerEvent<HTMLCanvasElement>): Point => {
        const rect = e.currentTarget.getBoundingClientRect();

        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    };

    const start = (e: ReactPointerEvent<HTMLCanvasElement>) => {
        const el = canvas.current;
        const ctx = context();
        if (!el || !ctx) {
            return;
        }
        e.currentTarget.setPointerCapture(e.pointerId);
        current.current = {
            width: el.offsetWidth,
            height: el.offsetHeight,
            points: [point(e)],
        };
        paint(ctx, current.current);
    };

    const move = (e: ReactPointerEvent<HTMLCanvasElement>) => {
        const ctx = context();
        const stroke = current.current;
        if (!stroke || !ctx) {
            return;
        }
        const previous = stroke.points[stroke.points.length - 1];
        const next = point(e);
        stroke.points.push(next);
        ctx.beginPath();
        ctx.moveTo(previous.x, previous.y);
        ctx.lineTo(next.x, next.y);
        ctx.stroke();
    };

    const end = () => {
        if (!current.current) {
            return;
        }
        strokes.current.push(current.current);
        current.current = null;
        report();
    };

    const undo = () => {
        strokes.current.pop();
        redraw();
        report();
    };

    const clear = () => {
        strokes.current = [];
        redraw();
        report();
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-x-2">
                <span className="text-label text-strong">{label}</span>
                <div className="flex gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={undo}
                        disabled={count === 0}
                    >
                        <Icon icon={Undo2} size="sm" />
                        {undoLabel ?? t('portal.sign.undo')}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={clear}
                        disabled={count === 0}
                    >
                        <Icon icon={Eraser} size="sm" />
                        {clearLabel}
                    </Button>
                </div>
            </div>
            <canvas
                ref={canvas}
                aria-label={label}
                role="img"
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerCancel={end}
                className={`h-[200px] w-full touch-none rounded-md border bg-white ${invalid ? 'border-danger-text' : 'border-stroke-control'}`}
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
    open,
    onOpen,
    onRequestChanges,
}: {
    doc: PortalDocument;
    signing: PortalSigning;
    open: boolean;
    onOpen: () => void;
    onRequestChanges: () => void;
}) {
    const t = useT();
    const panel = useRef<HTMLDivElement>(null);
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

    // Opening swaps the floating bar for the panel at the end of the document: bring it into view.
    useEffect(() => {
        if (!open) {
            return;
        }
        const reduce = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        panel.current?.scrollIntoView({
            behavior: reduce ? 'auto' : 'smooth',
            block: 'start',
        });
    }, [open]);

    if (!open) {
        return (
            <ActionBar>
                <p className="text-caption text-weak">
                    {t('esign.ui.sign_intro')}
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        className="max-sm:flex-1"
                        onClick={onOpen}
                    >
                        <Icon icon={PenLine} size="sm" />
                        {t('esign.ui.start')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onRequestChanges}
                    >
                        {t('portal.document.request_changes')}
                    </Button>
                </div>
            </ActionBar>
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
        <Card ref={panel} className="flex scroll-mt-20 flex-col gap-4">
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
                    undoLabel={t('portal.sign.undo')}
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
