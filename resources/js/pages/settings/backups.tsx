import { Form, Link, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import BackupsController from '@/actions/App/Http/Controllers/Settings/BackupsController';
import AlertError from '@/components/alert-error';
import { CheckField, Field, SelectInput } from '@/components/field';
import { Notice, Page, Panel } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { count, dateTime } from '@/lib/format';
import { edit } from '@/routes/backups';
import { edit as editConnections } from '@/routes/connections';

type Last = {
    status: 'running' | 'ok' | 'failed';
    at: string;
    name?: string;
    files?: number;
    copied?: number;
    message?: string;
};

type Tier = 'daily' | 'weekly' | 'monthly' | 'yearly';

type Props = {
    available: boolean;
    settings: {
        enabled: boolean;
        hour: number;
        keep: Record<Tier, number>;
        requested: boolean;
        last: Last | null;
    };
    bucket: {
        bucket: string | null;
        endpoint: string | null;
        region: string | null;
        key_hint: string;
    } | null;
    filesBucket: string | null;
    backups: {
        items: {
            name: string;
            made_at: string;
            files: number;
            copied: number;
        }[];
        error: string | null;
    };
    serverTime: string;
};

const TIERS: [Tier, string, string][] = [
    ['daily', 'Daily', 'Every night that is not the first of its week.'],
    ['weekly', 'Weekly', 'The first backup of a week, from Monday.'],
    ['monthly', 'Monthly', 'The first backup of a month.'],
    ['yearly', 'Yearly', 'The first backup of a year.'],
];

const outcome: Record<Last['status'], string> = {
    running: 'Running',
    ok: 'Made',
    failed: 'Failed',
};

function LastBackup({ last }: { last: Last }) {
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
                    {last.name ?? 'Backup'}, {dateTime(last.at)}
                </span>
            </p>
            {last.status === 'ok' && (
                <p className="mt-2 text-muted-foreground">
                    The database and {count(last.files)} files,{' '}
                    {count(last.copied)} of them new or changed.
                </p>
            )}
            {last.message && (
                <p className="mt-2 text-muted-foreground">{last.message}</p>
            )}
        </div>
    );
}

