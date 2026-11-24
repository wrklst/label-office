<?php

namespace App\Http\Controllers;

use App\Enums\AirplayOrigin;
use App\Http\Requests\AirplayRequest;
use App\Models\Airplay;
use App\Models\AirplayFinding;
use App\Models\AirplaySearch;
use App\Models\Track;
use App\Services\Airplay\AirplayResearch;
use App\Services\Airplay\ResearchState;
use App\Services\Airplay\WebSearch;
use App\Services\Releases\ArtistCredits;
use App\Support\CurrentLabel;
use App\Support\Filters;
use App\Support\PageSize;
use App\Support\Sorting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use SortDirection;

class AirplayController extends Controller
{
    public function index(Request $request, WebSearch $search, AirplayResearch $research): Response
    {
        $only = Track::query()->find($request->integer('track') ?: null, ['id', 'label_id', 'name', 'isrc']);
        $onlyIds = $only?->recordingIds() ?? [];

        $researched = AirplaySearch::query()->whereNotNull('airplay_id')->where('succeeded', true)
            ->selectRaw('airplay_id, MAX(created_at) as researched_at')->groupBy('airplay_id')->toBase()->pluck('researched_at', 'airplay_id');

        $sorting = Sorting::for($request, [
            'track' => 'track_name',
            'artist' => Sorting::nullsLast('artist_name'),
            'station' => Sorting::nullsLast('station'),
            'origin' => 'origin',
            'period' => Sorting::nullsLast('period_start'),
            'amount' => Sorting::nullsLast('amount'),
            'links' => fn (Builder $query, SortDirection $direction) => $query->orderBy(new Expression(
                $query->getGrammar() instanceof SQLiteGrammar ? 'COALESCE(json_array_length(links), 0)' : 'COALESCE(JSON_LENGTH(links), 0)',
            ), $direction),
        ], fn (Builder $query) => $query->orderByRaw('station is null')->orderBy('station')->orderByDesc('period_end')->orderBy('track_name'));

        return Inertia::render('airplays/index', [
            'findings' => AirplayFinding::query()->where('status', AirplayFinding::NEW)->when($only, fn ($query) => $query->whereIn('track_id', $onlyIds))->with(['track:id,name', 'airplay:id,track_name,station'])
                ->orderByRaw("verification = 'confirmed' desc")->orderByDesc('is_radio')->latest()->orderByDesc('id')->paginate(PageSize::SECONDARY, pageName: 'findings_page')->withQueryString()
                ->through(fn (AirplayFinding $finding): array => [
                    ...$finding->only(['id', 'track_id', 'is_radio', 'station', 'url', 'title', 'description', 'domain', 'query', 'airplay_id', 'verification', 'page_title', 'show', 'excerpt']),
                    'track' => $finding->track->name ?? $finding->airplay?->track_name,
                    'airplay_station' => $finding->airplay_id === null ? null : ($finding->airplay->station ?? __('Unknown')),
                ]),
            'searches' => AirplaySearch::query()->when($only, fn ($query) => $query->whereIn('track_id', $onlyIds))->with(['track:id,name', 'airplay:id,track_name,station'])->latest('id')->paginate(PageSize::SECONDARY, pageName: 'log_page')->withQueryString()
                ->through(fn (AirplaySearch $entry): array => [
                    ...$entry->only(['id', 'track_id', 'query', 'region', 'succeeded', 'new_findings', 'error', 'airplay_id']),
                    'track' => $entry->track->name ?? $entry->airplay?->track_name,
                    'station' => $entry->airplay?->station,
                    'results' => $entry->results ?? [],
                    'created_at' => $entry->created_at?->toIso8601String(),
                ]),
            'trackResearch' => Track::query()->whereNotNull('research_state')->orderByRaw("research_state = 'running' desc")->orderBy('research_state_at')
                ->get(['id', 'name', 'research_state', 'research_step', 'research_state_at'])
                ->map(fn (Track $track): array => [
                    'id' => $track->id,
                    'name' => $track->name,
                    'state' => ResearchState::visible($track->research_state, $track->research_state_at),
                    'step' => $track->research_step,
                ])->whereNotNull('state')->values(),
            'airplayResearch' => Airplay::query()->whereNotNull('research_state')->orderBy('research_state_at')
                ->get(['id', 'track_name', 'station', 'research_state', 'research_step', 'research_state_at'])
                ->map(fn (Airplay $airplay): array => [
                    'id' => $airplay->id,
                    'name' => $airplay->track_name.' on '.($airplay->station ?? __('an unknown station')),
                    'state' => ResearchState::visible($airplay->research_state, $airplay->research_state_at),
                    'step' => $airplay->research_step,
                ])->whereNotNull('state')->values(),
            'staleCount' => $research->stale(Track::query())->count(),
            'staleDays' => AirplayResearch::STALE_DAYS,
            'filters' => [
                'search' => Filters::text($request, 'search'),
                'track' => $only?->id,
                'origin' => AirplayOrigin::tryFrom(Filters::text($request, 'origin'))?->value,
                ...$sorting->toArray(),
            ],
            'airplays' => Airplay::query()->when($only, fn ($query) => $query->whereIn('track_id', $onlyIds))->with('track:id,artist_id')
                ->when(trim(Filters::text($request, 'search')), fn ($query, string $search) => $query->where(fn ($query) => $query
                    ->where('track_name', 'like', "%{$search}%")->orWhere('artist_name', 'like', "%{$search}%")
                    ->orWhere('station', 'like', "%{$search}%")->orWhere('isrc', 'like', '%'.str_replace('-', '', $search).'%')))
                ->when(AirplayOrigin::tryFrom(Filters::text($request, 'origin')), fn ($query, AirplayOrigin $origin) => $query->where('origin', $origin))
                ->tap($sorting)->paginate(PageSize::MAIN)->withQueryString()
                ->through(fn (Airplay $airplay): array => [
                    ...$airplay->only(['id', 'origin', 'track_id', 'track_name', 'artist_name', 'isrc', 'station', 'media_type', 'region', 'society', 'amount', 'notes', 'data_import_id']),
                    'period_start' => $airplay->period_start?->toDateString(),
                    'period_end' => $airplay->period_end?->toDateString(),
                    'links' => $airplay->links ?? [],
                    'artist_id' => $airplay->track?->artist_id,
                    'search_query' => $airplay->searchQuery(),
                    'researched_at' => isset($researched[$airplay->id]) ? Carbon::parse($researched[$airplay->id])->toIso8601String() : null,
                    'research_state' => ResearchState::visible($airplay->research_state, $airplay->research_state_at),
                    'research_step' => $airplay->research_step,
                ]),
            'trackCount' => Track::query()->count(),
            'searchAvailable' => $search->isConfigured(),
        ]);
    }

