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
import { CheckField, Field, SelectInput } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import { Notice } from '@/components/notice';
import { Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { edit, index } from '@/routes/agreements';
import { show as showContract } from '@/routes/contracts';
import { show as sign } from '@/routes/my-agreements';
import { show as showFile } from '@/routes/release-files';
import type { Option } from '@/types';

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
    filed: {
        payee_id: number | null;
        contract_id: number | null;
        release_id: number | null;
        track_id: number | null;
        signed_on: string | null;
    } | null;
    can_sign: boolean;
    lines: AgreementLine[];
    people: string[];
};

export default function AgreementShow({
    agreement,
    payeeOptions = [],
    contractOptions = [],
    releaseOptions = [],
    trackOptions = [],
}: {
    agreement: Agreement;
    payeeOptions?: Option[];
    contractOptions?: Option[];
    releaseOptions?: Option[];
    trackOptions?: Option[];
}) {
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
                        <Notice id="legal">{agreement.disclaimer}</Notice>
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

            {agreement.status === 'signed' && agreement.has_text && (
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
                <Panel
                    title="What this agreement belongs to"
                    description="Signed outside the application: the signed copy is the agreement, download it above. Match it to what exists, or make a new payee or contract right here. Everything but the name can stay empty."
                >
                    <Form
                        {...AgreementController.match.form(agreement.id)}
                        options={{ preserveScroll: true }}
                        className="grid gap-6 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    label="Name"
                                    htmlFor="match-name"
                                    error={errors.name}
                                    required
                                >
                                    <Input
                                        id="match-name"
                                        name="name"
                                        defaultValue={agreement.name}
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Signed on"
                                    htmlFor="match-signed-on"
                                    error={errors.signed_on}
                                >
                                    <Input
                                        id="match-signed-on"
                                        name="signed_on"
                                        type="date"
                                        className="w-48"
                                        defaultValue={
                                            agreement.filed?.signed_on ?? ''
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Payee"
                                    htmlFor="match-payee"
                                    error={errors.payee_id ?? errors.new_payee}
                                    hint="Pick one, or type the name of a new payee: it is created when you save."
                                >
                                    <SelectInput
                                        id="match-payee"
                                        name="payee_id"
                                        defaultValue={
                                            agreement.filed?.payee_id ?? ''
                                        }
                                        options={payeeOptions}
                                        placeholder="Not matched"
                                    />
                                    <Input
                                        name="new_payee"
                                        aria-label="New payee"
                                        placeholder="Or a new payee…"
                                    />
                                </Field>
                                <Field
                                    label="Contract"
                                    htmlFor="match-contract"
                                    error={errors.contract_id}
                                    hint="The contract statements are calculated from. A new one is named after the agreement and has no splits yet: add them under Deals → Contracts."
                                >
                                    <SelectInput
                                        id="match-contract"
                                        name="contract_id"
                                        defaultValue={
                                            agreement.filed?.contract_id ?? ''
                                        }
                                        options={contractOptions}
                                        placeholder="Not matched"
                                    />
                                    <CheckField
                                        withZero
                                        name="new_contract"
                                        label="Make a new contract instead"
                                    />
                                </Field>
                                <Field
                                    label="Release"
                                    htmlFor="match-release"
                                    error={errors.release_id}
                                >
                                    <SelectInput
                                        id="match-release"
                                        name="release_id"
                                        defaultValue={
                                            agreement.filed?.release_id ?? ''
                                        }
                                        options={releaseOptions}
                                        placeholder="None"
                                    />
                                </Field>
                                <Field
                                    label="Track"
                                    htmlFor="match-track"
                                    error={errors.track_id}
                                >
                                    <SelectInput
                                        id="match-track"
                                        name="track_id"
                                        defaultValue={
                                            agreement.filed?.track_id ?? ''
                                        }
                                        options={trackOptions}
                                        placeholder="None"
                                    />
                                </Field>
                                <div className="sm:col-span-2">
                                    <Button disabled={processing}>Save</Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Panel>
            )}
        </Page>
    );
}

AgreementShow.layout = {
    breadcrumbs: [{ title: 'Agreements', href: index() }],
};
