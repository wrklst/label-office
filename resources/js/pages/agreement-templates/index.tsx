import { Pencil, Plus } from 'lucide-react';
import AgreementTemplateController from '@/actions/App/Http/Controllers/AgreementTemplateController';
import { DeleteButton } from '@/components/delete-button';
import { CheckField, Field } from '@/components/field';
import { FormDialog } from '@/components/form-dialog';
import { Notice } from '@/components/notice';
import { EmptyState, Page, Panel } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/agreement-templates';
import { date } from '@/lib/format';

type Template = {
    id: number;
    name: string;
    body: string;
    updated_at: string | null;
};

type Example = { key: string; name: string; body: string };

function TemplateFields({
    template,
    fields,
    errors,
}: {
    template?: Template;
    fields: Record<string, string>;
    errors: Record<string, string>;
}) {
    return (
        <>
            <Field label="Name" htmlFor="name" error={errors.name} required>
                <Input
                    id="name"
                    name="name"
                    defaultValue={template?.name}
                    placeholder="Remix agreement"
                    required
                />
            </Field>
            <Field
                label="Text"
                htmlFor="body"
                error={errors.body}
                required
                hint="An agreement keeps the text as it was when it was sent: changing a template never changes an agreement that is out for signature or signed."
            >
                <Textarea
                    id="body"
                    name="body"
                    rows={18}
                    className="font-mono text-xs"
                    defaultValue={template?.body}
                    required
                />
            </Field>
            <details className="text-sm">
                <summary className="cursor-pointer font-medium">
                    Fields an agreement fills in
                </summary>
                <dl className="mt-2 grid gap-1 text-xs">
                    {Object.entries(fields).map(([key, meaning]) => (
                        <div key={key} className="flex gap-2">
                            <dt className="shrink-0 font-mono">{`{{ ${key} }}`}</dt>
                            <dd className="text-muted-foreground">{meaning}</dd>
                        </div>
                    ))}
                </dl>
            </details>
        </>
    );
}

export default function AgreementTemplates({
    templates,
    examples,
    notice,
    disclaimer,
    fields,
}: {
    templates: Template[];
    examples: Example[];
    notice: string;
    disclaimer: string;
    fields: Record<string, string>;
}) {
    return (
        <Page
            title="Agreement templates"
            description="The texts your agreements are made from. A template is yours: you answer for what it says. The application gives no legal advice."
            actions={
                <FormDialog
                    title="New template"
                    form={AgreementTemplateController.store.form()}
                    submitLabel="Create template"
                    trigger={
                        <Button>
                            <Plus />
                            New template
                        </Button>
                    }
                >
                    {(errors) => (
                        <TemplateFields fields={fields} errors={errors} />
                    )}
                </FormDialog>
            }
        >
            <Notice id="legal">{disclaimer}</Notice>
            {templates.length === 0 ? (
                <EmptyState
                    title="No templates yet"
                    description="Write your own, or copy one of the examples below and have a lawyer adapt it."
                />
            ) : (
                <Panel flush>
                    <ul className="divide-y text-sm">
                        {templates.map((template) => (
                            <li
                                key={template.id}
                                className="flex items-center justify-between gap-3 px-4 py-2"
                            >
                                <span className="min-w-0">
                                    <span className="font-medium">
                                        {template.name}
                                    </span>
                                    {template.updated_at && (
                                        <span className="block text-xs text-muted-foreground">
                                            Changed {date(template.updated_at)}
                                        </span>
                                    )}
                                </span>
                                <span className="flex">
                                    <FormDialog
                                        title={`Edit ${template.name}`}
                                        form={AgreementTemplateController.update.form(
                                            template.id,
                                        )}
                                        trigger={
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Edit ${template.name}`}
                                            >
                                                <Pencil className="text-muted-foreground" />
                                            </Button>
                                        }
                                    >
                                        {(errors) => (
                                            <TemplateFields
                                                template={template}
                                                fields={fields}
                                                errors={errors}
                                            />
                                        )}
                                    </FormDialog>
                                    <DeleteButton
                                        url={AgreementTemplateController.destroy.url(
                                            template.id,
                                        )}
                                        title={`Delete ${template.name}?`}
                                        description="The template is removed. Agreements already made from it keep their text."
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            <Panel
                title="Examples"
                description="Illustrations of how a template can look. Nothing is ever sent from an example: copy one into your templates to work on it."
            >
                <div className="grid gap-4">
                    <Notice id="examples">{notice}</Notice>
                    <ul className="divide-y rounded-lg border text-sm">
                        {examples.map((example) => (
                            <li
                                key={example.key}
                                className="flex flex-wrap items-center justify-between gap-3 px-3 py-2"
                            >
                                <span className="font-medium">
                                    {example.name}
                                </span>
                                <span className="flex gap-2">
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button variant="ghost" size="sm">
                                                Read
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Example: {example.name}
                                                </DialogTitle>
                                            </DialogHeader>
                                            <Notice>{notice}</Notice>
                                            <pre className="font-sans text-sm whitespace-pre-wrap">
                                                {example.body}
                                            </pre>
                                        </DialogContent>
                                    </Dialog>
                                    <FormDialog
                                        title={`Copy "${example.name}" to your templates`}
                                        form={AgreementTemplateController.store.form()}
                                        submitLabel="Copy to my templates"
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                Copy to my templates
                                            </Button>
                                        }
                                    >
                                        {(errors) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="example"
                                                    value={example.key}
                                                />
                                                <Notice>{notice}</Notice>
                                                <CheckField
                                                    name="reviewed"
                                                    value="1"
                                                    label="I understand that this is an unreviewed example"
                                                    hint="The copy becomes my own template. I take responsibility for its text and will have it reviewed and adapted by a lawyer before I use it."
                                                />
                                                {errors.reviewed && (
                                                    <p className="text-sm text-red-600">
                                                        {errors.reviewed}
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </FormDialog>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </Panel>
        </Page>
    );
}

AgreementTemplates.layout = {
    breadcrumbs: [{ title: 'Agreement templates', href: index() }],
};
