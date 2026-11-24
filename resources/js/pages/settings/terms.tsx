import { Form, usePage } from '@inertiajs/react';
import NoticeController from '@/actions/App/Http/Controllers/NoticeController';
import TermsController from '@/actions/App/Http/Controllers/Settings/TermsController';
import { CheckField } from '@/components/field';
import { Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { show } from '@/routes/terms';

type Props = {
    html: string;
    version: string;
    accepted: boolean;
    acceptance: {
        version: string;
        name: string;
        email: string;
        at: string;
    } | null;
};

export default function Terms({ html, version, accepted, acceptance }: Props) {
    const hidden = usePage().props.auth.user?.hidden_notices ?? [];

    return (
        <Page
            title="Terms of use"
            description="On what footing the software is given to you. The agreement tools open once an admin has accepted these terms for the label."
            className="p-0 md:p-0"
        >
            <Panel
                title={accepted ? 'Accepted' : 'Accept the terms for the label'}
                description={
                    accepted && acceptance
                        ? `${acceptance.name} (${acceptance.email}) accepted version ${acceptance.version} for the label on ${dateTime(acceptance.at)}.`
                        : acceptance
                          ? `The terms have changed since ${acceptance.name} accepted version ${acceptance.version}. Read them again below.`
                          : 'Read the terms below first. Clear the use of the software with your legal adviser before you accept.'
                }
            >
                {!accepted && (
                    <Form
                        {...TermsController.store.form()}
                        className="grid gap-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <CheckField
                                    name="accepted"
                                    value="1"
                                    label="I accept these terms for the label"
                                    hint={`Version ${version}. I have read them, I am entitled to accept them for the label, and I understand that the software guarantees no legal compliance and that its makers are not liable for damage from its use as far as the law allows.`}
                                    required
                                />
                                {errors.accepted && (
                                    <p className="text-sm text-red-600">
                                        {errors.accepted}
                                    </p>
                                )}
                                <div>
                                    <Button disabled={processing}>
                                        Accept
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </Panel>

            {hidden.length > 0 && (
                <Panel
                    title="Notices"
                    description="You have acknowledged and hidden the standing notices about legal guarantees. They can be shown again at any time."
                >
                    <Form {...NoticeController.restore.form()}>
                        {({ processing }) => (
                            <Button variant="outline" disabled={processing}>
                                Show the notices again
                            </Button>
                        )}
                    </Form>
                </Panel>
            )}

            <Panel title={`The terms, version ${version}`}>
                <div
                    className="handbook-prose"
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </Panel>
        </Page>
    );
}

Terms.layout = { breadcrumbs: [{ title: 'Terms of use', href: show() }] };
