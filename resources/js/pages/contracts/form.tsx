import { Link, setLayoutProps, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import ContractController from '@/actions/App/Http/Controllers/ContractController';
import { Field } from '@/components/field';
import InputError from '@/components/input-error';
import { Page, Panel } from '@/components/page';
import { RecordSelect } from '@/components/record-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { index, show } from '@/routes/contracts';
import { percent } from '@/lib/format';

type Split = {
    payee_id: number | '';
    ledger_id: number | '';
    territory_id: number | '';
    percentage: string;
};
type Child = { contract_id: number | ''; weight: string };

type Contract = {
    id: number;
    name: string;
    memo: string | null;
    splits: Split[];
    children: Child[];
};

type Props = {
    contract: Contract | null;
    /** The page the form was opened from, where saving returns to. */
    returnTo: string | null;
};

export default function ContractForm({ contract, returnTo }: Props) {
    const form = useForm({
        return_to: returnTo ?? '',
        name: contract?.name ?? '',
        memo: contract?.memo ?? '',
        splits: (contract?.splits ?? []).map((split) => ({
            payee_id: split.payee_id ?? '',
            ledger_id: split.ledger_id ?? '',
            territory_id: split.territory_id ?? '',
            percentage: split.percentage,
        })) as Split[],
        children: (contract?.children ?? []) as Child[],
    });

    const errors = form.errors as Record<string, string>;
    const prorated = form.data.children.length > 0;
    const worldTotal = form.data.splits
        .filter((split) => split.territory_id === '')
        .reduce((sum, split) => sum + (Number(split.percentage) || 0), 0);
    const weightTotal = form.data.children.reduce(
        (sum, child) => sum + (Number(child.weight) || 0),
        0,
    );

    const setSplit = (position: number, changes: Partial<Split>) =>
        form.setData(
            'splits',
            form.data.splits.map((split, current) =>
                current === position ? { ...split, ...changes } : split,
            ),
        );
    const setChild = (position: number, changes: Partial<Child>) =>
        form.setData(
            'children',
            form.data.children.map((child, current) =>
                current === position ? { ...child, ...changes } : child,
            ),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (contract) {
            form.put(ContractController.update.url(contract.id), {
                preserveScroll: true,
            });
        } else {
            form.post(ContractController.store.url());
        }
    };

    setLayoutProps({
        breadcrumbs: [
            { title: 'Contracts', href: index() },
            ...(contract
                ? [
                      { title: contract.name, href: show(contract.id) },
                      { title: 'Edit', href: '#' },
                  ]
                : [{ title: 'New contract', href: '#' }]),
        ],
    });

    return (
        <Page
            title={contract ? `Edit ${contract.name}` : 'New contract'}
            description="Splits are percentages of net income: income minus the expenses booked on the same release or track."
        >
            <form onSubmit={submit} className="grid max-w-4xl gap-6">
                <Panel>
                    <div className="grid gap-4">
                        <Field
                            label="Name"
                            htmlFor="name"
                            error={errors.name}
                            required
                            hint='Name it after the deal, e.g. "Artist – Album deal 50/50" or "Remix: Remixer name".'
                        >
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                        </Field>
                        <Field label="Memo" htmlFor="memo" error={errors.memo}>
                            <Textarea
                                id="memo"
                                value={form.data.memo}
                                onChange={(event) =>
                                    form.setData('memo', event.target.value)
                                }
                                placeholder="Term, recoupable costs, anything worth remembering about the deal"
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel
                    title="Splits"
                    description={
                        prorated
                            ? 'Ignored while this contract has child contracts below.'
                            : `Payees get ${percent(worldTotal / 100)} worldwide, the label keeps ${percent((100 - worldTotal) / 100)}.`
                    }
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('splits', [
                                    ...form.data.splits,
                                    {
                                        payee_id: '',
                                        ledger_id: '',
                                        territory_id: '',
                                        percentage: '',
                                    },
                                ])
                            }
                        >
                            <Plus />
                            Add split
                        </Button>
                    }
                >
                    {form.data.splits.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No splits: the label keeps everything booked on this
                            contract.
                        </p>
                    ) : (
                        <div className="grid gap-3">
                            <div className="hidden grid-cols-[1fr_7rem_1fr_1fr_2.25rem] gap-2 text-xs font-medium text-muted-foreground md:grid">
                                <span>Payee</span>
                                <span>Split %</span>
                                <span>Ledger</span>
                                <span>Territory</span>
                                <span />
                            </div>
                            {form.data.splits.map((split, position) => (
                                <div
                                    key={position}
                                    className="grid gap-2 md:grid-cols-[1fr_7rem_1fr_1fr_2.25rem]"
                                >
                                    <div>
                                        <RecordSelect
                                            aria-label="Payee"
                                            records="payees"
                                            value={split.payee_id}
                                            onChange={(id) =>
                                                setSplit(position, {
                                                    payee_id: id,
                                                })
                                            }
                                            placeholder="Choose payee…"
                                            required
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `splits.${position}.payee_id`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Input
                                            aria-label="Split percent"
                                            type="number"
                                            step="any"
                                            min="0"
                                            max="100"
                                            value={split.percentage}
                                            onChange={(event) =>
                                                setSplit(position, {
                                                    percentage:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="50"
                                            required
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `splits.${position}.percentage`
                                                ]
                                            }
                                        />
                                    </div>
                                    <RecordSelect
                                        aria-label="Ledger"
                                        records="ledgers"
                                        value={split.ledger_id}
                                        onChange={(id) =>
                                            setSplit(position, {
                                                ledger_id: id,
                                            })
                                        }
                                        placeholder="Primary ledger"
                                    />
                                    <RecordSelect
                                        aria-label="Territory"
                                        records="territories"
                                        value={split.territory_id}
                                        onChange={(id) =>
                                            setSplit(position, {
                                                territory_id: id,
                                            })
                                        }
                                        placeholder="Worldwide"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove split"
                                        onClick={() =>
                                            form.setData(
                                                'splits',
                                                form.data.splits.filter(
                                                    (_, current) =>
                                                        current !== position,
                                                ),
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            <InputError message={errors.splits} />
                            {worldTotal > 100 + 1e-9 && (
                                <p className="text-sm text-red-600 dark:text-red-400">
                                    The worldwide splits add up to more than
                                    100%. The label would pay out more than it
                                    earns.
                                </p>
                            )}
                        </div>
                    )}
                </Panel>

                <Panel
                    title="Prorated across other contracts"
                    description="For compilations and bundles: income on this contract is divided between the contracts below by weight, and each pays its own splits."
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('children', [
                                    ...form.data.children,
                                    { contract_id: '', weight: '1' },
                                ])
                            }
                        >
                            <Plus />
                            Add contract
                        </Button>
                    }
                >
                    <InputError message={errors.children} />
                    {form.data.children.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Not prorated. Leave empty for a normal contract.
                        </p>
                    ) : (
                        <div className="grid gap-3">
                            {form.data.children.map((child, position) => (
                                <div
                                    key={position}
                                    className="grid gap-2 md:grid-cols-[1fr_7rem_6rem_2.25rem] md:items-center"
                                >
                                    <div>
                                        <RecordSelect
                                            aria-label="Contract"
                                            records="contracts"
                                            params={{ exclude: contract?.id }}
                                            value={child.contract_id}
                                            onChange={(id) =>
                                                setChild(position, {
                                                    contract_id: id,
                                                })
                                            }
                                            placeholder="Choose contract…"
                                            required
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `children.${position}.contract_id`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Input
                                            aria-label="Weight"
                                            type="number"
                                            step="any"
                                            min="0"
                                            value={child.weight}
                                            onChange={(event) =>
                                                setChild(position, {
                                                    weight: event.target.value,
                                                })
                                            }
                                            required
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `children.${position}.weight`
                                                ]
                                            }
                                        />
                                    </div>
                                    <span className="text-sm text-muted-foreground tabular-nums">
                                        {weightTotal > 0
                                            ? percent(
                                                  (Number(child.weight) || 0) /
                                                      weightTotal,
                                                  2,
                                              )
                                            : '–'}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove contract"
                                        onClick={() =>
                                            form.setData(
                                                'children',
                                                form.data.children.filter(
                                                    (_, current) =>
                                                        current !== position,
                                                ),
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            <p className="text-xs text-muted-foreground">
                                Tip: use the number of tracks each contract
                                contributes as its weight.
                            </p>
                        </div>
                    )}
                </Panel>

                <div className="flex items-center gap-3">
                    <Button disabled={form.processing}>
                        {contract ? 'Save contract' : 'Create contract'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                returnTo ??
                                (contract ? show(contract.id) : index())
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </Page>
    );
}
