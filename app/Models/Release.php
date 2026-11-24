<?php

namespace App\Models;

use App\Enums\ReleaseFileKind;
use App\Enums\ReleaseStatus;
use App\Enums\ReleaseType;
use App\Models\Concerns\BelongsToLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property-read Pivot&object{track_number: int|null, disc_number: int|null} $pivot Its place on the release when loaded through Track::releases().
 * @property int $label_id
 * @property int|null $artist_id
 * @property string $name
 * @property string|null $catalog_number
 * @property string|null $upc
 * @property string|null $image_path
 * @property CarbonImmutable|null $released_on
 * @property string|null $spotify_id
 * @property string|null $discogs_id
 * @property bool $accounts_mechanicals
 * @property string|float|int|null $mechanicals_rate
 * @property string|float|int|null $ppd_rate
 * @property string|float|int|null $reserves_rate
 * @property string|null $notes
 * @property ReleaseStatus $status
 * @property ReleaseType|null $release_type
 * @property string|null $version
 * @property string|null $genre
 * @property string|null $subgenre
 * @property string|null $language
 * @property string|null $explicit
 * @property string|null $p_line
 * @property string|null $c_line
 * @property CarbonImmutable|null $original_released_on
 * @property CarbonImmutable|null $preorder_on
 * @property string|null $press_release
 * @property string|null $key_facts
 * @property string|null $todo
 * @property string|null $files_url
 * @property list<string>|null $blocked_services
 * @property list<string>|null $blocked_territories
 * @property string|null $ai_artwork How much of the artwork AI made: "none", "partly", "all"; null is not declared.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['label_id', 'artist_id', 'name', 'catalog_number', 'upc', 'image_path', 'released_on', 'spotify_id', 'discogs_id', 'accounts_mechanicals', 'mechanicals_rate', 'ppd_rate', 'reserves_rate', 'notes', 'status', 'release_type', 'version', 'genre', 'subgenre', 'language', 'explicit', 'p_line', 'c_line', 'original_released_on', 'preorder_on', 'press_release', 'key_facts', 'todo', 'files_url', 'blocked_services', 'blocked_territories', 'ai_artwork'])]
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use BelongsToLabel, HasFactory;

    /**
     * Every release gets an "[Additionals]" item that carries income and
     * costs entered by hand rather than imported for a specific product.
     */
    protected static function booted(): void
    {
        static::created(function (Release $release): void {
            $release->items()->create([
                'label_id' => $release->label_id,
                'is_additionals' => true,
                'name' => '[Additionals]',
                'catalog_number' => $release->catalog_number,
                'upc' => $release->upc,
            ]);
        });

        static::updated(function (Release $release): void {
            if ($release->wasChanged(['catalog_number', 'upc'])) {
                $release->items()->where('is_additionals', true)->update([
                    'catalog_number' => $release->catalog_number,
                    'upc' => $release->upc,
                ]);
            }
        });

        // Items that carry transactions stay behind as unassigned items so
        // their income can be given to another release; empty ones go.
        static::deleting(function (Release $release): void {
            // A track that is also on another release stays, and counts as first released there.
            DB::table('release_track')->join('tracks', 'tracks.id', '=', 'release_track.track_id')
                ->where('tracks.release_id', $release->id)->where('release_track.release_id', '<>', $release->id)
                ->orderByDesc('release_track.release_id')->get(['release_track.*'])
                ->each(fn (object $other) => DB::table('tracks')->where('id', $other->track_id)
                    ->update(['release_id' => $other->release_id, 'track_number' => $other->track_number, 'disc_number' => $other->disc_number]));

            // Stored files are removed one by one so each is deleted from disk.
            $release->files()->get()->each->delete();

            $release->items()->doesntHave('transactions')->delete();
            $release->items()->where('is_additionals', true)->update([
                'is_additionals' => false,
                'name' => "[Additionals] {$release->name}",
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_on' => 'date:Y-m-d',
            'accounts_mechanicals' => 'boolean',
            'original_released_on' => 'date:Y-m-d',
            'preorder_on' => 'date:Y-m-d',
            'status' => ReleaseStatus::class,
            'release_type' => ReleaseType::class,
            'blocked_services' => 'array',
            'blocked_territories' => 'array',
        ];
    }

    /**
     * Every artist credited on the release, main artists first.
     *
     * @return BelongsToMany<Artist, $this>
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class)->withPivot(['role', 'position'])->withTimestamps()->orderByPivot('position');
    }

    /**
     * @return HasMany<ReleaseFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ReleaseFile::class);
    }

    /**
     * The newest file marked as cover.
     *
     * @return HasOne<ReleaseFile, $this>
     */
    public function cover(): HasOne
    {
        return $this->hasOne(ReleaseFile::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('kind', ReleaseFileKind::Cover));
    }

    /**
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Only the releases with a track that has no contract. A release without
     * tracks has nothing a contract could be set on and is not among them.
     *
     * @param  Builder<Release>  $query
     */
    public function scopeWithoutContract(Builder $query): void
    {
        $query->whereExists(fn ($tracks) => $tracks->from('release_track')->join('tracks', 'tracks.id', '=', 'release_track.track_id')
            ->whereColumn('release_track.release_id', 'releases.id')->whereNull('tracks.contract_id'));
    }

    /**
     * The tracks on the release in their order, each with the number it has
     * here: a track that is on several releases has one per release.
     *
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)
            ->select(['tracks.*', 'release_track.track_number as track_number', 'release_track.disc_number as disc_number'])
            ->orderBy('release_track.disc_number')->orderBy('release_track.track_number')->orderBy('tracks.id');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * What the contracts of the tracks add up to, for showing: the one
     * contract when every track has it; none when no track has a contract;
     * "mixed" when the tracks have several, or some have none. A release has
     * no contract of its own. Needs the tracks loaded with their contract.
     *
     * @return array{contract: array{id: int, name: string}|null, mixed: bool}
     */
    public function contractSummary(): array
    {
        $contractIds = $this->tracks->map(fn (Track $track): ?int => $track->contract_id)->unique();

        if ($contractIds->filter()->isEmpty()) {
            return ['contract' => null, 'mixed' => false];
        }

        if ($contractIds->count() > 1) {
            return ['contract' => null, 'mixed' => true];
        }

        $contract = $this->tracks->first()?->contract;

        return ['contract' => $contract === null ? null : ['id' => $contract->id, 'name' => $contract->name], 'mixed' => false];
    }
}
