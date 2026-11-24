<?php

namespace App\Services\Imports;

use App\Models\Artist;
use App\Models\Item;
use App\Models\Label;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

/**
 * Links statement lines to the catalog and builds missing entries.
 *
 * A release is found by UPC/EAN, then catalog number. A track is found by
 * its ISRC anywhere in the catalog, and put on the release when it is not
 * on it yet; without an ISRC by its title or another spelling of it within
 * the release. Whatever is missing gets created from the
 * metadata in the file, so a first import builds the whole catalog. Lines
 * that carry too little to identify a release end up on an unassigned item
 * for manual review instead of being guessed.
 */
class CatalogMatcher
{
    /** @var array<string, int> */
    private array $releasesByUpc = [];

    /** @var array<string, int> */
    private array $releasesByCatalog = [];

    /** @var array<string, int> */
    private array $releasesByName = [];

    /** @var array<string, true> "releaseId|trackId" for every release a track is on */
    private array $tracksOnRelease = [];

    /** @var array<int, array<string, true>> track id => the titles it is known by */
    private array $trackNames = [];

    /** @var array<string, int> "releaseId|name" => track id */
    private array $tracksByReleaseName = [];

    /** @var array<string, array{0: int, 1: int}> isrc => [track id, release id] */
    private array $tracksByIsrc = [];

    /** @var array<int, true> Releases that have no UPC yet. */
    private array $releasesWithoutUpc = [];

    /** @var array<int, true> Tracks that have no ISRC yet. */
    private array $tracksWithoutIsrc = [];

    /** @var array<string, int> */
    private array $artists = [];

    /** @var array<string, int> */
    private array $items = [];

    public int $createdReleases = 0;

    public int $createdTracks = 0;

    public function __construct(private readonly Label $label, private readonly ?int $dataImportId = null)
    {
        Release::query()->where('label_id', $label->id)->orderBy('id')
            ->get(['id', 'name', 'upc', 'catalog_number'])
            ->each(fn (Release $release) => $this->rememberRelease($release));

        Track::query()->where('label_id', $label->id)->orderBy('id')
            ->get(['id', 'release_id', 'name', 'aliases', 'isrc'])
            ->each(fn (Track $track) => $this->rememberTrack($track));

        DB::table('release_track')->join('tracks', 'tracks.id', '=', 'release_track.track_id')->where('tracks.label_id', $label->id)
            ->orderBy('release_track.track_id')->get(['release_track.release_id', 'release_track.track_id'])
            ->each(fn (object $row) => $this->rememberOnRelease((int) $row->release_id, (int) $row->track_id));

        // An alias is another spelling a statement uses: what it names belongs to the main artist.
        Artist::query()->where('label_id', $label->id)->orderBy('id')->get(['id', 'name', 'alias_of_id'])
            ->each(function (Artist $artist): void {
                $this->artists[Identifiers::nameKey($artist->name)] ??= $artist->alias_of_id ?? $artist->id;
            });

        Item::query()->where('label_id', $label->id)->orderBy('id')
            ->get(['id', 'release_id', 'track_id', 'format_group', 'isrc', 'upc', 'name', 'is_additionals'])
            ->each(function (Item $item): void {
                if (! $item->is_additionals) {
                    $this->items[$this->itemKey($item->release_id, $item->track_id, $item->format_group, $item->isrc, $item->upc, $item->name)] ??= $item->id;
                }
            });
    }

    /**
     * @return array{release_id: int|null, track_id: int|null, item_id: int|null}
     */
    public function match(ImportRow $row, ?string $formatGroup): array
    {
        $hasCatalogData = $row->upc || $row->isrc || $row->catalogNumber || $row->releaseName || $row->trackName;

        if (! $hasCatalogData) {
            return ['release_id' => null, 'track_id' => null, 'item_id' => null];
        }

        $releaseId = $this->findRelease($row);
        $trackId = null;

        $namesRelease = $row->upc || $row->catalogNumber || $row->releaseName;

        if ($releaseId === null && ! $namesRelease && $row->isrc && isset($this->tracksByIsrc[$row->isrc])) {
            [$trackId, $releaseId] = $this->tracksByIsrc[$row->isrc];
        }

        if ($releaseId === null && $namesRelease) {
            $releaseId = $this->createRelease($row);
        }

        if ($releaseId !== null && $trackId === null && ($row->isrc || $row->trackName)) {
            $trackId = $this->findTrack($releaseId, $row) ?? $this->createTrack($releaseId, $row);
        }

        return [
            'release_id' => $releaseId,
            'track_id' => $trackId,
            'item_id' => $this->item($releaseId, $trackId, $formatGroup, $row),
        ];
    }

    private function findRelease(ImportRow $row): ?int
    {
        if ($row->upc && isset($this->releasesByUpc[Identifiers::upcKey($row->upc)])) {
            return $this->releasesByUpc[Identifiers::upcKey($row->upc)];
        }

        $catalogKey = Identifiers::catalogKey($row->catalogNumber);

        if ($catalogKey && isset($this->releasesByCatalog[$catalogKey])) {
            $releaseId = $this->releasesByCatalog[$catalogKey];

            if (! $row->upc) {
                return $releaseId;
            }

            // A release entered by hand has its catalog number before its UPC:
            // the first statement naming both completes it.
            if (isset($this->releasesWithoutUpc[$releaseId])) {
                Release::query()->withoutGlobalScopes()->whereKey($releaseId)->update(['upc' => $row->upc]);
                unset($this->releasesWithoutUpc[$releaseId]);
                $this->releasesByUpc[Identifiers::upcKey($row->upc)] = $releaseId;

                return $releaseId;
            }
        }

        $nameKey = Identifiers::nameKey($row->releaseName);

        if (! $row->upc && ! $catalogKey && $nameKey && isset($this->releasesByName[$nameKey])) {
            return $this->releasesByName[$nameKey];
        }

        return null;
    }

