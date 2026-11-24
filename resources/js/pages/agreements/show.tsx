import { Form, Link } from '@inertiajs/react';
import { Pencil, PenLine, Send } from 'lucide-react';
import AgreementController from '@/actions/App/Http/Controllers/AgreementController';
import {
    AgreementDocument,
    AgreementStatus,
} from '@/components/agreement-document';
import type { AgreementLine } from '@/components/agreement-document';
import { DeleteButton } from '@/components/delete-button';
import { Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/agreements';
import { show as showContract } from '@/routes/contracts';
import { show as sign } from '@/routes/my-agreements';

type Agreement = {
    id: number;
    name: string;
    status: string;
    licensor: string;
    body_hash: string | null;
    contract_id: number | null;
    share: string | null;
    payee: string | null;
    template: string | null;
    text: string;
    sent_at: string | null;
    signed_at: string | null;
    send_block: string | null;
    signatories: string[];
    lines: AgreementLine[];
    people: string[];
};

export default function AgreementShow({ agreement }: { agreement: Agreement }) {
    const draft = agreement.status === 'draft';

    return (
        <Page
            title={agreement.name}
            description={
                draft
                    ? `A draft from the template ${agreement.template ?? '(deleted)'}. Its text follows the template, the payee and the label's legal details until it is sent.`
                    : 'The text is frozen as it was sent. Every signature refers to it by the hash below.'
            }
            actions={
                <>
                    <AgreementStatus status={agreement.status} />
                    {draft && (
                        <>
                            <Button variant="outline" asChild>
                                <Link href={edit(agreement.id)}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                            <DeleteButton
                                url={AgreementController.destroy.url(
                                    agreement.id,
                                )}
                                label="Delete"
                                title={`Delete ${agreement.name}?`}
                                description="The draft is removed. Nothing was sent."
                            />
                        </>
                    )}
                    {agreement.status === 'sent' && (
                        <>
                            <Button asChild>
                                <Link href={sign(agreement.id)}>
                                    <PenLine />
                                    Read and sign
                                </Link>
                            </Button>
                            <Form
                                {...AgreementController.void.form(agreement.id)}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Withdraw
                                    </Button>
                                )}
                            </Form>
                        </>
                    )}
                </>
            }
        >
            {draft && (
                <Panel
                    title="Send for signature"
                    description="Sending freezes the text and the parties. After that the agreement can only be signed or withdrawn."
                >
                    <div className="grid gap-3 text-sm">
                        <p>
                            Signs for the label:{' '}
                            <span className="font-medium">
                                {agreement.signatories.join(', ') ||
                                    'nobody yet'}
                            </span>
                            . The payee signs through their own account: give
                            them access under Settings → Team if they have none.
                        </p>
                        {agreement.send_block && (
                            <p className="text-red-600">
                                {agreement.send_block}
                            </p>
                        )}
                        <Form {...AgreementController.send.form(agreement.id)}>
                            {({ processing }) => (
                                <Button
                                    disabled={
                                        processing ||
                                        agreement.send_block !== null
                                    }
                                >
                                    <Send />
                                    Send for signature
                                </Button>
                            )}
                        </Form>
                    </div>
                </Panel>
            )}

            {agreement.status === 'signed' && (
                <Panel
                    title="Royalty contract"
                    description="The agreement is the signed document; the contract is what statements are calculated from."
                >
                    {agreement.contract_id ? (
                        <Button variant="outline" asChild>
                            <Link href={showContract(agreement.contract_id)}>
                                Open the contract
                            </Link>
                        </Button>
                    ) : (
                        <Form
                            {...AgreementController.contract.form(agreement.id)}
                            className="grid gap-3 text-sm"
                        >
                            {({ processing }) => (
                                <>
                                    <p>
                                        Creates a contract that gives{' '}
                                        {agreement.payee} {agreement.share} %
                                        and sets it on the linked track or
                                        release, unless that already has a
                                        contract. Nothing is created on its own:
                                        check the contract before the next
                                        statements.
                                    </p>
                                    <div>
                                        <Button disabled={processing}>
                                            Create the contract
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </Panel>
            )}

            {!draft && (
                <p className="text-sm text-muted-foreground">
                    {agreement.people.length > 0
                        ? `Can sign through their account: ${agreement.people.join(', ')}.`
                        : 'Nobody with an account can sign this yet.'}
                </p>
            )}

            <AgreementDocument
                text={agreement.text}
                hash={agreement.body_hash}
                lines={agreement.lines}
            />
        </Page>
    );
}

AgreementShow.layout = {
    breadcrumbs: [{ title: 'Agreements', href: index() }],
};
