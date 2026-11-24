<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\TrackRequest;
use App\Models\Agreement;
use App\Models\Airplay;
use App\Models\AirplayFinding;
use App\Models\AirplaySearch;
use App\Models\Artist;
use App\Models\Contract;
use App\Models\PlayCount;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Airplay\ResearchState;
use App\Services\Airplay\WebSearch;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrackController extends Controller
{
    /**
     * What the track dialog edits, for every page that opens it. The track needs its `artists` loaded.
     * A list of many tracks leaves the lyrics and credits out (`$withText` false); the dialog fetches them from `fields()` when it opens.
     *
     * @return array<string, mixed>
     */
    public static function editable(Track $track, bool $withText = true): array
    {
        return [
            ...$track->only([
                'id', 'name', 'aliases', 'isrc', 'iswc', 'track_number', 'disc_number', 'duration', 'artist_id', 'contract_id', 'version', 'genre',
                'language', 'explicit', 'musical_key', 'preview_start', 'p_line', 'ai_recording', 'ai_composition', 'ai_lyrics',
                ...($withText ? ['lyrics', 'credits'] : []),
            ]),
            'bpm' => $track->bpm === null ? null : rtrim(rtrim((string) $track->bpm, '0'), '.'),
            'artists' => $track->artists->map(fn (Artist $artist): array => ['artist_id' => $artist->id, 'role' => $artist->pivot->role])->values(),
        ];
    }

    public function index(Request $request): Response
    {
        $sorting = Sorting::for($request, [
            'name' => 'name',
            'artist' => Sorting::related(Artist::class, 'artist_id'),
            'release' => Sorting::related(Release::class, 'release_id'),
            'isrc' => Sorting::nullsLast('isrc'),
            'contract' => Sorting::related(Contract::class, 'contract_id'),
        ], fn (Builder $query) => $query->orderBy('name'));

        $tracks = Track::query()
            ->with([
                'artist:id,name', 'artists:id,name', 'contract:id,name', 'release:id,name,catalog_number,contract_id,artist_id', 'release.contract:id,name',
                'release.artist:id,name', 'release.artists:id,name', 'release.cover:id,release_id', 'releases:id,name',
            ])
            ->when(trim(Filters::text($request, 'search')), fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('aliases', 'like', "%{$search}%")
                ->orWhere('isrc', 'like', '%'.str_replace('-', '', $search).'%')))
            // A recording on several releases is listed once, by its first entry;
            // within one release every entry of that release is listed.
            ->when($request->integer('release'), fn ($query, int $release) => $query->onRelease($release), fn ($query) => $query
                ->where(fn ($query) => $query->whereNull('isrc')->orWhereRaw('tracks.id = (select min(entries.id) from tracks as entries where entries.label_id = tracks.label_id and entries.isrc = tracks.isrc)')))
            ->tap($sorting)
            ->paginate(PageSize::MAIN)
            ->withQueryString();
        $audio = PlayableAudio::forTracks($tracks->getCollection());
        $recordings = $request->integer('release') ? collect() : Track::query()
            ->whereIn('isrc', $tracks->getCollection()->pluck('isrc')->filter())
            ->with(['release:id,name,contract_id', 'releases:id,name'])->orderBy('id')->get()->groupBy('isrc');

        $tracks = $tracks->through(fn (Track $track): array => [
            ...self::editable($track, withText: false),
            ...$track->only(['mechanicals_rate', 'ppd_rate', 'release_id']),
            'artist' => $track->artist?->name,
            'release' => $track->release?->only(['id', 'name', 'catalog_number']),
            'releases' => ($recordings->get($track->isrc) ?? collect([$track]))->flatMap(fn (Track $entry) => $entry->releases->map(fn (Release $release): array => $release->only(['id', 'name'])))->unique('id')->values(),
            // Each entry has its own contract; one without is named here, as its row is not listed.
            'without_contract_on' => ($recordings->get($track->isrc) ?? collect())
                ->filter(fn (Track $entry): bool => $entry->id !== $track->id && $entry->contract_id === null && $entry->release?->contract_id === null)
                ->map(fn (Track $entry): string => $entry->release->name ?? $entry->name)->values(),
            'audio_file_id' => $audio->get($track->id)?->id,
            'credited_artist' => ArtistCredits::display($track) ?? ($track->release ? ArtistCredits::display($track->release) : null),
            'cover_file_id' => $track->release?->cover?->id,
            'contract' => $track->contract?->only(['id', 'name']),
            'inherited_contract' => $track->contract ? null : $track->release?->contract?->only(['id', 'name']),
        ]);

        return Inertia::render('tracks/index', [
            'tracks' => $tracks,
            'filters' => ['search' => Filters::text($request, 'search'), 'release' => $request->integer('release') ?: null, ...$sorting->toArray()],
            'genreOptions' => Options::genres(),
        ]);
    }

    /**
     * The long texts of a track that its list leaves out, for the dialog that opens on one.
     */
    public function fields(Track $track): JsonResponse
    {
        return response()->json($track->only(['lyrics', 'credits']));
    }

    /**
     * Everything known about one track: where it belongs, what it earned and where it was played.
     */
    public function show(Request $request, Track $track, WebSearch $search): Response
    {
        $airplaySorting = Sorting::for($request, [
            'station' => Sorting::nullsLast('station'),
            'origin' => 'origin',
            'period' => Sorting::nullsLast('period_start'),
            'amount' => Sorting::nullsLast('amount'),
        ], fn (Builder $query) => $query->orderByRaw('station is null')->orderBy('station')->orderByDesc('period_end'), 'airplays');
        $searchSorting = Sorting::for($request, [
            'created_at' => 'id',
            'query' => 'query',
            'result' => 'new_findings',
        ], fn (Builder $query) => $query->latest('id'), 'searches');

        $track->load([
            'artist:id,name', 'artists:id,name', 'contract:id,name', 'release:id,name,catalog_number,contract_id', 'release.contract:id,name',
            'release.cover:id,release_id',
        ]);

        $ids = $track->recordingIds();
        $income = Transaction::query()->whereIn('track_id', $ids)->where('type', TransactionType::Income);

        return Inertia::render('tracks/show', [
            'agreements' => Agreement::linkedTo($track),
            'track' => [
                ...self::editable($track),
                'credited_artist' => ArtistCredits::display($track),
                // The main artist is not among the further credits, so it is put in front of them.
                'credited_artists' => collect([$track->artist])->concat($track->artists)->filter()->unique('id')
                    ->map(fn (Artist $artist): array => $artist->only(['id', 'name']))->values(),
                'release' => $track->release?->only(['id', 'name', 'catalog_number']),
                'cover_file_id' => $track->release?->cover?->id,
                'audio_file_id' => PlayableAudio::forTracks(collect([$track]))->get($track->id)?->id,
                'contract' => $track->contract?->only(['id', 'name']),
                'inherited_contract' => $track->contract ? null : $track->release?->contract?->only(['id', 'name']),
                'researched_at' => $track->researched_at?->toIso8601String(),
                'research_state' => ResearchState::visible($track->research_state, $track->research_state_at),
                'research_step' => $track->research_step,
            ],
            'income' => [
                'total' => (string) ((clone $income)->sum('converted_total') ?: '0'),
                'transactions' => (clone $income)->count(),
            ],
            'report' => IncomeReport::of(clone $income, PlayCount::query()->whereIn('track_id', $ids)),
            'releases' => Track::query()->whereIn('id', $ids)->with('releases:id,name,catalog_number')->orderBy('id')->get()
                ->flatMap(fn (Track $entry) => $entry->releases->map(fn (Release $release): array => [
                    'track_id' => $entry->id,
                    ...$release->only(['id', 'name', 'catalog_number']),
                    'track_number' => $release->pivot->track_number,
                    'income' => (string) (Transaction::query()->where('track_id', $entry->id)->where('release_id', $release->id)->where('type', TransactionType::Income)->sum('converted_total') ?: '0'),
                ]))->values(),
            'filters' => [...$airplaySorting->toArray(), ...$searchSorting->toArray()],
            'airplayTotals' => [
                'plays' => Airplay::query()->whereIn('track_id', $ids)->count(),
                'with_station' => Airplay::query()->whereIn('track_id', $ids)->whereNotNull('station')->count(),
                'earned' => (string) (Airplay::query()->whereIn('track_id', $ids)->sum('amount') ?: '0'),
            ],
            'airplays' => Airplay::query()->whereIn('track_id', $ids)->tap($airplaySorting)->paginate(PageSize::SECONDARY, pageName: 'airplays_page')->withQueryString()
                ->through(fn (Airplay $airplay): array => [
                    ...$airplay->only(['id', 'origin', 'station', 'media_type', 'region', 'society', 'amount', 'notes', 'data_import_id']),
                    'period_start' => $airplay->period_start?->toDateString(),
                    'period_end' => $airplay->period_end?->toDateString(),
                    'links' => $airplay->links ?? [],
                ]),
            'openFindings' => AirplayFinding::query()->whereIn('track_id', $ids)->where('status', AirplayFinding::NEW)->count(),
            'searches' => AirplaySearch::query()->whereIn('track_id', $ids)->tap($searchSorting)->paginate(PageSize::SECONDARY, pageName: 'searches_page')->withQueryString()
                ->through(fn (AirplaySearch $entry): array => [
                    ...$entry->only(['id', 'query', 'region', 'succeeded', 'new_findings', 'error']),
                    'results' => count($entry->results ?? []),
                    'created_at' => $entry->created_at?->toIso8601String(),
                ]),
            'searchAvailable' => $search->isConfigured(),
            'platformLinks' => PlatformLinkController::props($track),
            'genreOptions' => Options::genres(),
        ]);
    }

    public function update(TrackRequest $request, Track $track): RedirectResponse
    {
        $attributes = $request->safe()->except(['artists', 'release_id']);

        // On another release than its first, the track has its own number.
        if ($request->filled('release_id') && $request->integer('release_id') !== $track->release_id) {
            $track->putOn($request->integer('release_id'), $attributes['track_number'] ?? null, $attributes['disc_number'] ?? null);
            unset($attributes['track_number'], $attributes['disc_number']);
        }

        if ($request->has('artists')) {
            ArtistCredits::sync($track, $request->validated('artists', []));
        }

        if (array_key_exists('isrc', $attributes)) {
            $attributes['isrc'] = Identifiers::isrc($attributes['isrc']);
        }

        $track->update($attributes);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Track saved.')]);

        return back();
    }

    public function destroy(Request $request, Track $track): RedirectResponse
    {
        $track->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Track deleted.')]);

        return ReturnTo::afterDelete($request, route('tracks.show', $track->id), route('tracks.index'));
    }
}
