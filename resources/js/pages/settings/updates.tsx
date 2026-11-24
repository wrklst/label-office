import { Form, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import UpdatesController from '@/actions/App/Http/Controllers/Settings/UpdatesController';
import AlertError from '@/components/alert-error';
import { CheckField, Field, SelectInput } from '@/components/field';
import { Page, Panel } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/updates';
import { dateTime } from '@/lib/format';

type Last = {
    tag: string;
    from: string;
    status: 'running' | 'ok' | 'failed' | 'broken' | 'refused';
    at: string;
    message?: string;
};

type Settings = {
    auto: boolean;
    hour: number;
    requested: boolean;
    last: Last | null;
};

const outcome: Record<Last['status'], string> = {
    running: 'Running',
    ok: 'Installed',
    failed: 'Failed, previous version restored',
    broken: 'Failed, and going back failed too',
    refused: 'Not started',
};

function LastUpdate({ last }: { last: Last }) {
    return (
        <div className="rounded-lg border p-3 text-sm">
            <p className="flex flex-wrap items-center gap-2">
                <Badge
                    variant={
                        last.status === 'ok'
                            ? 'success'
                            : last.status === 'running'
                              ? 'warning'
                              : 'destructive'
                    }
                >
                    {outcome[last.status]}
                </Badge>
                <span>
                    {last.tag ? `${last.from} → ${last.tag}` : 'Update'},{' '}
                    {dateTime(last.at)}
                </span>
            </p>
            {(last.message ||
                last.status === 'failed' ||
                last.status === 'broken') && (
                <p className="mt-2 text-muted-foreground">
                    {last.message ??
                        'What went wrong is in storage/logs/update.log on the server.'}
                </p>
            )}
        </div>
    );
}

export default function Updates({
    current,
    latest,
    available,
    obstacle,
    settings,
    serverTime,
}: {
    current: string;
    latest: string | null;
    available: boolean;
    obstacle: string | null;
    settings: Settings;
    serverTime: string;
}) {
    const waiting = settings.requested || settings.last?.status === 'running';

    // While an update is on its way the page finds out by itself when it is through.
    // Started and stopped as it changes: autoStart alone is only read when the page is first shown.
    const { start, stop } = usePoll(5000, {}, { autoStart: false });

    useEffect(() => {
        if (waiting) {
            start();
        } else {
            stop();
        }
    }, [waiting, start, stop]);

    return (
        <Page
            title="Updates"
            description="The version this installation runs, and how it gets new ones."
            className="p-0 md:p-0"
        >
            <Panel
                title="Version"
                description="What runs now and what is available."
            >
                <div className="space-y-6">
                    <p className="flex flex-wrap items-center gap-2 text-sm">
                        <span>
                            Running <strong>{current}</strong>
                        </span>
                        {obstacle ? (
                            <Badge variant="warning">
                                Cannot update itself
                            </Badge>
                        ) : available ? (
                            <Badge variant="warning">
                                {latest} is available
                            </Badge>
                        ) : (
                            <Badge variant="success">Up to date</Badge>
                        )}
                    </p>

                    {obstacle && (
                        <p className="text-sm text-muted-foreground">
                            {obstacle}
                        </p>
                    )}

                    {settings.last && <LastUpdate last={settings.last} />}

                    {!obstacle && (
                        <div className="flex flex-wrap items-center gap-3">
                            {available && (
                                <Form
                                    {...UpdatesController.install.form()}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            {errors.update && (
                                                <AlertError
                                                    title="Update"
                                                    errors={[errors.update]}
                                                />
                                            )}
                                            <Button
                                                disabled={processing || waiting}
                                            >
                                                {waiting
                                                    ? 'Update is on its way…'
                                                    : `Update to ${latest}`}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            )}
                            <Form
                                {...UpdatesController.check.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {processing ? 'Asking…' : 'Check again'}
                                    </Button>
                                )}
                            </Form>
                        </div>
                    )}

                    <p className="text-sm text-muted-foreground">
                        An update backs up the database, puts the site in
                        maintenance mode for a few minutes, installs the new
                        version and goes back to the previous one by itself when
                        a step fails. Only versions the repository has tagged
                        are installed.
                    </p>
                </div>
            </Panel>

            {!obstacle && (
                <Panel
                    title="Automatic updates"
                    description="Install each new version without being asked."
                >
                    <Form
                        {...UpdatesController.update.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                <input type="hidden" name="auto" value="0" />
                                <CheckField
                                    name="auto"
                                    value="1"
                                    label="Install new versions automatically"
                                    hint="A version that failed is not tried again by itself."
                                    defaultChecked={settings.auto}
                                />
                                <Field
                                    label="At"
                                    htmlFor="hour"
                                    error={errors.hour}
                                    hint={`Server time; it is ${serverTime} there now.`}
                                >
                                    <SelectInput
                                        id="hour"
                                        name="hour"
                                        className="w-28"
                                        defaultValue={settings.hour}
                                    >
                                        {Array.from(
                                            { length: 24 },
                                            (_, hour) => (
                                                <option key={hour} value={hour}>
                                                    {String(hour).padStart(
                                                        2,
                                                        '0',
                                                    )}
                                                    :00
                                                </option>
                                            ),
                                        )}
                                    </SelectInput>
                                </Field>
                                <div>
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

Updates.layout = {
    breadcrumbs: [{ title: 'Updates', href: edit() }],
};
