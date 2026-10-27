import { CircleAlert } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { useT } from '@/lib/i18n';

export default function AlertError({
    errors,
    title,
}: {
    errors: string[];
    title?: string;
}) {
    const t = useT();

    return (
        <Alert variant="danger">
            <Icon icon={CircleAlert} />
            <AlertBody>
                <AlertTitle>{title || t('ui.something_wrong')}</AlertTitle>
                <AlertDescription>
                    <ul className="list-inside list-disc">
                        {Array.from(new Set(errors)).map((error, index) => (
                            <li key={index}>{error}</li>
                        ))}
                    </ul>
                </AlertDescription>
            </AlertBody>
        </Alert>
    );
}
