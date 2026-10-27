import { KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
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
import { useT } from '@/lib/i18n';
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    onDelete: (id: number, onError: () => void) => void;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    const t = useT();
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);
        onDelete(passkey.id, () => setIsDeleting(false));
    };

    return (
        <div className="flex items-center justify-between gap-3 border-b border-stroke-weak p-4 last:border-b-0">
            <div className="flex min-w-0 items-center gap-4">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-fill">
                    <Icon icon={KeyRound} className="text-weak" />
                </div>
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-label text-strong">{passkey.name}</p>
                        {passkey.authenticator && (
                            <Badge variant="neutral">
                                {passkey.authenticator}
                            </Badge>
                        )}
                    </div>
                    <p className="text-body text-weak">
                        {t('passkeys.added', { when: passkey.created_at_diff })}
                        {passkey.last_used_at_diff && (
                            <>
                                <span className="mx-1 text-weak">/</span>
                                {t('passkeys.last_used', {
                                    when: passkey.last_used_at_diff,
                                })}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="text-danger-text"
                    >
                        <Icon icon={Trash2} />
                        <span className="sr-only">{t('passkeys.remove')}</span>
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>{t('passkeys.remove_title')}</DialogTitle>
                    <DialogDescription>
                        {t('passkeys.remove_description', {
                            name: passkey.name,
                        })}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">
                                {t('ui.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={handleDelete}
                            loading={isDeleting}
                        >
                            {isDeleting
                                ? t('passkeys.removing')
                                : t('passkeys.remove_title')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
