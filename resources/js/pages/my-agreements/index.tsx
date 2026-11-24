import { Link } from '@inertiajs/react';
import { AgreementStatus } from '@/components/agreement-document';
import { EmptyState, Page, Panel } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { date } from '@/lib/format';
import { index, show } from '@/routes/my-agreements';

type Row = {
    id: number;
    name: string;
    status: string;
    label: string | null;
    sent_at: string | null;
    to_sign: boolean;
};

export default function MyAgreements({ agreements }: { agreements: Row[] }) {
    return (
        <Page
            title="My agreements"
            description="The agreements labels have sent you: to read, to sign, and to come back to."
        >
            {agreements.length === 0 ? (
                <EmptyState
                    title="No agreements"
                    description="When a label sends you an agreement to sign, it appears here."
                />
            ) : (
                <Panel flush>
                    <ul className="divide-y text-sm">
                        {agreements.map((agreement) => (
                            <li key={agreement.id}>
                                <Link
                                    href={show(agreement.id)}
                                    className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-muted/50"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {agreement.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {agreement.label}
                                            {agreement.sent_at &&
                                                ` · sent ${date(agreement.sent_at)}`}
                                        </span>
                                    </span>
                                    <span className="flex gap-2">
                                        {agreement.to_sign && (
                                            <Badge>Waiting for you</Badge>
                                        )}
                                        <AgreementStatus
                                            status={agreement.status}
                                        />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </Page>
    );
}

MyAgreements.layout = {
    breadcrumbs: [{ title: 'My agreements', href: index() }],
};
