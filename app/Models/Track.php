<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLabel;
use Carbon\CarbonImmutable;
use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $label_id
 * @property int $release_id The release the track came out on first; every release it is on is in releases().
 * @property int|null $artist_id
 * @property int|null $contract_id
 * @property string $name
 * @property list<string>|null $aliases Other spellings of the title, as statements and platforms write it.
 * @property string|null $isrc
 * @property string|null $iswc
 * @property int|null $track_number
 * @property int|null $duration
 * @property bool|null $accounts_mechanicals Null follows the release.
 * @property string|float|int|null $mechanicals_rate
 * @property string|float|int|null $ppd_rate
 * @property int|null $disc_number
 * @property string|null $version
 * @property string|null $genre
 * @property string|null $language
 * @property string|null $explicit
 * @property string|float|int|null $bpm
 * @property string|null $musical_key
 * @property int|null $preview_start
 * @property string|null $p_line
 * @property array<string, list<string>>|null $credits
 * @property string|null $lyrics
 * @property string|null $ai_recording How much of the recording AI made: "none", "partly", "all"; null is not declared.
 * @property string|null $ai_composition
 * @property string|null $ai_lyrics
 * @property CarbonImmutable|null $researched_at
 * @property string|null $research_state
 * @property string|null $research_step
 * @property CarbonImmutable|null $research_state_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['label_id', 'release_id', 'artist_id', 'contract_id', 'name', 'aliases', 'isrc', 'iswc', 'track_number', 'duration', 'accounts_mechanicals', 'mechanicals_rate', 'ppd_rate', 'disc_number', 'version', 'genre', 'language', 'explicit', 'bpm', 'musical_key', 'preview_start', 'p_line', 'credits', 'lyrics', 'researched_at', 'ai_recording', 'ai_composition', 'ai_lyrics'])]
class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use BelongsToLabel, HasFactory;

    /**
     * Items of a deleted track stay on the release when they carry
     * transactions; empty ones go with the track.
     */
    protected static function booted(): void
    {
        // ponytail: release_id, track_number and disc_number stay on the track for its first release; the list of releases is kept in step here.
        static::saved(function (Track $track): void {
            if ($track->wasRecentlyCreated || $track->wasChanged(['release_id', 'track_number', 'disc_number'])) {
                DB::table('release_track')->updateOrInsert(
                    ['release_id' => $track->release_id, 'track_id' => $track->id],
                    ['track_number' => $track->track_number, 'disc_number' => $track->disc_number],
                );
            }
        });

        static::deleting(function (Track $track): void {
            $track->items()->doesntHave('transactions')->delete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accounts_mechanicals' => 'boolean',
            'aliases' => 'array',
            'credits' => 'array',
            'researched_at' => 'immutable_datetime',
            'research_state_at' => 'immutable_datetime',
        ];
    }

    /**
     * Every artist credited on the track, main artists first.
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
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * Every release the track is on, with its number there.
     *
     * @return BelongsToMany<Release, $this>
     */
    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class)->withPivot(['track_number', 'disc_number']);
    }

    /**
     * Only the tracks that are on one of these releases.
     *
     * @param  Builder<Track>  $query
     * @param  int|list<int>  $releaseIds
     */
    public function scopeOnRelease(Builder $query, int|array $releaseIds): void
    {
        $query->whereIn('tracks.id', DB::table('release_track')->whereIn('release_id', (array) $releaseIds)->select('track_id'));
    }

    /**
     * Put the track on a release, or change its number there.
     */
    public function putOn(int $releaseId, ?int $trackNumber = null, ?int $discNumber = null): void
    {
        if ($releaseId === $this->release_id) {
            $this->update(['track_number' => $trackNumber, 'disc_number' => $discNumber ?? $this->disc_number]);

            return;
        }

        DB::table('release_track')->updateOrInsert(['release_id' => $releaseId, 'track_id' => $this->id], ['track_number' => $trackNumber, ...($discNumber === null ? [] : ['disc_number' => $discNumber])]);
    }

    /**
     * Take the track off a release. A track that is on no other release goes with it.
     */
    public function takeOff(int $releaseId): void
    {
        $other = DB::table('release_track')->where('track_id', $this->id)->where('release_id', '<>', $releaseId)->orderBy('release_id')->first();

        if ($other === null) {
            $this->delete();

            return;
        }

        DB::table('release_track')->where('track_id', $this->id)->where('release_id', $releaseId)->delete();

        if ($this->release_id === $releaseId) {
            $this->update(['release_id' => $other->release_id, 'track_number' => $other->track_number, 'disc_number' => $other->disc_number]);
        }
    }

    /**
     * The release a transaction or item of this track is booked on: the one
     * named when the track is on it, otherwise the track's first release.
     */
    public function releaseFor(?int $releaseId): int
    {
        return $releaseId !== null && DB::table('release_track')->where('track_id', $this->id)->where('release_id', $releaseId)->exists() ? $releaseId : $this->release_id;
    }

    /**
     * Keep another spelling of the title, so a file that uses it finds the track.
     */
    public function addAlias(?string $name): void
    {
        $name = trim((string) $name);

        if ($name !== '' && $name !== $this->name && ! in_array($name, $this->aliases ?? [], true)) {
            $this->update(['aliases' => [...($this->aliases ?? []), $name]]);
        }
    }

    /**
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The ids of every entry of this recording. A recording is one track
     * on several releases; entries with the same ISRC that were not merged
     * still count as one.
     *
     * @return list<int>
     */
    public function recordingIds(): array
    {
        if ($this->isrc === null) {
            return [$this->id];
        }

        return array_values(self::query()->withoutGlobalScope('label')->where('label_id', $this->label_id)->where('isrc', $this->isrc)->orderBy('id')->pluck('id')->all());
    }

    /**
     * @return HasMany<Airplay, $this>
     */
    public function airplays(): HasMany
    {
        return $this->hasMany(Airplay::class);
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
}
