import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import AgreementController from '@/actions/App/Http/Controllers/AgreementController';
import { Field, SelectInput } from '@/components/field';
import { Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { index, show } from '@/routes/agreements';
import type { Option } from '@/types';

type Agreement = {
    id: number;
    name: string;
    agreement_template_id: number | null;
    licensor: string;
    payee_id: number;
    artist_payee_id: number | null;
    release_id: number | null;
    track_id: number | null;
    terms: Record<string, string | null> | null;
};

type TemplateOption = { id: number; name: string; terms: string[] };

/** The terms in the order they are asked, with how each is entered. */
const TERMS: {
    key: string;
    label: string;
    hint?: string;
    type?: 'date' | 'number' | 'long';
}[] = [
    { key: 'effective_date', label: 'Effective date', type: 'date' },
    { key: 'work_title', label: 'Title of the remix or release' },
    {
        key: 'work_original_title',
        label: 'Title of the recording that is remixed',
    },
    { key: 'work_artist', label: 'Artist' },
    {
        key: 'work_tracks',
        label: 'Tracks',
        hint: 'One per line.',
        type: 'long',
    },
    {
        key: 'share',
        label: 'Share of the payee in percent',
        type: 'number',
    },
    {
        key: 'publishing_share',
        label: 'Share of the payee in the publishing in percent',
        type: 'number',
    },
    {
        key: 'term',
        label: 'Term',
        hint: 'How long the rights are granted for, in words, for example: seven years from the day of release.',
    },
    {
        key: 'deliverables',
        label: 'Deliverables',
        hint: 'What has to be delivered, for example: the master as WAV, 24 bit, 44.1 kHz, not limited; artwork 3000 × 3000 px; a short biography.',
        type: 'long',
    },
    { key: 'delivery_date', label: 'Delivery due', type: 'date' },
];

export default function AgreementForm({
    agreement,
    templateOptions,
    payeeOptions,
    releaseOptions,
    trackOptions,
}: {
    agreement: Agreement | null;
    templateOptions: TemplateOption[];
    payeeOptions: Option[];
    releaseOptions: Option[];
    trackOptions: Option[];
}) {
    const [templateId, setTemplateId] = useState(
        String(agreement?.agreement_template_id ?? ''),
    );
    const [licensor, setLicensor] = useState(agreement?.licensor ?? 'label');
    const template = templateOptions.find(
        (option) => String(option.id) === templateId,
    );
    const asked = TERMS.filter((term) => template?.terms.includes(term.key));

    return (
        <Page
            title={agreement ? `Edit ${agreement.name}` : 'New agreement'}
            description="A draft can be changed freely. Sending it for signature freezes its text."
        >
            <Form
                {...(agreement
                    ? AgreementController.update.form(agreement.id)
                    : AgreementController.store.form())}
                className="grid gap-6"
            >
                {({ errors, processing }) => (
                    <>
                        <Panel title="Agreement">
                            <div className="grid gap-6">
                                <Field
                                    label="Name"
                                    htmlFor="name"
                                    error={errors.name}
                                    required
                                    hint="For your own lists; it is not part of the text."
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={agreement?.name}
                                        placeholder="Example Song – Example Remix"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Template"
                                    htmlFor="agreement_template_id"
                                    error={errors.agreement_template_id}
                                    required
                                >
                                    <SelectInput
                                        id="agreement_template_id"
                                        name="agreement_template_id"
                                        value={templateId}
                                        onChange={(event) =>
                                            setTemplateId(event.target.value)
                                        }
                                        options={templateOptions}
                                        placeholder="Choose template…"
                                        required
                                    />
                                </Field>
                            </div>
                        </Panel>

                        <Panel
                            title="Parties"
                            description="The label is always a party: it releases the music and pays the share."
                        >
                            <div className="grid gap-6">
                                <Field
                                    label="Who grants the rights"
                                    htmlFor="licensor"
                                    error={errors.licensor}
                                    required
                                    hint="For a remix: who commissions it and holds the rights in the original recording. Choose a template that is written for the same case."
                                >
                                    <SelectInput
                                        id="licensor"
                                        name="licensor"
                                        value={licensor}
                                        onChange={(event) =>
                                            setLicensor(event.target.value)
                                        }
                                    >
                                        <option value="label">The label</option>
                                        <option value="artist">
                                            An artist of the label, as a third
                                            party
                                        </option>
                                    </SelectInput>
                                </Field>
                                {licensor === 'artist' && (
                                    <Field
                                        label="Artist"
                                        htmlFor="artist_payee_id"
                                        error={errors.artist_payee_id}
                                        required
                                        hint="The payee who holds the rights in the original recording. They sign too."
                                    >
                                        <SelectInput
                                            id="artist_payee_id"
                                            name="artist_payee_id"
                                            defaultValue={
                                                agreement?.artist_payee_id ?? ''
                                            }
                                            options={payeeOptions}
                                            placeholder="Choose payee…"
                                            required
                                        />
                                    </Field>
                                )}
                                <Field
                                    label="Payee"
                                    htmlFor="payee_id"
                                    error={errors.payee_id}
                                    required
                                    hint="The other party: the remixer, or the artist of a release."
                                >
                                    <SelectInput
                                        id="payee_id"
                                        name="payee_id"
                                        defaultValue={agreement?.payee_id ?? ''}
                                        options={payeeOptions}
                                        placeholder="Choose payee…"
                                        required
                                    />
                                </Field>
                            </div>
                        </Panel>

                        <Panel
                            title="What it is about"
                            description="Optional links to the catalog, for your own reference."
                        >
                            <div className="grid gap-6 sm:grid-cols-2">
                                <Field
                                    label="Release"
                                    htmlFor="release_id"
                                    error={errors.release_id}
                                >
                                    <SelectInput
                                        id="release_id"
                                        name="release_id"
                                        defaultValue={
                                            agreement?.release_id ?? ''
                                        }
                                        options={releaseOptions}
                                        placeholder="None"
                                    />
                                </Field>
                                <Field
                                    label="Track"
                                    htmlFor="track_id"
                                    error={errors.track_id}
                                >
                                    <SelectInput
                                        id="track_id"
                                        name="track_id"
                                        defaultValue={agreement?.track_id ?? ''}
                                        options={trackOptions}
                                        placeholder="None"
                                    />
                                </Field>
                            </div>
                        </Panel>

                        {asked.length > 0 && (
                            <Panel
                                title="Terms"
                                description="What this template leaves open. Anything left empty has to be filled in before the agreement can be sent."
                            >
                                <div className="grid gap-6">
                                    {asked.map((term) => (
                                        <Field
                                            key={term.key}
                                            label={term.label}
                                            htmlFor={`terms-${term.key}`}
                                            error={errors[`terms.${term.key}`]}
                                            hint={term.hint}
                                        >
                                            {term.type === 'long' ? (
                                                <Textarea
                                                    id={`terms-${term.key}`}
                                                    name={`terms[${term.key}]`}
                                                    rows={4}
                                                    defaultValue={
                                                        agreement?.terms?.[
                                                            term.key
                                                        ] ?? ''
                                                    }
                                                />
                                            ) : (
                                                <Input
                                                    id={`terms-${term.key}`}
                                                    name={`terms[${term.key}]`}
                                                    type={
                                                        term.type === 'number'
                                                            ? 'number'
                                                            : (term.type ??
                                                              'text')
                                                    }
                                                    {...(term.type === 'number'
                                                        ? {
                                                              min: 0,
                                                              max: 100,
                                                              step: 'any',
                                                          }
                                                        : {})}
                                                    className={
                                                        term.type
                                                            ? 'w-48'
                                                            : undefined
                                                    }
                                                    defaultValue={
                                                        agreement?.terms?.[
                                                            term.key
                                                        ] ?? ''
                                                    }
                                                />
                                            )}
                                        </Field>
                                    ))}
                                </div>
                            </Panel>
                        )}

                        <div className="flex gap-2">
                            <Button disabled={processing}>
                                {agreement ? 'Save' : 'Create draft'}
                            </Button>
                            <Button variant="secondary" asChild>
                                <Link
                                    href={
                                        agreement ? show(agreement.id) : index()
                                    }
                                >
                                    Cancel
                                </Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Page>
    );
}

AgreementForm.layout = {
    breadcrumbs: [{ title: 'Agreements', href: index() }],
};