    public function store(AirplayRequest $request): RedirectResponse
    {
        Airplay::query()->create([...$this->attributes($request), 'origin' => AirplayOrigin::Manual]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Airplay created.')]);

        return back();
    }

    public function update(AirplayRequest $request, Airplay $airplay): RedirectResponse
    {
        $airplay->update($this->attributes($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Airplay saved.')]);

        return back();
    }

    public function destroy(Airplay $airplay): RedirectResponse
    {
        $airplay->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Airplay deleted.')]);

        return back();
    }

    public function storeLink(Request $request, Airplay $airplay): RedirectResponse
    {
        $link = $request->validate([
            'url' => ['required', 'url:http,https', 'max:2000'],
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', Rule::in([AirplayOrigin::Search->value, AirplayOrigin::Manual->value])],
        ]);

        $airplay->addLink($link, AirplayOrigin::from($link['source'] ?? AirplayOrigin::Manual->value));
        $airplay->save();

        return back();
    }

    public function destroyLink(Request $request, Airplay $airplay): RedirectResponse
    {
        $url = $request->validate(['url' => ['required', 'string']])['url'];
        $links = collect($airplay->links)->reject(fn (array $link): bool => $link['url'] === $url)->values()->all();

        $airplay->update(['links' => $links === [] ? null : $links]);

        return back();
    }

    /**
     * Look the play up on the web. Each call is billed by the search service.
     */
    public function search(Request $request, Airplay $airplay, WebSearch $search, CurrentLabel $currentLabel): JsonResponse
    {
        $query = $request->validate(['query' => ['nullable', 'string', 'max:300']])['query'] ?? null;

        $query = $query ?: $airplay->searchQuery();
        $region = $airplay->region ?? $currentLabel->get()->airplayMarkets()[0];
        $log = ['track_id' => $airplay->track_id, 'airplay_id' => $airplay->id, 'query' => $query, 'region' => $region];

        try {
            $results = $search->search($query, $region);
            AirplaySearch::query()->create([...$log, 'succeeded' => true, 'results' => $results]);

            return response()->json(['results' => $results]);
        } catch (RuntimeException $exception) {
            report($exception);
            AirplaySearch::query()->create([...$log, 'succeeded' => false, 'error' => mb_substr($exception->getMessage(), 0, 500)]);

            throw ValidationException::withMessages(['query' => $exception->getMessage()]);
        }
    }

    /**
     * A play of a track in the catalog takes its names from the track.
     *
     * @return array<string, mixed>
     */
    private function attributes(AirplayRequest $request): array
    {
        $attributes = $request->validated();
        $track = isset($attributes['track_id']) ? Track::query()->with(['artist:id,name', 'artists:id,name'])->whereKey($attributes['track_id'])->first() : null;

        if ($track === null) {
            return $attributes;
        }

        return [
            ...$attributes,
            'track_name' => $track->name,
            'artist_name' => ArtistCredits::display($track) ?? $attributes['artist_name'] ?? null,
            'isrc' => $track->isrc,
        ];
    }
}