    private function findTrack(int $releaseId, ImportRow $row): ?int
    {
        // A recording is one track on every release it is on.
        if ($row->isrc && isset($this->tracksByIsrc[$row->isrc])) {
            $trackId = $this->tracksByIsrc[$row->isrc][0];

            if (! isset($this->tracksOnRelease["{$releaseId}|{$trackId}"])) {
                DB::table('release_track')->insertOrIgnore(['release_id' => $releaseId, 'track_id' => $trackId]);
            }

            if (($key = Identifiers::nameKey($row->trackName)) && ! isset($this->trackNames[$trackId][$key])) {
                Track::query()->withoutGlobalScopes()->find($trackId)?->addAlias($row->trackName);
                $this->trackNames[$trackId][$key] = true;
            }

            $this->rememberOnRelease($releaseId, $trackId);

            return $trackId;
        }

        $trackId = $this->tracksByReleaseName[$releaseId.'|'.Identifiers::nameKey($row->trackName)] ?? null;

        if (! $row->isrc || $trackId === null) {
            return $row->isrc ? null : $trackId;
        }

        // The same for a track entered by hand without its ISRC.
        if (! isset($this->tracksWithoutIsrc[$trackId])) {
            return null;
        }

        Track::query()->withoutGlobalScopes()->whereKey($trackId)->update(['isrc' => $row->isrc]);
        unset($this->tracksWithoutIsrc[$trackId]);
        $this->tracksByIsrc[$row->isrc] ??= [$trackId, $releaseId];

        return $trackId;
    }

    private function createRelease(ImportRow $row): int
    {
        $release = Release::query()->create([
            'label_id' => $this->label->id,
            'artist_id' => $this->artist($row->releaseArtist ?: $row->artist),
            'name' => $row->releaseName ?: $row->catalogNumber ?: $row->upc,
            'catalog_number' => $row->catalogNumber ?: null,
            'upc' => $row->upc,
        ]);

        $this->rememberRelease($release);
        $this->createdReleases++;

        return $release->id;
    }

    private function createTrack(int $releaseId, ImportRow $row): int
    {
        $track = Track::query()->create([
            'label_id' => $this->label->id,
            'release_id' => $releaseId,
            'artist_id' => $this->artist($row->artist),
            'name' => $row->trackName ?: $row->isrc,
            'isrc' => $row->isrc,
        ]);

        $this->rememberTrack($track);
        $this->rememberOnRelease($releaseId, $track->id);
        $this->createdTracks++;

        return $track->id;
    }

    private function artist(?string $name): ?int
    {
        $key = Identifiers::nameKey($name);

        if ($key === null) {
            return null;
        }

        return $this->artists[$key] ??= Artist::query()->create([
            'label_id' => $this->label->id,
            'name' => trim((string) $name),
        ])->id;
    }

    private function item(?int $releaseId, ?int $trackId, ?string $formatGroup, ImportRow $row): int
    {
        $name = $row->trackName ?: $row->releaseName ?: $row->isrc ?: $row->upc ?: $row->catalogNumber ?: 'Unknown';
        $key = $this->itemKey($releaseId, $trackId, $formatGroup, $row->isrc, $row->upc, $name);

        return $this->items[$key] ??= Item::query()->create([
            'label_id' => $this->label->id,
            'release_id' => $releaseId,
            'track_id' => $trackId,
            'data_import_id' => $this->dataImportId,
            'format_group' => $formatGroup,
            'name' => $name,
            'artist_name' => $row->artist ?: $row->releaseArtist,
            'track_name' => $row->trackName,
            'catalog_number' => $row->catalogNumber ?: null,
            'isrc' => $row->isrc,
            'upc' => $row->upc,
        ])->id;
    }

    private function itemKey(?int $releaseId, ?int $trackId, ?string $formatGroup, ?string $isrc, ?string $upc, ?string $name): string
    {
        if ($releaseId !== null) {
            return implode('|', ['r', $releaseId, $trackId, $formatGroup]);
        }

        return implode('|', ['u', $isrc, Identifiers::upcKey($upc), $isrc || $upc ? '' : Identifiers::nameKey($name), $formatGroup]);
    }

    private function rememberRelease(Release $release): void
    {
        if ($key = Identifiers::upcKey($release->upc)) {
            $this->releasesByUpc[$key] ??= $release->id;
        } else {
            $this->releasesWithoutUpc[$release->id] = true;
        }

        if ($key = Identifiers::catalogKey($release->catalog_number)) {
            $this->releasesByCatalog[$key] ??= $release->id;
        }

        if ($key = Identifiers::nameKey($release->name)) {
            $this->releasesByName[$key] ??= $release->id;
        }
    }

    private function rememberTrack(Track $track): void
    {
        if ($isrc = Identifiers::isrc($track->isrc)) {
            $this->tracksByIsrc[$isrc] ??= [$track->id, $track->release_id];
        } else {
            $this->tracksWithoutIsrc[$track->id] = true;
        }

        foreach ([$track->name, ...($track->aliases ?? [])] as $name) {
            if ($key = Identifiers::nameKey($name)) {
                $this->trackNames[$track->id][$key] = true;
            }
        }
    }

    /**
     * A track is found on a release by its title and by every other spelling of it.
     */
    private function rememberOnRelease(int $releaseId, int $trackId): void
    {
        $this->tracksOnRelease["{$releaseId}|{$trackId}"] = true;

        foreach (array_keys($this->trackNames[$trackId] ?? []) as $key) {
            $this->tracksByReleaseName["{$releaseId}|{$key}"] ??= $trackId;
        }
    }
}
