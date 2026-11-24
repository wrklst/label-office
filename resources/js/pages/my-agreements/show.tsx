import { Form, router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import MyAgreementController from '@/actions/App/Http/Controllers/MyAgreementController';
import {
    AgreementDocument,
    AgreementStatus,
    capacityName,
} from '@/components/agreement-document';
import type { AgreementLine } from '@/components/agreement-document';
import { CheckField, Field, SelectInput } from '@/components/field';
import { Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/my-agreements';

type Agreement = {
    id: number;
    name: string;
    status: string;
    body_hash: string | null;
    label: string | null;
    text: string;
    sent_at: string | null;
    signed_at: string | null;
    lines: AgreementLine[];
    capacities: string[];
    blocked: string[];
    is_admin: boolean;
};

export default function MyAgreement({ agreement }: { agreement: Agreement }) {
    const { auth } = usePage().props;

    return (
        <Page
            title={agreement.name}
            description={`An agreement with ${agreement.label ?? 'a label'}. Read all of it before you sign. Nothing is agreed until every party has signed.`}
            actions={
                <>
                    <AgreementStatus status={agreement.status} />
                    <Button variant="outline" asChild>
                        <a href={MyAgreementController.pdf.url(agreement.id)}>
                            <Download />
                            PDF
                        </a>
                    </Button>
                </>
            }
        >
            <AgreementDocument
                text={agreement.text}
                hash={agreement.body_hash}
                lines={agreement.lines}
            />

            {agreement.blocked.map((reason) => (
                <p key={reason} className="text-sm text-muted-foreground">
                    {reason}
                </p>
            ))}

            {agreement.capacities.length > 0 && (
                <div className="print:hidden">
                    <Panel
                        title="Sign"
                        description="Your typed name, your confirmation and the code we email you are together your signature. It is recorded with the time and your internet address."
                    >
                        <Form
                            {...MyAgreementController.sign.form(agreement.id)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="grid gap-6"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="body_hash"
                                        value={agreement.body_hash ?? ''}
                                    />
                                    {agreement.capacities.length > 1 ? (
                                        <Field
                                            label="I sign"
                                            htmlFor="capacity"
                                            error={errors.capacity}
                                            required
                                        >
                                            <SelectInput
                                                id="capacity"
                                                name="capacity"
                                                className="w-64"
                                            >
                                                {agreement.capacities.map(
                                                    (capacity) => (
                                                        <option
                                                            key={capacity}
                                                            value={capacity}
                                                        >
                                                            {capacity ===
                                                            'label'
                                                                ? capacityName(
                                                                      capacity,
                                                                  )
                                                                : `as the ${capacityName(capacity)}`}
                                                        </option>
                                                    ),
                                                )}
                                            </SelectInput>
                                        </Field>
                                    ) : (
                                        <>
                                            <input
                                                type="hidden"
                                                name="capacity"
                                                value={agreement.capacities[0]}
                                            />
                                            <p className="text-sm">
                                                You sign{' '}
                                                <span className="font-medium">
                                                    {agreement.capacities[0] ===
                                                    'label'
                                                        ? `for ${agreement.label}`
                                                        : `as the ${capacityName(agreement.capacities[0])}`}
                                                </span>
                                                .
                                            </p>
                                            {errors.capacity && (
                                                <p className="text-sm text-red-600">
                                                    {errors.capacity}
                                                </p>
                                            )}
                                        </>
                                    )}
                                    <Field
                                        label="Your full name"
                                        htmlFor="name"
                                        error={errors.name}
                                        required
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={auth.user?.name}
                                            autoComplete="name"
                                            required
                                        />
                                    </Field>
                                    <div>
                                        <CheckField
                                            name="consent"
                                            value="1"
                                            label="I have read this agreement and want to be bound by it"
                                            hint="I agree to sign it electronically."
                                            required
                                        />
                                        {(errors.consent ??
                                            errors.body_hash) && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.consent ??
                                                    errors.body_hash}
                                            </p>
                                        )}
                                    </div>
                                    <Field
                                        label="Code from your email"
                                        htmlFor="code"
                                        error={errors.code}
                                        required
                                        hint={`Sent to ${auth.user?.email}. It works once and for 15 minutes.`}
                                    >
                                        <div className="flex gap-2">
                                            <Input
                                                id="code"
                                                name="code"
                                                inputMode="numeric"
                                                autoComplete="one-time-code"
                                                className="w-32 font-mono"
                                                required
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        MyAgreementController.code.url(
                                                            agreement.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                            preserveState: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Email me a code
                                            </Button>
                                        </div>
                                    </Field>
                                    <div>
                                        <Button disabled={processing}>
                                            Sign
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </Panel>
                </div>
            )}
        </Page>
    );
}

MyAgreement.layout = {
    breadcrumbs: [{ title: 'My agreements', href: index() }],
};
