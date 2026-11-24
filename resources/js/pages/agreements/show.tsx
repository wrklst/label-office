import { Form, Link } from '@inertiajs/react';
import { Download, Pencil, PenLine, Send } from 'lucide-react';
import AgreementController from '@/actions/App/Http/Controllers/AgreementController';
import {
    AgreementDocument,
    AgreementStatus,
} from '@/components/agreement-document';
import type { AgreementLine } from '@/components/agreement-document';
import { DeliverableList } from '@/components/agreement-deliverables';
import type { Deliverable } from '@/components/agreement-deliverables';
import { DeleteButton } from '@/components/delete-button';
import { CheckField, Field } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import { Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { edit, index } from '@/routes/agreements';
import { show as showContract } from '@/routes/contracts';
import { show as sign } from '@/routes/my-agreements';
import { show as showFile } from '@/routes/release-files';

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
    signing_rule: string;
    disclaimer: string;
    deliverables: Deliverable[];
    planned_deliverables: string[];
    signed_copy: string | null;
    has_text: boolean;
    can_sign: boolean;
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
                    : agreement.has_text
                      ? 'The text is frozen as it was sent. Every signature refers to it by the hash below.'
                      : 'Signed outside the application and filed here.'
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
                    {agreement.signed_copy && (
                        <Button variant="outline" asChild>
                            <a
                                href={AgreementController.downloadSignedCopy.url(
                                    agreement.id,
                                )}
                            >
                                <Download />
                                Signed copy
                            </a>
                        </Button>
                    )}
                    {!draft && agreement.has_text && (
                        <Button variant="outline" asChild>
                            <a href={AgreementController.pdf.url(agreement.id)}>
                                <Download />
                                PDF
                            </a>
                        </Button>
                    )}
                    {agreement.status === 'sent' && (
                        <>
                            {agreement.can_sign && (
                                <Button asChild>
                                    <Link href={sign(agreement.id)}>
                                        <PenLine />
                                        Read and sign
                                    </Link>
                                </Button>
                            )}
                            <FormDialog
                                title="Keep a copy signed elsewhere"
                                description="For an agreement the parties signed on paper or with another service. The copy closes the agreement: it then counts as signed by everyone and can no longer be signed here."
                                form={AgreementController.signedCopy.form(
                                    agreement.id,
                                )}
                                submitLabel="Keep the copy and close"
                                trigger={
                                    <Button variant="outline">
                                        Signed elsewhere
                                    </Button>
                                }
                            >
                                {(errors) => (
                                    <>
                                        <Field
                                            label="Signed copy"
                                            htmlFor="signed-copy"
                                            error={errors.file}
                                            required
                                            hint="PDF, JPEG or PNG, up to 20 MB."
                                        >
                                            <Input
                                                id="signed-copy"
                                                name="file"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                required
                                            />
                                        </Field>
                                        <CheckField
                                            name="confirmed"
                                            value="1"
                                            label="Every party has signed this copy"
                                            required
                                        />
                                        {errors.confirmed && (
                                            <p className="text-sm text-red-600">
                                                {errors.confirmed}
                                            </p>
                                        )}
                                    </>
                                )}
                            </FormDialog>
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
                            Needed for the label:{' '}
                            <span className="font-medium">
                                {agreement.signatories.length === 0
                                    ? 'nobody has the right to sign yet'
                                    : agreement.signatories.length === 1
                                      ? agreement.signatories[0]
                                      : agreement.signing_rule === 'any'
                                        ? `one of ${agreement.signatories.join(', ')}`
                                        : `all of ${agreement.signatories.join(', ')}`}
                            </span>
                            . The payee signs through their own account: give
                            them access under Settings → Team if they have none.
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {agreement.disclaimer}
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

            {draft && agreement.planned_deliverables.length > 0 && (
                <Panel
                    title="To be delivered"
                    description="Each line of the deliverables becomes an item the payee hands a file in for, once everyone has signed. The files are filed with the release."
                >
                    <ul className="list-disc pl-5 text-sm">
                        {agreement.planned_deliverables.map((title) => (
                            <li key={title}>{title}</li>
                        ))}
                    </ul>
                </Panel>
            )}

            {agreement.deliverables.length > 0 && (
                <Panel
                    title="Deliverables"
                    description={
                        agreement.status === 'signed'
                            ? 'What the payee hands in under My agreements. A file is filed with the release as soon as it arrives; accepting it says it is what was agreed.'
                            : 'The payee can deliver once everyone has signed.'
                    }
                >
                    <DeliverableList
                        deliverables={agreement.deliverables}
                        file={(deliverable) => (
                            <a
                                className="underline"
                                href={`${showFile.url(deliverable.file_id ?? 0)}?download=1`}
                            >
                                {deliverable.file}
                            </a>
                        )}
                    >
                        {(deliverable) =>
                            (deliverable.status === 'delivered' ||
                                deliverable.status === 'accepted') && (
                                <div className="flex gap-2">
                                    {deliverable.status === 'delivered' && (
                                        <Form
                                            {...AgreementController.accept.form(
                                                [agreement.id, deliverable.id],
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Accept
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                    <FormDialog
                                        title={`Hand back: ${deliverable.title}`}
                                        description="The payee is emailed your note and can deliver again."
                                        form={AgreementController.return.form([
                                            agreement.id,
                                            deliverable.id,
                                        ])}
                                        submitLabel="Hand back"
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                Hand back
                                            </Button>
                                        }
                                    >
                                        {(errors) => (
                                            <Field
                                                label="What is wrong with it"
                                                htmlFor={`note-${deliverable.id}`}
                                                error={errors.returned_note}
                                                required
                                            >
                                                <Textarea
                                                    id={`note-${deliverable.id}`}
                                                    name="returned_note"
                                                    rows={4}
                                                    required
                                                />
                                            </Field>
                                        )}
                                    </FormDialog>
                                </div>
                            )
                        }
                    </DeliverableList>
                </Panel>
            )}

            {agreement.status === 'sent' && (
                <p className="text-sm text-muted-foreground">
                    {agreement.people.length > 0
                        ? `Can sign through their account: ${agreement.people.join(', ')}.`
                        : 'Nobody with an account can sign this yet.'}
                </p>
            )}

            {agreement.has_text ? (
                <AgreementDocument
                    text={agreement.text}
                    hash={agreement.body_hash}
                    lines={agreement.lines}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    This agreement was signed outside the application. The
                    signed copy is the agreement: download it above.
                </p>
            )}
        </Page>
    );
}

AgreementShow.layout = {
    breadcrumbs: [{ title: 'Agreements', href: index() }],
};
