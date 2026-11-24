import { Link, usePage } from '@inertiajs/react';
import { AgreementStatus } from '@/components/agreement-document';
import { Panel } from '@/components/page';
import { recordLinkClass } from '@/components/record-link';
import { show } from '@/routes/agreements';

type LinkedAgreement = {
    id: number;
    name: string;
    status: string;
    payee: string | null;
    signed_at: string | null;
};

/**
 * The agreements about the record a page shows: its contract, release,
 * track or payee. The page sends them as `agreements`; with none, nothing is shown.
 */
export function AgreementLinks() {
    const agreements =
        (usePage().props.agreements as LinkedAgreement[] | undefined) ?? [];

    if (agreements.length === 0) {
        return null;
    }

    return (
        <Panel title="Agreements">
            <ul className="divide-y text-sm">
                {agreements.map((agreement) => (
                    <li
                        key={agreement.id}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2"
                    >
                        <Link
                            href={show(agreement.id)}
                            className={`font-medium ${recordLinkClass}`}
                        >
                            {agreement.name}
                        </Link>
                        <AgreementStatus status={agreement.status} />
                        <span className="text-muted-foreground">
                            {[agreement.payee, agreement.signed_at]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
