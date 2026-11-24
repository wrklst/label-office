import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import AgreementController from '@/actions/App/Http/Controllers/AgreementController';
import { CoverageFields } from '@/components/agreement-coverage';
import type { CoveredRelease } from '@/components/agreement-coverage';
import { Field, SelectInput } from '@/components/field';
import { Page, Panel } from '@/components/page';
import { RecordSelect } from '@/components/record-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { index, show } from '@/routes/agreements';

type Agreement = {
    id: number;
    name: string;
    agreement_template_id: number | null;
    licensor: string;
    payee_id: number;
    artist_payee_id: number | null;
    coverage: CoveredRelease[];
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
        hint: 'Their royalty: this much of the net income. It becomes their split on the contract.',
        type: 'number',
    },
    {
        key: 'artist_share',
        label: 'Share of the original artist in percent',
        hint: 'Their split on the same contract. The label keeps what the shares do not hand out.',
        type: 'number',
    },
    {
        key: 'publishing_share',
        label: 'Share of the payee in the publishing in percent',
        hint: 'Wording only: it is written into the agreement and not calculated.',
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
        hint: 'One item per line, for example: Master as WAV, 24 bit, 44.1 kHz, not limited. Each line becomes an item the payee hands a file in for, and needs a release chosen above to be filed with.',
        type: 'long',
    },
    { key: 'delivery_date', label: 'Delivery due', type: 'date' },
];

export default function AgreementForm({
    agreement,
    templateOptions,
}: {
    agreement: Agreement | null;
    templateOptions: TemplateOption[];
}) {
    const [templateId, setTemplateId] = useState(
        String(agreement?.agreement_template_id ?? ''),
    );
    const [licensor, setLicensor] = useState(agreement?.licensor ?? 'label');
    const [payeeId, setPayeeId] = useState<number | ''>(
        agreement?.payee_id ?? '',
    );
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
                                {
                                    <Field
                                        label="Original artist"
                                        htmlFor="artist_payee_id"
                                        error={errors.artist_payee_id}
                                        required={licensor === 'artist'}
                                        hint="For a remix: the artist of the original recording, as a party of their own. They sign too and can have a share. Leave empty for an agreement with the artist alone, such as a release."
                                    >
                                        <RecordSelect
                                            id="artist_payee_id"
                                            records="payees"
                                            name="artist_payee_id"
                                            defaultValue={
                                                agreement?.artist_payee_id
                                            }
                                            placeholder="Not a party"
                                            required={licensor === 'artist'}
                                        />
                                    </Field>
                                }
                                <Field
                                    label="Payee"
                                    htmlFor="payee_id"
                                    error={errors.payee_id}
                                    required
                                    hint="The other party: the remixer, or the artist of a release."
                                >
                                    <RecordSelect
                                        id="payee_id"
                                        records="payees"
                                        name="payee_id"
                                        value={payeeId}
                                        onChange={setPayeeId}
                                        placeholder="Choose payee…"
                                        required
                                    />
                                </Field>
                            </div>
                        </Panel>

                        <Panel
                            title="What it is about"
                            description="The releases the agreement covers. Of each release it covers everything, or only the tracks you tick: on a remix release, the one or two remixes this deal is for."
                        >
                            <CoverageFields
                                initial={agreement?.coverage ?? []}
                                params={{ payee_id: payeeId }}
                            />
                            {(errors.release_ids ??
                                Object.entries(errors).find(([key]) =>
                                    /^(release|track)_ids/.test(key),
                                )?.[1]) && (
                                <p className="mt-2 text-sm text-red-600">
                                    {errors.release_ids ??
                                        Object.entries(errors).find(([key]) =>
                                            /^(release|track)_ids/.test(key),
                                        )?.[1]}
                                </p>
                            )}
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
