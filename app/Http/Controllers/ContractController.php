<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContractRequest;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Release;
use App\Models\Track;
use App\Support\Decimal;
use App\Support\Filters;
use App\Support\PageSize;
use App\Support\ReturnTo;
use App\Support\Sorting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use SortDirection;

class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        $sorting = Sorting::for($request, [
            'name' => 'name',
            'usage' => fn (Builder $query, SortDirection $direction) => $query
                ->orderBy('releases_count', $direction)
                ->orderBy('tracks_count', $direction)
                ->orderBy('items_count', $direction),
        ], fn (Builder $query) => $query->orderBy('name'));

        $contracts = Contract::query()
            ->with(['splits.payee:id,name', 'splits.ledger:id,name'])
            ->withCount(['releases', 'tracks', 'items', 'children', 'parents'])
            ->when(trim(Filters::text($request, 'search')), fn ($query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($request->boolean('unused'), fn ($query) => $query->doesntHave('releases')->doesntHave('tracks')->doesntHave('items')->doesntHave('parents'))
            ->tap($sorting)
            ->paginate(PageSize::MAIN)
            ->withQueryString()
            ->through(fn (Contract $contract): array => [
                ...$contract->only(['id', 'name', 'memo', 'releases_count', 'tracks_count', 'items_count', 'children_count', 'parents_count']),
                'splits' => $contract->splits->map(fn ($split): array => [
                    'payee' => $split->payee?->name,
                    'payee_id' => $split->payee_id,
                    'split_amount' => $split->split_amount,
                    'ledger' => $split->ledger?->name,
                ]),
            ]);

        return Inertia::render('contracts/index', [
            'contracts' => $contracts,
            'filters' => ['search' => Filters::text($request, 'search'), 'unused' => $request->boolean('unused'), ...$sorting->toArray()],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('contracts/form', [
            'contract' => null,
            'returnTo' => ReturnTo::path($request->query('return_to')),
        ]);
    }

    public function store(ContractRequest $request): RedirectResponse
    {
        $contract = DB::transaction(function () use ($request): Contract {
            $contract = Contract::query()->create($request->safe()->only(['name', 'memo']));
            $this->sync($contract, $request);

            return $contract;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contract created.')]);

        return redirect(ReturnTo::from($request, route('contracts.show', $contract)));
    }

    /**
     * One contract: its terms, who is paid what, and where it applies.
     */
    public function show(Request $request, Contract $contract): Response
    {
        $contract->load(['splits.payee:id,name', 'splits.ledger:id,name', 'splits.territory:id,name', 'children', 'parents:id,name']);

        $releaseSorting = Sorting::for($request, ['name' => 'name', 'catalog_number' => Sorting::nullsLast('catalog_number')], fn (Builder $query) => $query->orderBy('name'), 'releases');
        $trackSorting = Sorting::for($request, ['name' => 'name', 'release' => Sorting::related(Release::class, 'release_id')], fn (Builder $query) => $query->orderBy('name'), 'tracks');

        return Inertia::render('contracts/show', [
            'contract' => [
                ...$contract->only(['id', 'name', 'memo']),
                'splits' => $contract->splits->map(fn (ContractSplit $split): array => [
                    'payee' => $split->payee?->only(['id', 'name']),
                    'ledger' => $split->ledger?->name,
                    'territory' => $split->territory?->name,
                    'percentage' => rtrim(rtrim(Decimal::mul((string) $split->split_amount, '100'), '0'), '.'),
                ]),
                'children' => $contract->children->map(fn (Contract $child): array => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'weight' => rtrim(rtrim((string) $child->pivot->weight, '0'), '.'),
                ]),
                'parents' => $contract->parents->map->only(['id', 'name']),
                'items_count' => $contract->items()->count(),
            ],
            'releases' => $contract->releases()->tap($releaseSorting)->paginate(PageSize::SECONDARY, ['id', 'name', 'catalog_number'], 'releases_page')->withQueryString(),
            'tracks' => $contract->tracks()->with('release:id,name')->tap($trackSorting)->paginate(PageSize::SECONDARY, ['id', 'name', 'release_id'], 'tracks_page')->withQueryString()
                ->through(fn (Track $track): array => ['id' => $track->id, 'name' => $track->name, 'release' => $track->release?->only(['id', 'name'])]),
            'filters' => [...$releaseSorting->toArray(), ...$trackSorting->toArray()],
        ]);
    }

    public function edit(Request $request, Contract $contract): Response
    {
        $contract->load(['splits', 'children:id,name']);

        return Inertia::render('contracts/form', [
            'returnTo' => ReturnTo::path($request->query('return_to')),
            'contract' => [
                ...$contract->only(['id', 'name', 'memo']),
                'splits' => $contract->splits->map(fn ($split): array => [
                    'payee_id' => $split->payee_id,
                    'ledger_id' => $split->ledger_id,
                    'territory_id' => $split->territory_id,
                    'percentage' => rtrim(rtrim(Decimal::mul((string) $split->split_amount, '100'), '0'), '.'),
                ]),
                'children' => $contract->children->map(fn (Contract $child): array => [
                    'contract_id' => $child->id,
                    'weight' => rtrim(rtrim((string) $child->pivot->weight, '0'), '.'),
                ]),
            ],
        ]);
    }

    public function update(ContractRequest $request, Contract $contract): RedirectResponse
    {
        DB::transaction(function () use ($request, $contract): void {
            $contract->update($request->safe()->only(['name', 'memo']));
            $this->sync($contract, $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contract saved.')]);

        return redirect(ReturnTo::from($request, route('contracts.show', $contract)));
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        $contract->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contract deleted.')]);

        return to_route('contracts.index');
    }

    private function sync(Contract $contract, ContractRequest $request): void
    {
        if ($request->has('splits')) {
            $contract->splits()->delete();
        }

        foreach ($request->validated('splits', []) as $split) {
            $contract->splits()->create([
                'payee_id' => $split['payee_id'],
                'ledger_id' => $split['ledger_id'] ?? null,
                'territory_id' => $split['territory_id'] ?? null,
                'split_amount' => bcdiv((string) $split['percentage'], '100', 9),
            ]);
        }

        if (! $request->has('children')) {
            return;
        }

        $children = [];

        foreach ($request->validated('children', []) as $child) {
            $children[$child['contract_id']] = ['weight' => $child['weight']];
        }

        $contract->children()->sync($children);
    }
}