function OwnBucket({
    bucket,
    filesBucket,
}: Pick<Props, 'bucket' | 'filesBucket'>) {
    const [open, setOpen] = useState(false);

    if (bucket) {
        return (
            <div className="space-y-4 text-sm">
                <p>
                    Backups go to their own bucket{' '}
                    <strong>{bucket.bucket}</strong> (
                    {bucket.endpoint ?? 'Amazon S3'}
                    {bucket.region ? `, ${bucket.region}` : ''}, access key{' '}
                    {bucket.key_hint}).
                </p>
                <Form
                    {...BackupsController.destroyBucket.form()}
                    options={{ preserveScroll: true }}
                    onBefore={() =>
                        window.confirm(
                            'New backups go to the bucket for files again. The backups in this bucket stay there, but are no longer listed or cleaned up.',
                        )
                    }
                >
                    {({ processing }) => (
                        <Button variant="outline" disabled={processing}>
                            Disconnect this bucket
                        </Button>
                    )}
                </Form>
            </div>
        );
    }

    return (
        <div className="space-y-4 text-sm">
            <p>
                {filesBucket ? (
                    <>
                        Backups go into the folder <code>backups</code> of the
                        bucket for files, <strong>{filesBucket}</strong>.
                    </>
                ) : (
                    'No bucket is connected.'
                )}{' '}
                A bucket of their own is the safer place: a backup beside the
                files is lost with them when the bucket or its access key is.
            </p>
            {!open && (
                <Button variant="outline" onClick={() => setOpen(true)}>
                    Connect a bucket for backups
                </Button>
            )}
            {open && (
                <Form
                    {...BackupsController.bucket.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                label="Bucket"
                                htmlFor="backup_bucket"
                                error={errors.bucket}
                                required
                            >
                                <Input
                                    id="backup_bucket"
                                    name="bucket"
                                    autoComplete="off"
                                    required
                                />
                            </Field>
                            <Field
                                label="Endpoint"
                                htmlFor="backup_endpoint"
                                error={errors.endpoint}
                                hint="For every provider other than Amazon, e.g. https://hel1.your-objectstorage.com."
                            >
                                <Input
                                    id="backup_endpoint"
                                    name="endpoint"
                                    type="url"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label="Region"
                                htmlFor="backup_region"
                                error={errors.region}
                            >
                                <Input
                                    id="backup_region"
                                    name="region"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label="Access key"
                                htmlFor="backup_key"
                                error={errors.key}
                                required
                            >
                                <Input
                                    id="backup_key"
                                    name="key"
                                    autoComplete="off"
                                    required
                                />
                            </Field>
                            <Field
                                label="Secret key"
                                htmlFor="backup_secret"
                                error={errors.secret}
                                required
                            >
                                <Input
                                    id="backup_secret"
                                    name="secret"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                />
                            </Field>
                            <CheckField
                                name="path_style"
                                value="1"
                                label="Path-style addresses"
                                hint="Needed by some self-hosted storage such as MinIO."
                            />
                            <div className="flex gap-2">
                                <Button disabled={processing}>
                                    {processing
                                        ? 'Testing the bucket…'
                                        : 'Test and connect'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

export default function Backups({
    available,
    settings,
    bucket,
    filesBucket,
    backups,
    serverTime,
}: Props) {
    const waiting = settings.requested || settings.last?.status === 'running';

    // While a backup is on its way the page finds out by itself when it is through.
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
            title="Backups"
            description="The database and the files of every label, copied to a bucket every night."
            className="p-0 md:p-0"
        >
            {!available && (
                <Notice
                    title="No backups are made"
                    action={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={editConnections()}>Connections</Link>
                        </Button>
                    }
                >
                    <p>
                        Backups need an S3-compatible bucket. Connect one for
                        the files under Connections, or one for the backups
                        alone below.
                    </p>
                </Notice>
            )}

            {available && (
                <Panel
                    title="Last backup"
                    description="What the last run did, and a backup on demand."
                >
                    <div className="space-y-6">
                        {settings.last ? (
                            <LastBackup last={settings.last} />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No backup has been made yet.
                            </p>
                        )}
                        <Form
                            {...BackupsController.run.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ errors, processing }) => (
                                <>
                                    {errors.backup && (
                                        <AlertError
                                            title="Backup"
                                            errors={[errors.backup]}
                                        />
                                    )}
                                    <Button disabled={processing || waiting}>
                                        {waiting
                                            ? 'Backup is on its way…'
                                            : 'Back up now'}
                                    </Button>
                                </>
                            )}
                        </Form>
                        <p className="text-sm text-muted-foreground">
                            A backup holds the database as a dump and the files
                            that are new or changed since the backup before it;
                            the first one holds them all. The server&apos;s
                            environment file is never part of it: keep a copy
                            yourself.
                        </p>
                    </div>
                </Panel>
            )}

            <Panel
                title="Schedule and what is kept"
                description="When the nightly backup runs, and how many backups stay before the oldest are deleted."
            >
                <Form
                    {...BackupsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="grid gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <input type="hidden" name="enabled" value="0" />
                            <CheckField
                                name="enabled"
                                value="1"
                                label="Make a backup every night"
                                hint="Without it a backup is made only when you ask for one."
                                defaultChecked={settings.enabled}
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
                                    {Array.from({ length: 24 }, (_, hour) => (
                                        <option key={hour} value={hour}>
                                            {String(hour).padStart(2, '0')}:00
                                        </option>
                                    ))}
                                </SelectInput>
                            </Field>
                            <div className="grid gap-6 sm:grid-cols-2">
                                {TIERS.map(([tier, label, hint]) => (
                                    <Field
                                        key={tier}
                                        label={`${label} backups kept`}
                                        htmlFor={`keep_${tier}`}
                                        error={errors[`keep.${tier}`]}
                                        hint={hint}
                                    >
                                        <Input
                                            id={`keep_${tier}`}
                                            name={`keep[${tier}]`}
                                            type="number"
                                            min={1}
                                            max={99}
                                            className="w-28"
                                            defaultValue={settings.keep[tier]}
                                            required
                                        />
                                    </Field>
                                ))}
                            </div>
                            <div>
                                <Button disabled={processing}>Save</Button>
                            </div>
                        </>
                    )}
                </Form>
            </Panel>

            <Panel
                title="Where backups go"
                description="The bucket for files, or a bucket of their own."
            >
                <OwnBucket bucket={bucket} filesBucket={filesBucket} />
            </Panel>

            {available && (
                <Panel
                    title="Backups in the bucket"
                    description="Newest first. To get one back, run php artisan royalties:assemble-backup on the server."
                    flush
                >
                    {backups.error ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            The bucket could not be read: {backups.error}
                        </p>
                    ) : backups.items.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            None yet.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Backup</TableHead>
                                    <TableHead>Made</TableHead>
                                    <TableHead className="text-right">
                                        Files
                                    </TableHead>
                                    <TableHead className="text-right">
                                        New or changed
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {backups.items.map((backup) => (
                                    <TableRow key={backup.name}>
                                        <TableCell className="font-mono text-xs">
                                            {backup.name}
                                        </TableCell>
                                        <TableCell>
                                            {dateTime(backup.made_at)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {count(backup.files)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {count(backup.copied)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            )}
        </Page>
    );
}

Backups.layout = {
    breadcrumbs: [{ title: 'Backups', href: edit() }],
};
