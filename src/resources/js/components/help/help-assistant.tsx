import { Link } from '@inertiajs/react';
import { LifeBuoy, Sparkles } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';

type Source = { slug: string; title: string; url: string };

type Reply = {
    status: 'answered' | 'no_answer' | 'unavailable';
    answer: string | null;
    sources: Source[];
    support_url: string;
};

/**
 * «Ask the guide» (ADR-0050, AI-2). The server answers only from articles this user may read and always names them;
 * with no supporting article it says so and offers support.
 */
export function HelpAssistant() {
    const t = useT();
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const [reply, setReply] = useState<Reply | null>(null);
    const [failed, setFailed] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (question.trim().length < 3 || busy) {
            return;
        }
        setBusy(true);
        setFailed(false);
        setReply(null);
        const result = await api<Reply>('POST', '/help/assistant', {
            question,
        });
        setBusy(false);
        if (result.ok && result.data) {
            setReply(result.data);
        } else {
            setFailed(true);
        }
    }

    return (
        <Card className="mb-6 p-5" data-test="help-assistant">
            <h2 className="flex items-center gap-2 text-subtitle text-strong">
                <Icon icon={Sparkles} size="sm" />
                {t('help.assistant.title')}
            </h2>
            <p className="mb-3 text-weak">{t('help.assistant.description')}</p>
            <form onSubmit={submit} className="flex flex-wrap gap-2">
                <Input
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    maxLength={300}
                    placeholder={t('help.assistant.placeholder')}
                    aria-label={t('help.assistant.title')}
                    className="flex-1"
                    data-test="help-assistant-input"
                />
                <Button type="submit" disabled={busy || question.trim().length < 3}>
                    {busy ? t('help.assistant.asking') : t('help.assistant.ask')}
                </Button>
            </form>

            <div aria-live="polite" className="mt-4">
                {failed && (
                    <p className="text-weak">{t('help.assistant.unavailable')}</p>
                )}
                {reply?.status === 'unavailable' && (
                    <p className="text-weak">{t('help.assistant.unavailable')}</p>
                )}
                {reply?.status === 'answered' && (
                    <div data-test="help-assistant-answer">
                        <p className="whitespace-pre-line text-strong">
                            {reply.answer}
                        </p>
                        <p className="mt-3 text-label text-weak">
                            {t('help.assistant.sources')}
                        </p>
                        <ul className="mt-1 flex flex-col gap-1">
                            {reply.sources.map((source) => (
                                <li key={source.slug}>
                                    <Link href={source.url}>{source.title}</Link>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-label text-weak">
                            {t('help.assistant.disclaimer')}
                        </p>
                    </div>
                )}
                {reply?.status === 'no_answer' && (
                    <div data-test="help-assistant-no-answer">
                        <p className="text-strong">
                            {t('help.assistant.not_found')}
                        </p>
                        <Button variant="secondary" className="mt-2" asChild>
                            <Link href={reply.support_url}>
                                <Icon icon={LifeBuoy} />
                                {t('help.assistant.contact_support')}
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        </Card>
    );
}
