import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TeamController from '@/actions/App/Http/Controllers/Settings/TeamController';
import { DeleteButton } from '@/components/delete-button';
import { Field, SelectInput } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import { Page, Panel } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/team';
import type { Option } from '@/types';

type Member = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'payee';
    payee_id: number | null;
    signs_as_payee_id: number | null;
    payee: string | null;
};

function RoleFields({
    member,
    payeeOptions,
    errors,
}: {
    member?: Member;
    payeeOptions: Option[];
    errors: Record<string, string>;
}) {
    const [role, setRole] = useState<'admin' | 'payee'>(
        member?.role ?? 'payee',
    );

    return (
        <>
            <Field
                label="Access"
                htmlFor={`role-${member?.id ?? 'new'}`}
                error={errors.role}
                required
                hint={
                    role === 'admin'
                        ? 'Admins see and change everything in this label.'
                        : 'A payee only sees their own published statements.'
                }
            >
                <SelectInput
                    id={`role-${member?.id ?? 'new'}`}
                    name="role"
                    value={role}
                    onChange={(event) =>
                        setRole(event.target.value as 'admin' | 'payee')
                    }
                >
                    <option value="payee">Payee – own statements only</option>
                    <option value="admin">Admin – full access</option>
                </SelectInput>
            </Field>
            {role === 'payee' && (
                <Field
                    label="Payee"
                    htmlFor={`payee-${member?.id ?? 'new'}`}
                    error={errors.payee_id}
                    required
                    hint="Whose statements this person may see."
                >
                    <SelectInput
                        id={`payee-${member?.id ?? 'new'}`}
                        name="payee_id"
                        defaultValue={member?.payee_id ?? ''}
                        options={payeeOptions}
                        placeholder="Choose payee…"
                        required
                    />
                </Field>
            )}
            {role === 'admin' && (
                <Field
                    label="Signs agreements as"
                    htmlFor={`signs-as-${member?.id ?? 'new'}`}
                    error={errors.signs_as_payee_id}
                    hint="Only when this admin is also an artist of the label: the payee they sign their own agreements as."
                >
                    <SelectInput
                        id={`signs-as-${member?.id ?? 'new'}`}
                        name="signs_as_payee_id"
                        defaultValue={member?.signs_as_payee_id ?? ''}
                        options={payeeOptions}
                        placeholder="Nobody"
                    />
                </Field>
            )}
        </>
    );
}

export default function Team({
    members,
    payeeOptions,
}: {
    members: Member[];
    payeeOptions: Option[];
}) {
    const { auth, label } = usePage().props;

    return (
        <Page
            title="Team"
            description={`Who can sign in to ${label?.name ?? 'this label'}.`}
        >
            <Panel flush>
                <ul className="divide-y text-sm">
                    {members.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-wrap items-center justify-between gap-3 px-3 py-2"
                        >
                            <span className="min-w-0">
                                <span className="font-medium">
                                    {member.name}
                                </span>
                                {member.id === auth.user.id && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        (you)
                                    </span>
                                )}
                                <span className="block truncate text-xs text-muted-foreground">
                                    {member.email}
                                </span>
                            </span>
                            <span className="flex items-center gap-1">
                                <Badge
                                    variant={
                                        member.role === 'admin'
                                            ? 'info'
                                            : 'outline'
                                    }
                                >
                                    {member.role === 'admin'
                                        ? 'Admin'
                                        : `Payee · ${member.payee ?? 'not linked'}`}
                                </Badge>
                                <FormDialog
                                    title={`Access for ${member.name}`}
                                    form={TeamController.update.form(member.id)}
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            Change
                                        </Button>
                                    }
                                >
                                    {(errors) => (
                                        <RoleFields
                                            member={member}
                                            payeeOptions={payeeOptions}
                                            errors={errors}
                                        />
                                    )}
                                </FormDialog>
                                <DeleteButton
                                    url={TeamController.destroy.url(member.id)}
                                    confirmLabel="Remove access"
                                    title={`Remove ${member.name} from ${label?.name ?? 'this label'}?`}
                                    triggerLabel={`Remove ${member.name}`}
                                    description={`They are removed from this label and can no longer sign in to ${label?.name ?? 'this label'}. Their account and access to other labels stay.`}
                                />
                            </span>
                        </li>
                    ))}
                </ul>
            </Panel>

            <Panel
                title="Add a person"
                description="Someone without an account gets one, plus an email that leads to signing in with a one-time code."
            >
                <Form
                    {...TeamController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                label="Email"
                                htmlFor="email"
                                error={errors.email}
                                required
                            >
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    required
                                />
                            </Field>
                            <Field
                                label="Name"
                                htmlFor="member_name"
                                error={errors.name}
                                hint="Only used when a new account is created."
                            >
                                <Input id="member_name" name="name" />
                            </Field>
                            <RoleFields
                                payeeOptions={payeeOptions}
                                errors={errors}
                            />
                            <div>
                                <Button disabled={processing}>
                                    Add to label
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Panel>
        </Page>
    );
}

Team.layout = { breadcrumbs: [{ title: 'Team', href: index() }] };
