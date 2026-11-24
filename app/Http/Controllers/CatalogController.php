<?php

namespace App\Http\Controllers;

use App\Models\Release;
use App\Models\Track;
use App\Support\CurrentLabel;
use App\Support\Filters;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalog manager: every release with its tracks and the contract
 * each one resolves to, for assigning contracts in one place.
 */
class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $releases = Release::query()
            ->with(['artist:id,name', 'contract:id,name', 'tracks.contract:id,name', 'tracks.artist:id,name'])
            ->when(trim(Filters::text($request, 'search')), fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('catalog_number', 'like', "%{$search}%")
                ->orWhereHas('artist', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                ->orWhereHas('tracks', fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('isrc', 'like', "%{$search}%")
                    ->orWhereHas('artist', fn ($query) => $query->where('name', 'like', "%{$search}%"))))))
            ->when($request->boolean('without_contract'), fn ($query) => $query->whereNull('contract_id')
                ->where(fn ($query) => $query->doesntHave('tracks')->orWhereHas('tracks', fn ($query) => $query->whereNull('contract_id'))))
            ->orderByRaw('catalog_number IS NULL')->orderBy('catalog_number')->orderBy('name')->orderBy('id')
            ->paginate(PageSize::MAIN)
            ->withQueryString()
            ->through(fn (Release $release): array => [
                ...$release->only(['id', 'name', 'catalog_number', 'contract_id', 'artist_id']),
                'artist' => $release->artist?->name,
                'contract' => $release->contract?->name,
                'tracks' => $release->tracks->map(fn (Track $track): array => [
                    ...$track->only(['id', 'name', 'isrc', 'contract_id', 'artist_id']),
                    'artist' => $track->artist?->name,
                    'contract' => $track->contract?->name,
                ]),
            ]);

        return Inertia::render('catalog/manager', [
            'releases' => $releases,
            'filters' => [
                'search' => Filters::text($request, 'search'),
                'without_contract' => $request->boolean('without_contract'),
            ],
            'uncovered' => Track::query()->whereNull('contract_id')->whereHas('release', fn ($query) => $query->whereNull('contract_id'))->count(),
            'hasReleases' => Release::query()->exists(),
        ]);
    }

    /**
     * Set or clear the contract of one release or track.
     */
    public function assign(Request $request, CurrentLabel $currentLabel): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['release', 'track'])],
            'id' => ['required', 'integer'],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->where('label_id', $currentLabel->id())],
        ]);

        $model = $validated['type'] === 'release' ? Release::class : Track::class;
        $model::query()->whereKey($validated['id'])->firstOrFail()->update(['contract_id' => $validated['contract_id'] ?? null]);

        return back();
    }
}
