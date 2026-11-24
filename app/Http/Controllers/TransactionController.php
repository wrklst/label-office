<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\TransactionRequest;
use App\Models\DataImport;
use App\Models\Item;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Imports\Identifiers;
use App\Services\Royalties\ExchangeRates;
use App\Support\CurrentLabel;
use App\Support\DateRange;
use App\Support\Decimal;
use App\Support\Filters;
use App\Support\Formats;
use App\Support\PageSize;
use App\Support\Platforms;
use App\Support\ReturnTo;
use App\Support\Sorting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string'],
            'retailer' => ['nullable', 'string', 'max:255'],
        ]);

        $query = $this->filtered($request);

        $summary = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as transactions, SUM(converted_total) as total, SUM(quantity) as quantity')
            ->first();

        $sorting = Sorting::for($request, [
            'timestamp' => 'timestamp',
            'paid_timestamp' => 'paid_timestamp',
            'type' => 'type',
            'release' => Sorting::related(Release::class, 'release_id'),
            'retailer' => Sorting::nullsLast('retailer'),
            'format' => Sorting::nullsLast('format'),
            'region' => Sorting::nullsLast('region'),
            'quantity' => 'quantity',
            'converted_total' => Sorting::nullsLast('converted_total'),
        ], fn (Builder $query) => $query->orderByDesc('timestamp')->orderByDesc('id'));

        $transactions = $query
            ->with(['release:id,name,catalog_number', 'track:id,name', 'payee:id,name'])
            ->tap($sorting)
            ->paginate(PageSize::TRANSACTIONS)
            ->withQueryString()
            ->through(fn (Transaction $transaction): array => [
                ...$transaction->only(['id', 'total', 'converted_total', 'currency', 'quantity', 'category', 'format', 'region', 'retailer', 'memo', 'published', 'recoupable', 'data_import_id', 'track_id', 'payee_id']),
                'type' => $transaction->type->value,
                'timestamp' => $transaction->timestamp->toDateString(),
                'paid_timestamp' => $transaction->paid_timestamp->toDateString(),
                'release' => $transaction->release?->only(['id', 'name']),
                'track' => $transaction->track?->name,
                'payee' => $transaction->payee?->name,
            ]);

        return Inertia::render('transactions/index', [
            'transactions' => $transactions,
            'summary' => [
                'transactions' => (int) ($summary->transactions ?? 0),
                'total' => Decimal::parse($summary->total ?? 0) ?? '0',
                'quantity' => Decimal::parse($summary->quantity ?? 0) ?? '0',
            ],
            'filters' => [
                ...$request->only(['search', 'type', 'release', 'track', 'retailer', 'import', 'unassigned', 'unconverted']),
                ...DateRange::fromRequest($request)->toArray(),
                ...$sorting->toArray(),
            ],
            // The choice lists are closures: filtering, sorting and paging leave them out (useChoiceLists), and then they are not read.
            'platformOptions' => fn () => Transaction::query()->distinct()->pluck('retailer')
                ->map(fn (?string $retailer): array => Platforms::of($retailer))
                ->unique('key')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
                ->map(fn (array $platform): array => ['id' => $platform['key'], 'name' => $platform['name']]),
            'importOptions' => fn () => DataImport::query()->latest('id')->get(['id', 'name'])->map->only(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('transactions/form', [
            'transaction' => null,
            'defaults' => [
                'type' => (TransactionType::tryFrom(Filters::text($request, 'type')) ?? TransactionType::Income)->value,
                'release_id' => $request->integer('release') ?: null,
                'payee_id' => $request->integer('payee') ?: null,
                'recoupable' => $request->boolean('recoupable', true),
            ],
            'returnTo' => ReturnTo::path($request->query('return_to')),
            ...$this->options(),
        ]);
    }

    public function store(TransactionRequest $request, CurrentLabel $currentLabel): RedirectResponse
    {
        Transaction::query()->create([
            ...$this->attributes($request, $currentLabel),
            'source' => $request->validated('source') ?? 'Manual',
            'importer_source' => 'manual',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction created.')]);

        return redirect(ReturnTo::from($request, route('transactions.index')));
    }

    public function edit(Request $request, Transaction $transaction): Response
    {
        return Inertia::render('transactions/form', [
            'transaction' => [
                ...$transaction->only(['id', 'total', 'currency', 'conversion_rate', 'converted_total', 'quantity', 'price', 'release_id', 'track_id', 'contract_id', 'payee_id', 'ledger_id', 'category', 'format', 'usage_type', 'region', 'retailer', 'provider', 'external_id', 'source', 'memo', 'published', 'billable', 'recoupable', 'raw_data', 'data_import_id']),
                'type' => $transaction->type->value,
                // An expense is shown as the positive amount it was typed as; a stored positive one is a refund or credit.
                ...($transaction->type === TransactionType::Expense ? [
                    'total' => Decimal::abs($transaction->total),
                    'refund' => Decimal::isPositive($transaction->total),
                ] : ['refund' => false]),
                'timestamp' => $transaction->timestamp->toDateString(),
                'paid_timestamp' => $transaction->paid_timestamp->toDateString(),
            ],
            'defaults' => null,
            'returnTo' => ReturnTo::path($request->query('return_to')),
            ...$this->options(),
        ]);
    }

    public function update(TransactionRequest $request, Transaction $transaction, CurrentLabel $currentLabel): RedirectResponse
    {
        $transaction->update($this->attributes($request, $currentLabel, $transaction));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction saved.')]);

        return redirect(ReturnTo::from($request, route('transactions.index')));
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $transaction->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transaction deleted.')]);

        return back();
    }

    /**
     * Keep the lines of one platform by its key, whichever way the statements spell it ("Apple", "Apple/iTunes"). An older link may carry a statement's own spelling instead of a key; that filters too.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private function onPlatform(Builder $query, string $platform): Builder
    {
        $retailers = Transaction::query()->whereNotNull('retailer')->distinct()->pluck('retailer')
            ->filter(fn (string $retailer): bool => $retailer === $platform || Platforms::of($retailer)['key'] === $platform)->all();

        return $query->where(fn ($query) => $query->whereIn('retailer', $retailers)->when($platform === 'unknown', fn ($query) => $query->orWhereNull('retailer')));
    }

    /**
     * @return Builder<Transaction>
     */
    private function filtered(Request $request): Builder
    {
        return DateRange::fromRequest($request)->apply(Transaction::query())
            ->when(TransactionType::tryFrom(Filters::text($request, 'type')), fn ($query, TransactionType $type) => $query->where('type', $type))
            ->when($request->integer('release'), fn ($query, int $release) => $query->where('release_id', $release))
            ->when($request->integer('track'), fn ($query, int $track) => $query->whereIn('track_id', Track::query()->find($track)?->recordingIds() ?? [$track]))
            ->when($request->integer('import'), fn ($query, int $import) => $query->where('data_import_id', $import))
            ->when(Filters::text($request, 'retailer'), fn ($query, string $platform) => $this->onPlatform($query, $platform))
            ->when($request->boolean('unassigned'), fn ($query) => $query->whereNull('release_id')->where('type', '<>', 'transfer')
                ->where(fn ($query) => $query->where('type', '<>', 'expense')->orWhere('recoupable', true)))
            ->when($request->boolean('unconverted'), fn ($query) => $query->whereNull('converted_total'))
            ->when(trim(Filters::text($request, 'search')), function ($query, string $search) {
                // Typed text is matched literally: % and _ are not wildcards.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $isrc = Identifiers::isrc($search);

                return $query->where(fn ($query) => $query
                    ->whereRaw("memo LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("retailer LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("category LIKE ? ESCAPE '!'", [$like])
                    ->orWhere('external_id', $search)
                    ->orWhereHas('track', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$like])->when($isrc, fn ($query) => $query->orWhere('isrc', $isrc)))
                    ->orWhereHas('release', fn ($query) => $query->where(fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("catalog_number LIKE ? ESCAPE '!'", [$like]))));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(TransactionRequest $request, CurrentLabel $currentLabel, ?Transaction $transaction = null): array
    {
        $attributes = $request->validated();
        $label = $currentLabel->get();

        if (! empty($attributes['track_id'])) {
            $attributes['release_id'] = Track::query()->whereKey($attributes['track_id'])->firstOrFail()->release_id;
        }

        // An imported transaction keeps the item it was booked on; a new one,
        // or one moved to another release or track, goes on "[Additionals]".
        $releaseId = $attributes['release_id'] ?? null;
        $trackId = $attributes['track_id'] ?? null;

        if ($transaction === null || $transaction->release_id !== $releaseId || $transaction->track_id !== $trackId) {
            $attributes['item_id'] = $releaseId === null ? null : Item::query()
                ->where('release_id', $releaseId)
                ->where('is_additionals', true)
                ->value('id');
        }

        $rate = app(ExchangeRates::class)->for($attributes['currency'], $label->currency, $attributes['conversion_rate'] ?? null, $attributes['paid_timestamp'] ?? $attributes['timestamp'] ?? null);
        $total = Decimal::parse($attributes['total']);

        // An expense is typed positive and stored negative; a refund or credit reduces the cost and is stored positive.
        if ($attributes['type'] === TransactionType::Expense->value) {
            $total = ! empty($attributes['refund']) ? Decimal::abs($total) : Decimal::sub('0', Decimal::abs($total));
        }

        unset($attributes['refund']);

        if (array_key_exists('format', $attributes)) {
            $attributes['format'] = Formats::canonical($attributes['format']);
        }

        if (isset($attributes['region'])) {
            $attributes['region'] = strtoupper($attributes['region']);
        }

        // Only an expense can be borne by the label alone.
        $recoupable = $attributes['type'] !== TransactionType::Expense->value
            || ($attributes['recoupable'] ?? $transaction->recoupable ?? true);

        return [
            ...$attributes,
            'recoupable' => $recoupable,
            'total' => $total,
            'conversion_rate' => $rate,
            'converted_total' => $rate === null ? null : Decimal::mul($total, $rate),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'categories' => config('royalties.categories'),
            'formats' => config('royalties.formats'),
        ];
    }
}
