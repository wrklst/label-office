<?php

namespace App\Http\Controllers;

use App\Enums\ArtistRole;
use App\Enums\ReleaseFileKind;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Http\Requests\ReleaseRequest;
use App\Models\Artist;
use App\Models\Contract;
use App\Models\Item;
use App\Models\PlayCount;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Imports\Identifiers;
use App\Services\Releases\ArtistCredits;
use App\Services\Releases\PlayableAudio;
use App\Services\Reports\IncomeReport;
use App\Support\Filters;
use App\Support\Options;
use App\Support\PageSize;
use App\Support\ReturnTo;
use App\Support\Sorting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReleaseController extends Controller
{
    public function index(Request $request): Response
    {
        $sorting = Sorting::for($request, [
            'catalog_number' => Sorting::nullsLast('catalog_number'),
            'name' => 'name',
            'artist' => Sorting::related(Artist::class, 'artist_id'),
            'upc' => Sorting::nullsLast('upc'),
            'released_on' => Sorting::nullsLast('released_on'),
            'tracks_count' => 'tracks_count',
            'contract' => Sorting::related(Contract::class, 'contract_id'),
            'income' => Sorting::sum('income'),
        ], fn (Builder $query) => $query->orderByRaw('catalog_number IS NULL')->orderBy('catalog_number')->orderBy('name'));

        $releases = Release::query()
            ->with(['artist:id,name', 'artists:id,name', 'contract:id,name', 'cover:id,release_id'])
            ->withCount('tracks')
            ->withSum(['transactions as income' => fn ($query) => $query->where('type', 'income')], 'converted_total')
            ->when(trim(Filters::text($request, 'search')), fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('catalog_number', 'like', "%{$search}%")
                ->orWhere('upc', 'like', "%{$search}%")))
            // An artist is on a release in any role: as its main or credited artist, or on one of its tracks, as the Artists list counts it.
            ->when($request->integer('artist'), fn ($query, int $artist) => $query->where(fn ($query) => $query
                ->where('releases.artist_id', $artist)
                ->orWhereExists(fn ($query) => $query->from('artist_release')->whereColumn('artist_release.release_id', 'releases.id')->where('artist_release.artist_id', $artist))
                ->orWhereExists(fn ($query) => $query->from('tracks')->whereColumn('tracks.release_id', 'releases.id')->where(fn ($query) => $query
                    ->where('tracks.artist_id', $artist)
                    ->orWhereExists(fn ($query) => $query->from('artist_track')->whereColumn('artist_track.track_id', 'tracks.id')->where('artist_track.artist_id', $artist))))))
            ->when($request->boolean('without_contract'), fn ($query) => $query->whereNull('contract_id'))
            ->when(ReleaseStatus::tryFrom(Filters::text($request, 'status')), fn ($query, ReleaseStatus $status) => $query->where('status', $status))
            ->tap($sorting)
            ->paginate(PageSize::MAIN)
            ->withQueryString();
        $albums = PlayableAudio::albums($releases->getCollection());
        $releases = $releases
            ->through(fn (Release $release): array => [
                ...$release->only(['id', 'name', 'catalog_number', 'upc', 'tracks_count', 'artist_id']),
                'player_tracks' => $albums[$release->id],
                'released_on' => $release->released_on?->toDateString(),
                'artist' => ArtistCredits::display($release),
                'status' => $release->status->value,
                'release_type' => $release->release_type?->label(),
                'cover_file_id' => $release->cover?->id,
                'contract' => $release->contract?->only(['id', 'name']),
                'income' => (string) ($release->income ?? '0'),
            ]);

        return Inertia::render('releases/index', [
            'releases' => $releases,
            'filters' => [
                'search' => Filters::text($request, 'search'),
                'artist' => $request->integer('artist') ?: null,
                'without_contract' => $request->boolean('without_contract'),
                'status' => ReleaseStatus::tryFrom(Filters::text($request, 'status'))?->value,
                ...$sorting->toArray(),
            ],
            'artistOptions' => Options::artists(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('releases/form', ['release' => null, 'returnTo' => ReturnTo::path($request->query('return_to')), ...$this->options()]);
    }

    public function store(ReleaseRequest $request): RedirectResponse
    {
        $release = DB::transaction(function () use ($request): Release {
            $release = Release::query()->create($this->attributes($request));
            $this->syncArtists($release, $request);
            $this->syncTracks($release, $request);

            return $release;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Release created.')]);

        return redirect(ReturnTo::from($request, route('releases.show', $release)));
    }

    public function show(Request $request, Release $release): Response
    {
        $itemSorting = Sorting::for($request, [
            'name' => 'name',
            'track' => Sorting::related(Track::class, 'track_id'),
            'transactions_count' => 'transactions_count',
            'total' => Sorting::sum('total'),
        ], fn (Builder $query) => $query->orderBy('name'), 'items');
        $items = $release->items()
            ->with(['contract:id,name', 'track:id,name'])
            ->withCount('transactions')
            ->withSum('transactions as total', 'converted_total')
            ->tap($itemSorting)
            ->paginate(PageSize::SECONDARY, pageName: 'items_page')->withQueryString();

        $release->load([
            'artist:id,name', 'artists:id,name', 'contract:id,name', 'tracks.artist:id,name', 'tracks.artists:id,name', 'tracks.contract:id,name',
            'files.track:id,name',
        ]);
        $audioByTrack = $release->files->where('kind', ReleaseFileKind::Audio)->whereNotNull('track_id')->groupBy('track_id');

        $incomeByTrack = Transaction::query()->where('release_id', $release->id)->where('type', 'income')
            ->selectRaw('track_id, SUM(converted_total) as total, SUM(quantity) as quantity')->groupBy('track_id')->get()->keyBy('track_id');
        $byType = Transaction::query()->where('release_id', $release->id)
            ->selectRaw('type, SUM(converted_total) as total, COUNT(*) as transactions')->groupBy('type')->get()->keyBy(fn ($row) => $row->type->value);

        $bookedByItem = Transaction::query()->whereIn('item_id', $items->getCollection()->modelKeys())
            ->selectRaw('item_id, importer_source, COUNT(*) as transactions, SUM(converted_total) as total')
            ->groupBy('item_id', 'importer_source')->orderBy('importer_source')->toBase()->get()->groupBy('item_id');

        return Inertia::render('releases/show', [
            'release' => [
                ...$release->only([
                    'id', 'name', 'artist_id', 'catalog_number', 'upc', 'spotify_id', 'discogs_id', 'notes', 'version', 'genre', 'subgenre', 'language', 'explicit',
                    'p_line', 'c_line', 'press_release', 'key_facts', 'todo', 'files_url', 'blocked_services', 'blocked_territories', 'ai_artwork',
                ]),
                'released_on' => $release->released_on?->toDateString(),
                'original_released_on' => $release->original_released_on?->toDateString(),
                'preorder_on' => $release->preorder_on?->toDateString(),
                'status' => $release->status->value,
                'release_type' => $release->release_type?->label(),
                'artist' => ArtistCredits::display($release),
                'cover_file_id' => $release->files->where('kind', ReleaseFileKind::Cover)->sortByDesc('id')->first()?->id,
                'contract' => $release->contract?->only(['id', 'name']),
                'files' => $release->files->sortBy([['kind', 'asc'], ['id', 'asc']])->values()->map(fn (ReleaseFile $file): array => [
                    ...$file->only(['id', 'original_name', 'mime_type', 'size', 'track_id']),
                    'kind' => $file->kind->value,
                    'track' => $file->track?->name,
                    'inline' => $file->canBeShownInline(),
                    'is_image' => $file->isImage(),
                    'uploaded_at' => $file->created_at?->toDateString(),
                ]),
                'tracks' => $release->tracks->sortBy([['track_number', 'asc'], ['id', 'asc']])->values()->map(fn (Track $track): array => [
                    ...TrackController::editable($track),
                    'artist' => ArtistCredits::display($track),
                    'audio_file_id' => PlayableAudio::pick($audioByTrack->get($track->id))?->id,
                    'contract' => $track->contract?->only(['id', 'name']),
                    'income' => (string) ($incomeByTrack[$track->id]->total ?? '0'),
                    'quantity' => (string) ($incomeByTrack[$track->id]->quantity ?? '0'),
                ]),
            ],
            'items' => $items->through(fn (Item $item): array => [
                ...$item->only(['id', 'name', 'format_group', 'is_additionals']),
                'track' => $item->track?->name,
                'contract' => $item->contract?->only(['id', 'name']),
                'sources' => ($booked = $bookedByItem->get($item->id) ?? collect())->pluck('importer_source')->filter()->values(),
                'transactions' => $item->transactions_count,
                'total' => (string) ($item->total ?? '0'),
            ]),
            'filters' => $itemSorting->toArray(),
            'totals' => [
                'income' => (string) ($byType['income']->total ?? '0'),
                'expense' => (string) ($byType['expense']->total ?? '0'),
                'transactions' => (int) $byType->sum('transactions'),
                'release_level_income' => (string) ($incomeByTrack->get('')->total ?? '0'),
            ],
            'report' => IncomeReport::of(Transaction::query()->where('release_id', $release->id), PlayCount::query()->whereIn('track_id', $release->tracks->modelKeys())),
            'movers' => IncomeReport::movers(Transaction::query()->where('release_id', $release->id), 'track_id', 30),
            'platformLinks' => PlatformLinkController::props($release),
            'genreOptions' => Options::genres(),
            'artistOptions' => Options::artists(),
            'contractOptions' => Options::contracts(),
            'fileKinds' => array_map(fn (ReleaseFileKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], ReleaseFileKind::cases()),
            'maxUploadMegabytes' => (int) floor(config('royalties.uploads.max_kilobytes') / 1024),
        ]);
    }

    public function edit(Request $request, Release $release): Response
    {
        $release->load(['tracks', 'artists:id,name']);

        return Inertia::render('releases/form', [
            'returnTo' => ReturnTo::path($request->query('return_to')),
            'release' => [
                ...$release->only([
                    'id', 'name', 'artist_id', 'contract_id', 'catalog_number', 'upc', 'spotify_id', 'discogs_id', 'accounts_mechanicals', 'mechanicals_rate',
                    'ppd_rate', 'reserves_rate', 'notes', 'version', 'genre', 'subgenre', 'language', 'explicit', 'p_line', 'c_line', 'press_release',
                    'key_facts', 'todo', 'files_url', 'ai_artwork',
                ]),
                'released_on' => $release->released_on?->toDateString(),
                'original_released_on' => $release->original_released_on?->toDateString(),
                'preorder_on' => $release->preorder_on?->toDateString(),
                'status' => $release->status->value,
                'release_type' => $release->release_type?->value,
                'blocked_services' => $release->blocked_services ?? [],
                'blocked_territories' => $release->blocked_territories ?? [],
                'artists' => $release->artists->map(fn (Artist $artist): array => ['artist_id' => $artist->id, 'role' => $artist->pivot->role])->values(),
                'tracks' => $release->tracks->sortBy([['track_number', 'asc'], ['id', 'asc']])->values()
                    ->map->only(['id', 'name', 'artist_id', 'contract_id', 'isrc', 'iswc', 'track_number', 'duration', 'version']),
            ],
            ...$this->options(),
        ]);
    }

    public function update(ReleaseRequest $request, Release $release): RedirectResponse
    {
        DB::transaction(function () use ($request, $release): void {
            $release->update($this->attributes($request));
            $this->syncArtists($release, $request);
            $this->syncTracks($release, $request);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Release saved.')]);

        return redirect(ReturnTo::from($request, route('releases.show', $release)));
    }

    public function destroy(Release $release): RedirectResponse
    {
        $release->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Release deleted. Its transactions are kept and now unassigned.')]);

        return to_route('releases.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ReleaseRequest $request): array
    {
        $attributes = $request->safe()->except(['tracks', 'artists']);

        if (array_key_exists('upc', $attributes)) {
            $attributes['upc'] = Identifiers::upc($attributes['upc']);
        }

        return $attributes;
    }

    /**
     * Replace the additional artist credits when the form sent them.
     */
    private function syncArtists(Release $release, ReleaseRequest $request): void
    {
        if ($request->has('artists')) {
            ArtistCredits::sync($release, $request->validated('artists', []));
        }
    }

    /**
     * Update, create and remove tracks to match the submitted rows.
     */
    private function syncTracks(Release $release, ReleaseRequest $request): void
    {
        if (! $request->has('tracks')) {
            return;
        }

        $kept = [];

        foreach ($request->validated('tracks', []) as $row) {
            $attributes = [
                'name' => $row['name'],
                'artist_id' => $row['artist_id'] ?? null,
                'contract_id' => $row['contract_id'] ?? null,
                'isrc' => Identifiers::isrc($row['isrc'] ?? null),
                'iswc' => $row['iswc'] ?? null,
                'track_number' => $row['track_number'] ?? null,
                'duration' => $row['duration'] ?? null,
            ];

            // The release form does not edit a track's version; leaving it out must not clear it.
            if (array_key_exists('version', $row)) {
                $attributes['version'] = $row['version'];
            }

            $track = isset($row['id']) ? $release->tracks()->whereKey($row['id'])->first() : null;
            $track ? $track->update($attributes) : $track = $release->tracks()->create([...$attributes, 'label_id' => $release->label_id]);
            $kept[] = $track->id;
        }

        $release->tracks()->whereNotIn('id', $kept)->get()->each->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'artistOptions' => Options::artists(),
            'contractOptions' => Options::contracts(),
            'statusOptions' => array_map(fn (ReleaseStatus $status): array => ['value' => $status->value, 'label' => $status->label()], ReleaseStatus::cases()),
            'typeOptions' => array_map(fn (ReleaseType $type): array => ['value' => $type->value, 'label' => $type->label()], ReleaseType::cases()),
            // The genres a distributor takes are suggested; without one the field is free text.
            'genreOptions' => Options::genres(),
            'roleOptions' => array_map(fn (ArtistRole $role): array => ['value' => $role->value, 'label' => $role->label()], ArtistRole::cases()),
        ];
    }
}
