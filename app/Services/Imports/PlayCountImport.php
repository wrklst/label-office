<?php

namespace App\Services\Imports;

use App\Models\Label;
use App\Models\PlayCount;
use App\Services\Imports\Readers\ReaderFactory;
use App\Support\Platforms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads what a platform's artist portal or a distributor's analytics
 * export into play counts. The portals do not share a layout and change
 * theirs without notice, so a file is read by what its columns are
 * called, and nothing has to be said about it.
 */
class PlayCountImport
{
    /**
     * Columns that give a portal away when its file names it nowhere:
     * platform => headers only its export has together, in lower case.
     * ponytail: Spotify only, from its Songs export; add a portal when one of its files comes in as "Unknown".
     *
     * @var array<string, list<string>>
     */
    private const array SIGNATURES = [
        'spotify' => ['song', 'listeners', 'streams', 'saves'],
    ];

    /**
     * What the portals call a column: column => headers, in lower case.
     *
     * @var array<string, list<string>>
     */
    private const array HEADERS = [
        'track' => ['track', 'song', 'title', 'name', 'track name', 'song name', 'track title', 'song title'],
        'plays' => ['plays', 'streams', 'play count', 'playcount', 'total plays', 'total streams', 'listens', 'views'],
        'listeners' => ['listeners', 'unique listeners'],
        'artist' => ['artist', 'artists', 'artist name'],
        'isrc' => ['isrc'],
        'platform' => ['platform', 'service', 'store', 'retailer', 'dsp', 'shop'],
        'date' => ['date', 'day'],
        'from' => ['from', 'start', 'start date', 'period start'],
        'to' => ['to', 'end', 'end date', 'period end'],
    ];

    public function __construct(private readonly ReaderFactory $readers) {}

