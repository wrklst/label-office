<?php

namespace App\Http\Controllers;

use App\Http\Requests\ItemRequest;
use App\Models\Contract;
use App\Models\Item;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Support\Filters;
use App\Support\PageSize;
use App\Support\Sorting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(Request $request): Response
    {
        $sorting = Sorting::for($request, [
            'name' => 'name',
            'format_group' => Sorting::nullsLast('format_group'),
            'release' => Sorting::related(Release::class, 'release_id'),
            'isrc' => Sorting::nullsLast('isrc'),
            'contract' => Sorting::related(Contract::class, 'contract_id'),
            'transactions_count' => 'transactions_count',
            'total' => Sorting::sum('total'),
        ], fn (Builder $query) => $query->orderByRaw('release_id IS NOT NULL')->orderBy('name'));

        $items = Item::query()
            ->with(['release:id,name,catalog_number', 'track:id,name', 'contract:id,name'])
            ->withCount('transactions')
            ->withSum('transactions as total', 'converted_total')
            ->when(trim(Filters::text($request, 'search')), fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('isrc', 'like', "%{$search}%")
                ->orWhere('upc', 'like', "%{$search}%")
                ->orWhere('catalog_number', 'like', "%{$search}%")))
            ->when($request->boolean('unassigned'), fn ($query) => $query->whereNull('release_id'))
            ->when($request->integer('release'), fn ($query, int $release) => $query->where('release_id', $release))
            ->tap($sorting)
            ->paginate(PageSize::MAIN)
            ->withQueryString()
            ->through(fn (Item $item): array => [
                ...$item->only(['id', 'name', 'artist_name', 'track_name', 'catalog_number', 'isrc', 'upc', 'format_group', 'is_additionals', 'release_id', 'track_id', 'contract_id', 'transactions_count']),
                'release' => $item->release?->only(['id', 'name', 'catalog_number']),
                'track' => $item->track?->only(['id', 'name']),
                'contract' => $item->contract?->only(['id', 'name']),
                'total' => (string) ($item->total ?? '0'),
            ]);

        return Inertia::render('items/index', [
            'items' => $items,
            'filters' => [
                'search' => Filters::text($request, 'search'),
                'unassigned' => $request->boolean('unassigned'),
                'release' => $request->integer('release') ?: null,
                ...$sorting->toArray(),
            ],
            'unassignedCount' => Item::query()->whereNull('release_id')->count(),
        ]);
    }

    /**
     * Assign an item to the catalog and move its transactions along.
     */
    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        abort_if($item->is_additionals, 403, 'The [Additionals] item belongs to its release and cannot be reassigned.');

        $attributes = $request->validated();

        if (! empty($attributes['track_id'])) {
            $attributes['release_id'] = Track::query()->whereKey($attributes['track_id'])->firstOrFail()->releaseFor($attributes['release_id'] ?? null);
        } elseif (array_key_exists('release_id', $attributes) && $attributes['release_id'] !== $item->release_id) {
            // The track was not named again, so it does not follow the item to another release.
            $attributes['track_id'] = null;
        }

        $moved = DB::transaction(function () use ($item, $attributes): int {
            $item->update($attributes);

            if (! $item->wasChanged(['release_id', 'track_id'])) {
                return 0;
            }

            return Transaction::query()->where('item_id', $item->id)->update([
                'release_id' => $item->release_id,
                'track_id' => $item->track_id,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $moved > 0
            ? __('Item saved and :count transactions moved with it.', ['count' => $moved])
            : __('Item saved.')]);

        return back();
    }

    public function destroy(Item $item): RedirectResponse
    {
        abort_if($item->is_additionals, 403, 'The [Additionals] item belongs to its release and cannot be deleted.');

        if ($item->transactions()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This item has transactions. Delete or reassign them first.')]);

            return back();
        }

        $item->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item deleted.')]);

        return back();
    }
}
