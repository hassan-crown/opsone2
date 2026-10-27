export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header
            className={variant === 'small' ? 'space-y-1' : 'mb-6 space-y-1'}
        >
            <h2
                className={
                    variant === 'small'
                        ? 'text-subtitle text-strong'
                        : 'text-section text-strong'
                }
            >
                {title}
            </h2>
            {description && (
                <p className="text-body text-weak">{description}</p>
            )}
        </header>
    );
}
