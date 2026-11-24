<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Makes one track of the tracks of a label that share an ISRC.
 *
 * The track that stays is the one with a contract, then the one with the
 * most transactions. It is put on the releases of the others, takes their
 * transactions, items, files, plays and credits, and keeps their titles as
 * other spellings. No transaction changes its contract: where a merged
 * track resolved to another contract than the one that stays, that contract
 * is written on its items.
 */
class RecordingMerge
{
    /** What points at a track and can simply follow it. */
    private const array FOLLOWING = ['items', 'transactions', 'release_files', 'airplays', 'airplay_searches', 'platform_links', 'play_counts'];

    /** The same, where a row may be there for both tracks: table => what makes a row the same. */
    private const array FOLLOWING_ONCE = [
        'release_track' => ['release_id'],
        'airplay_findings' => ['url_hash'],
        'artist_track' => ['artist_id', 'role'],
        'agreement_track' => ['agreement_id'],
    ];

    /** Details the track that stays takes over when it has none of its own. */
    private const array FILLED = ['artist_id', 'iswc', 'duration', 'version', 'genre', 'language', 'explicit', 'bpm', 'musical_key', 'preview_start', 'p_line', 'credits', 'lyrics', 'researched_at', 'ai_recording', 'ai_composition', 'ai_lyrics'];

    /**
     * @return list<string> One line per merged recording.
     */
    public static function run(): array
    {
        $doubled = DB::table('tracks')->whereNotNull('isrc')->where('isrc', '<>', '')
            ->select(['label_id', 'isrc'])->groupBy('label_id', 'isrc')->havingRaw('count(*) > 1')->get();

        return array_values($doubled->map(fn (object $recording): string => DB::transaction(fn (): string => self::merge((int) $recording->label_id, (string) $recording->isrc)))->all());
    }

    private static function merge(int $labelId, string $isrc): string
    {
        $entries = DB::table('tracks')->where('label_id', $labelId)->where('isrc', $isrc)
            ->selectRaw('tracks.*, (select count(*) from transactions where transactions.track_id = tracks.id) as booked')
            ->orderByRaw('contract_id is null')->orderByDesc('booked')->orderBy('id')->get();
        $kept = $entries->first();
        $aliases = json_decode((string) ($kept->aliases ?? '[]'), true) ?: [];
        $filled = [];
        $pinned = 0;

        foreach ($entries->skip(1) as $entry) {
            $pinned += self::keepContracts($entry, $kept);

            foreach ([$entry->name, ...(json_decode((string) ($entry->aliases ?? '[]'), true) ?: [])] as $name) {
                if ($name !== $kept->name && ! in_array($name, $aliases, true)) {
                    $aliases[] = $name;
                }
            }

            foreach (self::FILLED as $column) {
                $filled[$column] ??= ($kept->{$column} ?? null) === null ? $entry->{$column} : null;
            }

            foreach (self::FOLLOWING_ONCE as $table => $same) {
                // Row by row: MySQL does not delete from a table it reads in the same statement.
                foreach (DB::table($table)->where('track_id', $kept->id)->get($same) as $there) {
                    DB::table($table)->where('track_id', $entry->id)->where((array) $there)->delete();
                }
                DB::table($table)->where('track_id', $entry->id)->update(['track_id' => $kept->id]);
            }

            foreach (self::FOLLOWING as $table) {
                DB::table($table)->where('track_id', $entry->id)->update(['track_id' => $kept->id]);
            }

            DB::table('tracks')->where('id', $entry->id)->delete();
        }

        DB::table('tracks')->where('id', $kept->id)->update([...array_filter($filled, fn (mixed $value): bool => $value !== null), 'aliases' => $aliases === [] ? null : json_encode($aliases)]);

        return sprintf('%s "%s": tracks %s are now track %d%s', $isrc, $kept->name, $entries->skip(1)->pluck('id')->implode(', '), $kept->id, $pinned > 0 ? ", {$pinned} items and transactions keep their contract" : '');
    }

    /**
     * Write the contract a merged track resolved to on its items, and on its
     * transactions without an item, where the track that stays would give another.
     * Where releases still have a contract (before the migration that removes it)
     * that counts too; afterwards only the contracts of the tracks do.
     */
    private static function keepContracts(stdClass $entry, stdClass $kept): int
    {
        $pinned = 0;
        $releaseContracts = Schema::hasColumn('releases', 'contract_id');

        foreach (['items' => 'id', 'transactions' => 'item_id'] as $table => $withoutItem) {
            $rows = DB::table($table)->where("{$table}.track_id", $entry->id)->whereNull("{$table}.contract_id")
                ->when($table === 'transactions', fn ($query) => $query->whereNull("{$table}.{$withoutItem}"))
                ->when($releaseContracts, fn ($query) => $query->leftJoin('releases', 'releases.id', '=', "{$table}.release_id"))
                ->get(["{$table}.id", ...($releaseContracts ? ['releases.contract_id as release_contract_id'] : [])]);

            foreach ($rows as $row) {
                $before = $entry->contract_id ?? $row->release_contract_id ?? null;

                if ($before !== null && $before !== ($kept->contract_id ?? $row->release_contract_id ?? null)) {
                    $pinned += DB::table($table)->where('id', $row->id)->update(['contract_id' => $before]);
                }
            }
        }

        return $pinned;
    }
}
