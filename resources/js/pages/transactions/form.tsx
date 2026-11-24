import { Link, setLayoutProps, useForm, usePage } from '@inertiajs/react';
import TransactionController from '@/actions/App/Http/Controllers/TransactionController';
import { CheckField, Field, SelectInput } from '@/components/field';
import { Page, Panel } from '@/components/page';
import { RecordSelect } from '@/components/record-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { today } from '@/lib/format';
import { index } from '@/routes/transactions';

type TransactionType = 'income' | 'expense' | 'transfer';

type Transaction = {
    id: number;
    type: TransactionType;
    timestamp: string;
    paid_timestamp: string;
    total: string;
    currency: string;
    conversion_rate: string | null;
    quantity: string | null;
    price: string | null;
    release_id: number | null;
    track_id: number | null;
    contract_id: number | null;
    payee_id: number | null;
    ledger_id: number | null;
    category: string | null;
    format: string | null;
    usage_type: string | null;
    region: string | null;
    retailer: string | null;
    provider: string | null;
    external_id: string | null;
    source: string | null;
    memo: string | null;
    published: boolean;
    billable: boolean;
    recoupable: boolean;
    raw_data: Record<string, string> | null;
    data_import_id: number | null;
    /** An expense stored positive: a refund or credit that reduces a cost. */
    refund: boolean;
};

type Props = {
    transaction: Transaction | null;
    defaults: {
        type: string;
        release_id: number | null;
        payee_id: number | null;
        recoupable: boolean;
    } | null;
    /** The page the form was opened from, where saving returns to. */
    returnTo: string | null;
    categories: Record<TransactionType, string[]>;
    formats: Record<string, string[]>;
};

const trim = (value: string | number | null | undefined): string =>
    value || value === 0
        ? String(value).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1')
        : '';
/**
 * The amount the form shows after the type changed, which is what gets
 * stored under the new type's sign convention: an expense is typed positive,
 * so leaving it for a transfer shows the cost as money paid out (negative,
 * unless it was a refund); income and expense are typed positive.
 */
const totalForType = (
    total: string,
    from: TransactionType,
    to: TransactionType,
    refund: boolean,
): string => {
    const amount = total.replace(/^-/, '');

    if (amount === '' || to === from) {
        return total;
    }

    if (to === 'expense') {
        return amount;
    }

    if (from === 'expense') {
        return to === 'transfer' && !refund ? `-${amount}` : amount;
    }

    return total;
};
// The local date: toISOString() is UTC and gives yesterday shortly after midnight.