    /**
     * Store the play counts of a file. A count replaces earlier ones of
     * the same track on the same platform whose period overlaps its own,
     * so a newer export never counts plays twice. A file without dates
     * is the total up to today and replaces everything before it.
     *
     * The platform is the one each line names, else the one in the file
     * name, else the one its columns give away, else "Unknown", which
     * is also right for a distributor's export of all platforms together.
     *
     * @return array{batch: string, counts: int, matched: int, unmatched: list<string>}
     *
     * @throws ImportException when the file cannot be read as play counts; nothing is stored then
     */
    public function import(Label $label, string $path, string $filename): array
    {
        $reader = $this->readers->open($path);
        $found = $reader->locateHeader(function (array $headers): bool {
            $index = self::index($headers);

            return isset($index['plays']) && (isset($index['track']) || isset($index['isrc']));
        });

        if (! $found) {
            throw new ImportException('The file needs a column with the play count, called "plays" or "streams", and one naming the track, called "track", "song" or "isrc". Found: '.(implode(', ', array_filter($reader->headers())) ?: 'no header row').'.');
        }

        $index = self::index($reader->headers());
        $catalog = $this->catalog($label);
        $batch = (string) Str::ulid();
        $platform = self::platform($filename, array_map(strval(...), $reader->headers()));
        $counts = [];
        $dated = [];

        foreach ($reader->rows() as $line => $values) {
            $row = new Row($line, $values, $index);
            $plays = $row->decimal('plays');
            $name = $row->string('track');
            $isrc = $row->isrc('isrc');

            // Totals and empty lines name no track or no count.
            if ($plays === null || ($name === null && $isrc === null)) {
                continue;
            }

            try {
                $start = $row->date('date') ?? $row->date('from');
                $end = $row->date('date') ?? $row->date('to') ?? $start ?? now()->toDateString();
            } catch (RowException $exception) {
                throw new ImportException("Line {$line}: {$exception->getMessage()}");
            }

            $named = $row->string('platform');
            $key = $named === null ? $platform : Platforms::of($named)['key'];

            // ponytail: a platform this installation has no name for is counted as not named; store its name when one matters.
            if (! isset(Platforms::names()[$key])) {
                $key = Platforms::of(null)['key'];
            }

            $dated[] = $start !== null;
            $counts[] = [
                'label_id' => $label->id,
                'track_id' => $this->match($catalog, $isrc, $name, $row->string('artist')),
                'batch' => $batch,
                'source_file' => mb_substr($filename, 0, 255),
                'platform' => $key,
                'track_name' => mb_substr($name ?? (string) $isrc, 0, 255),
                'artist_name' => $row->string('artist') === null ? null : mb_substr($row->string('artist'), 0, 255),
                'isrc' => $isrc,
                'period_start' => min($start ?? $end, $end),
                'period_end' => max($start ?? $end, $end),
                'plays' => max(0, (int) round((float) $plays)),
                'listeners' => $row->decimal('listeners') === null ? null : max(0, (int) round((float) $row->decimal('listeners'))),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($counts === []) {
            throw new ImportException('The file has no line with a track and a play count.');
        }

        // One span per track and platform: daily exports have tens of thousands of lines.
        $spans = [];

        foreach ($counts as $at => $count) {
            $key = $count['platform'].'|'.($count['track_id'] ?? 'name:'.$count['track_name']);
            $from = $dated[$at] ? $count['period_start'] : '0001-01-01';
            $spans[$key] = [...$count, 'period_start' => min($spans[$key]['period_start'] ?? $from, $from), 'period_end' => max($spans[$key]['period_end'] ?? $count['period_end'], $count['period_end'])];
        }

        DB::transaction(function () use ($counts, $spans, $label): void {
            foreach ($spans as $count) {
                PlayCount::query()->withoutGlobalScope('label')->where('label_id', $label->id)
                    ->where('platform', $count['platform'])
                    ->when($count['track_id'] !== null, fn ($query) => $query->where('track_id', $count['track_id']), fn ($query) => $query->whereNull('track_id')->where('track_name', $count['track_name']))
                    ->where('period_start', '<=', $count['period_end'])->where('period_end', '>=', $count['period_start'])
                    ->delete();
            }

            foreach (array_chunk($counts, 500) as $chunk) {
                DB::table('play_counts')->insert($chunk);
            }
        });

        $unmatched = array_values(array_unique(array_column(array_filter($counts, fn (array $count): bool => $count['track_id'] === null), 'track_name')));

        return ['batch' => $batch, 'counts' => count($counts), 'matched' => count(array_filter($counts, fn (array $count): bool => $count['track_id'] !== null)), 'unmatched' => $unmatched];
    }

    /**
     * @param  list<string>  $headers  As the file spells them.
     */
    private static function platform(string $filename, array $headers): string
    {
        $named = Platforms::of(pathinfo($filename, PATHINFO_FILENAME))['key'];

        if (isset(Platforms::names()[$named])) {
            return $named;
        }

        $headers = array_map(Row::normalize(...), $headers);

        foreach (self::SIGNATURES as $key => $signature) {
            if (array_diff($signature, $headers) === []) {
                return $key;
            }
        }

        return Platforms::of(null)['key'];
    }

    /**
     * The label's recordings by ISRC and by name. The same recording on
     * several releases is one, under its oldest entry.
     *
     * @return array{isrc: array<string, int>, name: array<string, array<string, array{id: int, artist: string}>>}
     */
    private function catalog(Label $label): array
    {
        $catalog = ['isrc' => [], 'name' => []];
        $tracks = DB::table('tracks')->leftJoin('artists', 'artists.id', '=', 'tracks.artist_id')->where('tracks.label_id', $label->id)
            ->orderBy('tracks.id')->get(['tracks.id', 'tracks.name', 'tracks.aliases', 'tracks.version', 'tracks.isrc', 'artists.name as artist']);

        foreach ($tracks as $track) {
            $isrc = Identifiers::isrc($track->isrc);
            $id = $isrc === null ? $track->id : ($catalog['isrc'][$isrc] ??= $track->id);

            foreach (array_unique(array_map(self::plain(...), [$track->name, $track->name.' '.$track->version, ...(json_decode((string) $track->aliases, true) ?: [])])) as $name) {
                $catalog['name'][$name][$isrc ?? 'track-'.$track->id] ??= ['id' => $id, 'artist' => self::plain((string) $track->artist)];
            }
        }

        return $catalog;
    }

    /**
     * The track a line means: by its ISRC, or by a name only one
     * recording has, or among recordings of one name by the artist.
     *
     * @param  array{isrc: array<string, int>, name: array<string, array<string, array{id: int, artist: string}>>}  $catalog
     */
    private function match(array $catalog, ?string $isrc, ?string $name, ?string $artist): ?int
    {
        if ($isrc !== null && isset($catalog['isrc'][$isrc])) {
            return $catalog['isrc'][$isrc];
        }

        $named = array_values($catalog['name'][self::plain((string) $name)] ?? []);

        if (count($named) > 1 && $artist !== null) {
            $named = array_values(array_filter($named, fn (array $track): bool => $track['artist'] !== '' && Str::contains(self::plain($artist), $track['artist'])));
        }

        return count($named) === 1 ? $named[0]['id'] : null;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string> Column => header in the file.
     */
    private static function index(array $headers): array
    {
        $index = [];

        foreach ($headers as $header) {
            foreach (self::HEADERS as $column => $names) {
                if (in_array(Row::normalize((string) $header), $names, true)) {
                    $index[$column] ??= $header;
                }
            }
        }

        return $index;
    }

    /**
     * A name without its punctuation: "Track - Remix" and "Track (Remix)" are one.
     */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($text)));
    }
}
