import { Badge } from '@/components/ui/badge';
import { dateTime } from '@/lib/format';

export type AgreementLine = {
    capacity: string;
    name: string;
    signed_by: string | null;
    signed_at: string | null;
};

const STATUS: Record<string, string> = {
    draft: 'Draft',
    sent: 'Out for signature',
    signed: 'Signed by everyone',
    void: 'Withdrawn',
};

const CAPACITY: Record<string, string> = {
    label: 'for the label',
    payee: 'payee',
    artist: 'artist',
};

export function AgreementStatus({ status }: { status: string }) {
    return (
        <Badge variant={status === 'signed' ? 'default' : 'secondary'}>
            {STATUS[status] ?? status}
        </Badge>
    );
}

export function capacityName(capacity: string): string {
    return CAPACITY[capacity] ?? capacity;
}

/**
 * The text of an agreement as it is signed, with who has signed it.
 */
export function AgreementDocument({
    text,
    hash,
    lines,
}: {
    text: string;
    hash: string | null;
    lines: AgreementLine[];
}) {
    return (
        <div className="grid gap-6">
            <pre className="rounded-lg border bg-background p-6 font-serif text-sm leading-relaxed whitespace-pre-wrap print:border-0 print:p-0">
                {text}
            </pre>
            {lines.length > 0 && (
                <ul className="divide-y rounded-lg border text-sm">
                    {lines.map((line, index) => (
                        <li
                            key={index}
                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span>
                                <span className="font-medium">{line.name}</span>{' '}
                                <span className="text-muted-foreground">
                                    ({capacityName(line.capacity)})
                                </span>
                            </span>
                            <span
                                className={
                                    line.signed_at
                                        ? ''
                                        : 'text-muted-foreground'
                                }
                            >
                                {line.signed_at
                                    ? `Signed by ${line.signed_by}, ${dateTime(line.signed_at)}`
                                    : 'Not signed yet'}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {hash && (
                <p className="font-mono text-xs break-all text-muted-foreground">
                    SHA-256 of the text: {hash}
                </p>
            )}
        </div>
    );
}

/**
 * A PDF or a picture shown in the page, so a contract can be read
 * without downloading it. `src` has to answer with the file inline.
 */
export function FileViewer({ src, name }: { src: string; name: string }) {
    return /\.pdf$/i.test(name) ? (
        <iframe
            src={src}
            title={name}
            className="h-[80vh] w-full rounded-lg border bg-background print:hidden"
        />
    ) : (
        <img
            src={src}
            alt={name}
            className="max-w-full rounded-lg border print:hidden"
        />
    );
}
