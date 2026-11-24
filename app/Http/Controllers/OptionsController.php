<?php

namespace App\Http\Controllers;

use App\Models\AgreementTemplate;
use App\Models\Artist;
use App\Models\Contract;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Period;
use App\Models\Release;
use App\Models\Territory;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What a dropdown of records offers: searched on the server and handed
 * out a page at a time, so a label with thousands of tracks loads no
 * more than the reader scrolls through. Every list is of the current
 * label only.
 */
class OptionsController extends Controller
{
    /** How many records one page of a dropdown holds. */
    public const int PAGE = 30;

    /** @var list<string> The kinds of records a dropdown can list. */
    public const array RECORDS = ['releases', 'tracks', 'artists', 'payees', 'contracts', 'ledgers', 'territories', 'periods', 'agreement-templates'];

    public function __invoke(Request $request, string $records): JsonResponse
    {
        $search = trim($request->string('search')->toString());
        $query = $this->query($records, $request);

        if ($search !== '') {
            $query->where(fn (Builder $matching) => collect($this->searched($records))
                ->each(fn (string $column) => $matching->orWhere($column, 'like', "%{$search}%")));
        }

        $page = $query->simplePaginate(self::PAGE);
        $selected = $request->integer('selected') && $page->currentPage() === 1
            ? $this->query($records, $request, filtered: false)->whereKey($request->integer('selected'))->get()
            : collect();

        return response()->json([
            'data' => $this->rows($records, collect($page->items())),
            'next' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'selected' => $this->rows($records, $selected)[0] ?? null,
        ]);
    }

    /**
     * @return Builder<covariant Model>
     */
    private function query(string $records, Request $request, bool $filtered = true): Builder
    {
        $contractId = $filtered ? $request->integer('contract_id') : 0;
        $payeeId = $filtered ? $request->integer('payee_id') : 0;
        $releaseId = $filtered ? $request->integer('release_id') : 0;
        // A record is not offered as its own parent or alias.
        $excludedId = $filtered ? $request->integer('exclude') : 0;

        $query = match ($records) {
            // The releases a contract pays for: those it is set on and those with a track it is set on.
            'releases' => $this->narrowed(
                Release::query()->with(['cover:id,release_id', 'artist:id,name'])->orderBy('name')->orderBy('id'),
                $contractId ? fn (Builder $releases) => $releases->where(fn (Builder $paid) => $paid->where('contract_id', $contractId)->orWhereHas('tracks', fn (Builder $tracks) => $tracks->where('contract_id', $contractId))) : null,
            ),
            // A dropdown of tracks can be narrowed to the release chosen beside it, and to what a contract pays for.
            'tracks' => $this->narrowed(
                Track::query()->with(['release:id,name', 'release.cover:id,release_id'])
                    ->when($releaseId, fn (Builder $tracks) => $tracks->where('release_id', $releaseId))
                    ->orderBy('name')->orderBy('id'),
                $contractId ? fn (Builder $tracks) => $tracks->where(fn (Builder $paid) => $paid->where('contract_id', $contractId)->orWhereHas('release', fn (Builder $release) => $release->where('contract_id', $contractId))) : null,
            ),
            'artists' => Artist::query()->orderBy('name')->orderBy('id'),
            'payees' => Payee::query()->orderBy('name')->orderBy('id'),
            // The contracts a payee has a split on.
            'contracts' => $this->narrowed(
                Contract::query()->orderBy('name')->orderBy('id'),
                $payeeId ? fn (Builder $contracts) => $contracts->whereHas('splits', fn (Builder $splits) => $splits->where('payee_id', $payeeId)) : null,
            ),
            'ledgers' => Ledger::query()->orderBy('name')->orderBy('id'),
            'territories' => Territory::query()->orderBy('name')->orderBy('id'),
            'periods' => Period::query()->orderByDesc('ends_on')->orderByDesc('id'),
            default => AgreementTemplate::query()->orderBy('name')->orderBy('id'),
        };

        return $excludedId ? $query->whereKeyNot($excludedId) : $query;
    }

    /**
     * Narrow a list to what belongs to something chosen beside it, but
     * only while that leaves anything: a contract that pays for nothing
     * yet must not empty the list it is about to be set from.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  (\Closure(Builder<TModel>): mixed)|null  $narrow
     * @return Builder<TModel>
     */
    private function narrowed(Builder $query, ?\Closure $narrow): Builder
    {
        if ($narrow === null || ! $query->clone()->tap($narrow)->exists()) {
            return $query;
        }

        return $query->tap($narrow);
    }

    /**
     * The columns a search looks in.
     *
     * @return list<string>
     */
    private function searched(string $records): array
    {
        return match ($records) {
            'releases' => ['name', 'catalog_number', 'upc'],
            'tracks' => ['name', 'isrc'],
            'payees' => ['name', 'email'],
            default => ['name'],
        };
    }

    /**
     * @param  Collection<int, covariant Model>  $models
     * @return list<array{id: int, name: string, hint: string|null, image: string|null}>
     */
    private function rows(string $records, Collection $models): array
    {
        $artistCovers = $records === 'artists' ? $this->artistCovers($models) : [];

        return array_values($models->map(fn (Model $model): array => [
            'id' => (int) $model->getKey(),
            'name' => (string) $model->getAttribute('name'),
            'hint' => match (true) {
                $model instanceof Release => implode(' · ', array_filter([$model->catalog_number, $model->artist?->name])) ?: null,
                $model instanceof Track => implode(' · ', array_filter([$model->release?->name, $model->isrc])) ?: null,
                $model instanceof Payee => $model->email,
                default => null,
            },
            'image' => match (true) {
                $model instanceof Release => self::cover($model),
                $model instanceof Track => $model->release ? self::cover($model->release) : null,
                // An artist's own picture comes first; without one, the cover of their latest release stands in.
                $model instanceof Artist => ArtistImageController::url($model) ?? ($artistCovers[$model->id] ?? null),
                default => null,
            },
        ])->all());
    }

    private static function cover(Release $release): ?string
    {
        return $release->cover ? route('release-files.show', ['release_file' => $release->cover->id, 'size' => 'thumb']) : null;
    }

    /**
     * The cover of the latest release of each artist that has one.
     *
     * @param  Collection<int, covariant Model>  $artists
     * @return array<int, string>
     */
    private function artistCovers(Collection $artists): array
    {
        return Release::query()->whereIn('artist_id', $artists->map->getKey()->all())->has('cover')->with('cover:id,release_id')
            ->orderByDesc('released_on')->orderByDesc('id')->get(['id', 'artist_id'])
            ->unique('artist_id')
            ->mapWithKeys(fn (Release $release): array => [(int) $release->artist_id => (string) self::cover($release)])
            ->all();
    }
}