export default function TransactionForm({
    transaction,
    defaults,
    returnTo,
    categories,
    formats,
}: Props) {
    const { label } = usePage().props;
    const labelCurrency = label?.currency ?? 'EUR';
    const initialType = (transaction?.type ??
        (['income', 'expense', 'transfer'].includes(defaults?.type ?? '')
            ? defaults?.type
            : 'income')) as TransactionType;

    const form = useForm({
        return_to: returnTo ?? '',
        type: initialType,
        timestamp: transaction?.timestamp ?? today(),
        paid_timestamp: transaction?.paid_timestamp ?? today(),
        total: trim(transaction?.total),
        refund: transaction?.refund ?? false,
        currency: transaction?.currency ?? labelCurrency,
        conversion_rate: trim(transaction?.conversion_rate),
        quantity: trim(transaction?.quantity),
        price: trim(transaction?.price),
        release_id: (transaction?.release_id ?? defaults?.release_id ?? '') as
            | number
            | '',
        track_id: (transaction?.track_id ?? '') as number | '',
        contract_id: (transaction?.contract_id ?? '') as number | '',
        payee_id: (transaction?.payee_id ?? defaults?.payee_id ?? '') as
            | number
            | '',
        ledger_id: (transaction?.ledger_id ?? '') as number | '',
        category: transaction?.category ?? '',
        format: transaction?.format ?? '',
        region: transaction?.region ?? '',
        retailer: transaction?.retailer ?? '',
        provider: transaction?.provider ?? '',
        external_id: transaction?.external_id ?? '',
        source: transaction?.source ?? '',
        memo: transaction?.memo ?? '',
        published: transaction?.published ?? true,
        billable: transaction?.billable ?? true,
        recoupable: transaction?.recoupable ?? defaults?.recoupable ?? true,
    });

    const errors = form.errors;
    const type = form.data.type;
    const foreign = form.data.currency.toUpperCase() !== labelCurrency;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (transaction) {
            form.put(TransactionController.update.url(transaction.id));
        } else {
            form.post(TransactionController.store.url());
        }
    };

    const title = transaction ? `Edit ${transaction.type}` : `New ${type}`;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Transactions', href: index() },
            { title, href: '#' },
        ],
    });

    return (
        <Page
            title={title}
            description={
                type === 'transfer'
                    ? 'A transfer moves money between the label and a payee outside of royalties: an advance, a payback, an opening balance.'
                    : type === 'expense'
                      ? form.data.recoupable
                          ? 'An expense reduces the net income of its release or track before the split, so the payee carries their share of it.'
                          : 'A label cost: an expense the label bears alone. It does not reduce what payees earn and counts against the release in the profitability report.'
                      : 'Income entered by hand, for example a sync fee or a sale on a platform that is not in any statement.'
            }
        >
            <form onSubmit={submit} className="grid max-w-4xl gap-6">
                <Panel title="Amount">
                    <div className="grid gap-4 md:grid-cols-3">
                        <Field
                            label="Type"
                            htmlFor="type"
                            error={errors.type}
                            required
                        >
                            <SelectInput
                                id="type"
                                value={type}
                                onChange={(event) =>
                                    form.setData((data) => {
                                        const next = event.target
                                            .value as TransactionType;

                                        return {
                                            ...data,
                                            type: next,
                                            total: totalForType(
                                                data.total,
                                                data.type,
                                                next,
                                                data.refund,
                                            ),
                                            category: '',
                                            refund: false,
                                        };
                                    })
                                }
                                className="capitalize"
                                options={['income', 'expense', 'transfer']}
                            />
                        </Field>
                        <Field
                            label="Total"
                            htmlFor="total"
                            error={errors.total}
                            required
                            hint={
                                type === 'expense'
                                    ? 'Enter the cost as a positive amount, e.g. 250. It is booked as a cost.'
                                    : type === 'transfer'
                                      ? 'Negative: given to the payee (advance). Positive: owed to or returned by the payee.'
                                      : undefined
                            }
                        >
                            <Input
                                id="total"
                                type="number"
                                step="any"
                                min={type === 'expense' ? '0' : undefined}
                                value={form.data.total}
                                onChange={(event) =>
                                    form.setData('total', event.target.value)
                                }
                                required
                            />
                        </Field>
                        <Field
                            label="Currency"
                            htmlFor="currency"
                            error={errors.currency}
                            required
                        >
                            <Input
                                id="currency"
                                value={form.data.currency}
                                onChange={(event) =>
                                    form.setData(
                                        'currency',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                                maxLength={3}
                                className="uppercase"
                                required
                            />
                        </Field>
                        {type === 'expense' && (
                            <CheckField
                                id="refund"
                                label="Refund or credit"
                                hint="Tick it when the amount comes back to the label: it reduces the cost instead of adding to it."
                                checked={form.data.refund}
                                onChange={(event) =>
                                    form.setData('refund', event.target.checked)
                                }
                                className="md:col-span-3"
                            />
                        )}
                        {foreign && (
                            <Field
                                label={`Rate to ${labelCurrency}`}
                                htmlFor="conversion_rate"
                                error={errors.conversion_rate}
                                hint={`1 ${form.data.currency.toUpperCase()} = ? ${labelCurrency}. Leave empty to use the European Central Bank's rate of the paid date.`}
                                className="md:col-span-3 md:max-w-xs"
                            >
                                <Input
                                    id="conversion_rate"
                                    type="number"
                                    step="any"
                                    min="0"
                                    value={form.data.conversion_rate}
                                    onChange={(event) =>
                                        form.setData(
                                            'conversion_rate',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        )}
                        <Field
                            label={type === 'income' ? 'Sale date' : 'Date'}
                            htmlFor="timestamp"
                            error={errors.timestamp}
                            required
                        >
                            <Input
                                id="timestamp"
                                type="date"
                                value={form.data.timestamp}
                                onChange={(event) =>
                                    form.setData(
                                        'timestamp',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </Field>
                        <Field
                            label="Paid date"
                            htmlFor="paid_timestamp"
                            error={errors.paid_timestamp}
                            required
                            hint="Decides which period the transaction counts in."
                        >
                            <Input
                                id="paid_timestamp"
                                type="date"
                                value={form.data.paid_timestamp}
                                onChange={(event) =>
                                    form.setData(
                                        'paid_timestamp',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </Field>
                        <Field
                            label="Category"
                            htmlFor="category"
                            error={errors.category}
                        >
                            <SelectInput
                                id="category"
                                value={form.data.category}
                                onChange={(event) =>
                                    form.setData('category', event.target.value)
                                }
                                placeholder="No category"
                                options={[
                                    ...new Set([
                                        ...(categories[type] ?? []),
                                        ...(form.data.category
                                            ? [form.data.category]
                                            : []),
                                    ]),
                                ]}
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel title={type === 'transfer' ? 'Payee' : 'Catalog'}>
                    <div className="grid gap-4 md:grid-cols-2">
                        {type === 'transfer' && (
                            <>
                                <Field
                                    label="Payee"
                                    htmlFor="payee_id"
                                    error={errors.payee_id}
                                    required
                                >
                                    <RecordSelect
                                        id="payee_id"
                                        records="payees"
                                        value={form.data.payee_id}
                                        onChange={(id) =>
                                            form.setData('payee_id', id)
                                        }
                                        placeholder="Choose payee…"
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Ledger"
                                    htmlFor="ledger_id"
                                    error={errors.ledger_id}
                                    hint="Leave empty to use the ledger of the payee's split on the release below, or the primary ledger."
                                >
                                    <RecordSelect
                                        id="ledger_id"
                                        records="ledgers"
                                        value={form.data.ledger_id}
                                        onChange={(id) =>
                                            form.setData('ledger_id', id)
                                        }
                                        placeholder="Automatic"
                                    />
                                </Field>
                            </>
                        )}
                        <Field
                            label="Release"
                            htmlFor="release_id"
                            error={errors.release_id}
                            hint={
                                type === 'transfer'
                                    ? 'Optional. For your reference and to pick the ledger.'
                                    : 'Without a release the amount stays with the label.'
                            }
                        >
                            <RecordSelect
                                id="release_id"
                                records="releases"
                                value={form.data.release_id}
                                onChange={(id) =>
                                    form.setData((data) => ({
                                        ...data,
                                        release_id: id,
                                        track_id: '',
                                    }))
                                }
                                placeholder="No release"
                            />
                        </Field>
                        {type === 'expense' && (
                            <>
                                <Field
                                    label="Paid to"
                                    htmlFor="payee_id"
                                    error={errors.payee_id}
                                    hint="Optional. For your reference only, e.g. the remixer or designer who received a label cost."
                                >
                                    <RecordSelect
                                        id="payee_id"
                                        records="payees"
                                        value={form.data.payee_id}
                                        onChange={(id) =>
                                            form.setData('payee_id', id)
                                        }
                                        placeholder="Nobody in particular"
                                    />
                                </Field>
                                <CheckField
                                    id="recoupable"
                                    label="Recoup from payees"
                                    hint="On: the cost is deducted before the split, so payees carry their share of it. Off: the label bears the cost alone and payees earn as if it did not exist."
                                    checked={form.data.recoupable}
                                    onChange={(event) =>
                                        form.setData(
                                            'recoupable',
                                            event.target.checked,
                                        )
                                    }
                                    className="md:pt-6"
                                />
                            </>
                        )}
                        {type !== 'transfer' && (
                            <>
                                <Field
                                    label="Track"
                                    htmlFor="track_id"
                                    error={errors.track_id}
                                >
                                    <RecordSelect
                                        id="track_id"
                                        records="tracks"
                                        value={form.data.track_id}
                                        onChange={(id) =>
                                            form.setData('track_id', id)
                                        }
                                        params={{
                                            release_id: form.data.release_id,
                                        }}
                                        placeholder="Whole release"
                                        disabled={form.data.release_id === ''}
                                    />
                                </Field>
                                <Field
                                    label="Contract override"
                                    htmlFor="contract_id"
                                    error={errors.contract_id}
                                    hint="Only when this one transaction follows a different deal than its track or release."
                                    className="md:col-span-2"
                                >
                                    <RecordSelect
                                        id="contract_id"
                                        records="contracts"
                                        value={form.data.contract_id}
                                        onChange={(id) =>
                                            form.setData('contract_id', id)
                                        }
                                        placeholder="Use track contract"
                                    />
                                </Field>
                            </>
                        )}
                    </div>
                </Panel>

                <Panel title="Details">
                    <div className="grid gap-4 md:grid-cols-3">
                        {type === 'income' && (
                            <>
                                <Field
                                    label="Format"
                                    htmlFor="format"
                                    error={errors.format}
                                >
                                    <SelectInput
                                        id="format"
                                        value={form.data.format}
                                        onChange={(event) =>
                                            form.setData(
                                                'format',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="No format"
                                    >
                                        {Object.entries(formats).map(
                                            ([group, values]) => (
                                                <optgroup
                                                    key={group}
                                                    label={group}
                                                >
                                                    {values.map((value) => (
                                                        <option
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {value}
                                                        </option>
                                                    ))}
                                                </optgroup>
                                            ),
                                        )}
                                        {form.data.format &&
                                            !Object.values(formats)
                                                .flat()
                                                .includes(form.data.format) && (
                                                <option
                                                    value={form.data.format}
                                                >
                                                    {form.data.format}
                                                </option>
                                            )}
                                    </SelectInput>
                                </Field>
                                <Field
                                    label="Units"
                                    htmlFor="quantity"
                                    error={errors.quantity}
                                >
                                    <Input
                                        id="quantity"
                                        type="number"
                                        step="any"
                                        value={form.data.quantity}
                                        onChange={(event) =>
                                            form.setData(
                                                'quantity',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Unit price"
                                    htmlFor="price"
                                    error={errors.price}
                                >
                                    <Input
                                        id="price"
                                        type="number"
                                        step="any"
                                        value={form.data.price}
                                        onChange={(event) =>
                                            form.setData(
                                                'price',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Platform"
                                    htmlFor="retailer"
                                    error={errors.retailer}
                                >
                                    <Input
                                        id="retailer"
                                        value={form.data.retailer}
                                        onChange={(event) =>
                                            form.setData(
                                                'retailer',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="External ID"
                                    htmlFor="external_id"
                                    error={errors.external_id}
                                >
                                    <Input
                                        id="external_id"
                                        value={form.data.external_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'external_id',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </>
                        )}
                        {type !== 'transfer' && (
                            <Field
                                label="Country"
                                htmlFor="region"
                                error={errors.region}
                                hint={
                                    type === 'income'
                                        ? 'Two-letter code, e.g. DE. Used by territory splits.'
                                        : 'Two-letter code. Without one, payees whose split is limited to a territory are not charged their share of this expense.'
                                }
                            >
                                <Input
                                    id="region"
                                    value={form.data.region}
                                    onChange={(event) =>
                                        form.setData(
                                            'region',
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                    maxLength={2}
                                    className="w-24 uppercase"
                                />
                            </Field>
                        )}
                        <Field
                            label={type === 'expense' ? 'Supplier' : 'Paid by'}
                            htmlFor="source"
                            error={errors.source}
                            hint={
                                type === 'expense' ? 'Who you paid.' : undefined
                            }
                        >
                            <Input
                                id="source"
                                value={form.data.source}
                                onChange={(event) =>
                                    form.setData('source', event.target.value)
                                }
                                placeholder={transaction ? '' : 'Manual'}
                            />
                        </Field>
                        <Field
                            label="Memo"
                            htmlFor="memo"
                            error={errors.memo}
                            className="md:col-span-3"
                            hint="Shown to the payee on their statement."
                        >
                            <Textarea
                                id="memo"
                                value={form.data.memo}
                                onChange={(event) =>
                                    form.setData('memo', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Panel>

                {transaction?.raw_data &&
                    Object.keys(transaction.raw_data).length > 0 && (
                        <Panel
                            title="Original statement line"
                            description="Exactly as it was in the imported file."
                        >
                            <dl className="grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2 lg:grid-cols-3">
                                {Object.entries(transaction.raw_data)
                                    .filter(
                                        ([, value]) =>
                                            value !== '' && value !== null,
                                    )
                                    .map(([key, value]) => (
                                        <div
                                            key={key}
                                            className="flex justify-between gap-3 border-b py-1"
                                        >
                                            <dt className="text-muted-foreground">
                                                {key}
                                            </dt>
                                            <dd className="truncate text-right font-mono">
                                                {String(value)}
                                            </dd>
                                        </div>
                                    ))}
                            </dl>
                        </Panel>
                    )}

                <div className="flex items-center gap-3">
                    <Button disabled={form.processing}>
                        {transaction ? 'Save transaction' : `Create ${type}`}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={returnTo ?? index()}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </Page>
    );
}
