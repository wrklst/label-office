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
use Illuminate\Support\Facades\DB;

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
        // A record is not offered as its own parent or alias, nor again beside rows that already chose it: one id or several with commas.
        $excluded = $filtered ? array_values(array_filter(array_map(intval(...), explode(',', $request->string('exclude')->toString())))) : [];

        // What a chosen contract pays for; with only a payee chosen, what any of their contracts pays for.
        $contracts = match (true) {
            $contractId > 0 => [$contractId],
            $payeeId > 0 && in_array($records, ['releases', 'tracks'], true) => Contract::query()->whereHas('splits', fn (Builder $splits) => $splits->where('payee_id', $payeeId))->pluck('id')->all(),
            default => [],
        };

        $query = match ($records) {
            // First the releases with a track a contract is set on.
            'releases' => $this->narrowed(
                Release::query()->with(['cover:id,release_id', 'artist:id,name'])->orderBy('name')->orderBy('id'),
                $contracts ? fn (Builder $releases) => $releases->whereHas('tracks', fn (Builder $tracks) => $tracks->whereIn('tracks.contract_id', $contracts)) : null,
                $excluded,
            ),
            // A dropdown of tracks is narrowed to the release chosen beside it, and lists what a contract pays for first.
            'tracks' => $this->narrowed(
                Track::query()->with(['release:id,name', 'release.cover:id,release_id'])
                    ->when($releaseId, fn (Builder $tracks) => $tracks->onRelease($releaseId))
                    ->orderBy('name')->orderBy('id'),
                $contracts ? fn (Builder $tracks) => $tracks->whereIn('contract_id', $contracts) : null,
                $excluded,
            ),
            'artists' => Artist::query()->orderBy('name')->orderBy('id'),
            'payees' => Payee::query()->orderBy('name')->orderBy('id'),
            // First the contracts a payee has a split on.
            'contracts' => $this->narrowed(
                Contract::query()->orderBy('name')->orderBy('id'),
                $payeeId ? fn (Builder $contracts) => $contracts->whereHas('splits', fn (Builder $splits) => $splits->where('payee_id', $payeeId)) : null,
                $excluded,
            ),
            'ledgers' => Ledger::query()->orderBy('name')->orderBy('id'),
            'territories' => Territory::query()->orderBy('name')->orderBy('id'),
            'periods' => Period::query()->orderByDesc('ends_on')->orderByDesc('id'),
            default => AgreementTemplate::query()->orderBy('name')->orderBy('id'),
        };

        return $excluded ? $query->whereKeyNot($excluded) : $query;
    }

    /**
     * Put what belongs to something chosen beside it first, and everything
     * else after it. Nothing is hidden: what a contract pays for today need
     * not be all it is meant for, so the rest stays within reach.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  (\Closure(Builder<TModel>): mixed)|null  $related
     * @param  list<int>  $excluded
     * @return Builder<TModel>
     */
    private function narrowed(Builder $query, ?\Closure $related, array $excluded = []): Builder
    {
        $query = $excluded ? $query->whereKeyNot($excluded) : $query;

        if ($related === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $ids = $query->getModel()->newQuery()->tap($related)->select("{$table}.id");

        return $query->reorder()->select("{$table}.*")
            ->addSelect(['related' => DB::query()->selectRaw('count(*)')->fromSub($ids, 'related_records')->whereColumn('related_records.id', "{$table}.id")])
            ->orderByDesc('related')->orderBy('name')->orderBy('id');
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
            // Whether it belongs to what is chosen beside the dropdown; null when nothing is.
            'related' => $model->getAttribute('related') === null ? null : (bool) $model->getAttribute('related'),
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
