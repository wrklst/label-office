<?php

namespace App\Services\Royalties\Tools;

use App\Enums\TransactionType;
use App\Models\Label;
use App\Models\Release;
use App\Models\Track;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

/**
 * Mechanical royalties owed to songwriters: units × a rate per track.
 *
 * - A track takes part when it is switched on, or when it says nothing
 *   and its release is switched on.
 * - Rate: the track's own, else the release's, else the US statutory
 *   rate for the track's duration from the table chosen for the run.
 * - Units: quantities of permanent downloads (and physical sales when the
 *   label asks for it) paid in the range, plus manufacturing runs entered
 *   for the run. A sale booked on the release counts for each of its
 *   tracks that take part.
 *
 * @phpstan-import-type Line from ToolRuns
 *
 * @phpstan-type Row array{
 *     release_id: int,
 *     release_name: string,
 *     track_id: int,
 *     track_name: string,
 *     sold: numeric-string,
 *     manufactured: numeric-string,
 *     units: numeric-string,
 *     rate: numeric-string,
 *     rate_source: string,
 *     amount: numeric-string
 * }
 * @phpstan-type Report array{
 *     rows: list<Row>,
 *     total: numeric-string,
 *     units: numeric-string,
 *     uncalculable: list<array{release_id: int, release_name: string, track_id: int, track_name: string, reason: string}>,
 *     unconverted: int,
 *     without_quantity: int,
 *     unused_manufacturing: list<string>
 * }
 */
class MechanicalsTool
{
    /** Recordings up to this many seconds earn the flat rate per work. */
    private const int PER_WORK_LIMIT = 300;

    public function __construct(private readonly ToolRuns $runs) {}

    /**
     * @return array<string, array{per_work: numeric-string, per_minute: numeric-string}>
     */
    public static function rateTables(): array
    {
        return Config::array('royalties.mechanicals.statutory_rates');
    }

    /**
     * The table for the current year, or the newest one there is.
     */
    public static function defaultRateTable(): string
    {
        $tables = array_map(strval(...), array_keys(self::rateTables()));

        return in_array((string) now()->year, $tables, true) ? (string) now()->year : ($tables[0] ?? '');
    }

    /**
     * The statutory rate in US dollars for a recording of the given length.
     *
     * A recording of exactly five minutes still earns the flat rate; the
     * published wording leaves that boundary open.
     *
     * @return numeric-string|null
     */
    public static function statutoryRate(string $table, ?int $seconds): ?string
    {
        $rates = self::rateTables()[$table] ?? null;

        if ($rates === null || $seconds === null || $seconds <= 0) {
            return null;
        }

        return $seconds <= self::PER_WORK_LIMIT
            ? Decimal::add($rates['per_work'], '0')
            : Decimal::mul((string) intdiv($seconds + 59, 60), $rates['per_minute']);
    }

    /**
     * @param  array<int, numeric-string>  $manufacturing  Copies manufactured in the range, by release id.
     * @return Report
     */
    public function report(Label $label, string $from, string $to, string $rateTable, array $manufacturing = []): array
    {
        $settings = ToolSettings::for($label);
        $sold = $this->unitsSold($label, $settings, $from, $to);

        $releases = Release::query()
            ->withoutGlobalScope('label')
            ->where('label_id', $label->id)
            ->where(fn (Builder $query) => $query
                ->where('accounts_mechanicals', true)
                ->orWhereHas('tracks', fn (Builder $tracks) => $tracks->withoutGlobalScope('label')->where('accounts_mechanicals', true)))
            ->with(['tracks' => fn ($tracks) => $tracks->withoutGlobalScope('label')])
            ->orderBy('name')
            ->get();

        $rows = [];
        $uncalculable = [];
        $used = [];
        $total = Decimal::add('0', '0');
        $units = Decimal::add('0', '0');

        foreach ($releases as $release) {
            foreach ($release->tracks as $track) {
                if (! ($track->accounts_mechanicals ?? $release->accounts_mechanicals)) {
                    continue;
                }

                $used[$release->id] = true;
                $place = ['release_id' => $release->id, 'release_name' => $release->name, 'track_id' => $track->id, 'track_name' => $track->name];
                $rate = $this->rate($label, $settings, $release, $track, $rateTable);

                if (is_array($rate) === false) {
                    $uncalculable[] = [...$place, 'reason' => $rate];

                    continue;
                }

                // A sale of the whole release is a sale of each of its tracks.
                $trackSold = Decimal::add($sold[$release->id][$track->id] ?? '0', $sold[$release->id][0] ?? '0');
                $made = $manufacturing[$release->id] ?? Decimal::add('0', '0');
                $trackUnits = Decimal::add($trackSold, $made);

                if (Decimal::isZero($trackUnits)) {
                    continue;
                }

                $amount = Decimal::mul($trackUnits, $rate['rate']);
                $rows[] = [...$place, 'sold' => $trackSold, 'manufactured' => Decimal::add($made, '0'), 'units' => $trackUnits, 'rate' => $rate['rate'], 'rate_source' => $rate['source'], 'amount' => $amount];
                $total = Decimal::add($total, $amount);
                $units = Decimal::add($units, $trackUnits);
            }
        }

        $counts = $this->ignored($label, $settings, $from, $to);
        $names = Release::query()->withoutGlobalScope('label')->where('label_id', $label->id)
            ->whereKey(array_keys(array_diff_key($manufacturing, $used)))->orderBy('name')->pluck('name')->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'units' => $units,
            'uncalculable' => $uncalculable,
            'unconverted' => $counts['unconverted'],
            'without_quantity' => $counts['without_quantity'],
            'unused_manufacturing' => array_values(array_map(strval(...), $names)),
        ];
    }

    /**
     * An expense and an income line per track. Under one contract the
     * pair cancels out; a publishing contract on the income item is what
     * pays the songwriter.
     *
     * @param  Report  $report
     * @return list<Line>
     */
    public function lines(array $report, string $paidOn): array
    {
        $lines = [];

        foreach ($report['rows'] as $row) {
            if (Decimal::isZero($row['amount'])) {
                continue;
            }

            $base = [
                'release_id' => $row['release_id'], 'release_name' => $row['release_name'], 'track_id' => $row['track_id'], 'track_name' => $row['track_name'],
                'paid_on' => $paidOn, 'category' => 'Mechanicals',
                'memo' => 'Mechanicals: '.self::plain($row['units']).' units × '.self::plain($row['rate']),
            ];

            $lines[] = [...$base, 'role' => 'mechanicals-expenses', 'suffix' => 'Mechanicals Expenses', 'type' => TransactionType::Expense, 'total' => Decimal::sub('0', $row['amount'])];
            $lines[] = [...$base, 'role' => 'mechanicals-income', 'suffix' => 'Mechanicals Income', 'type' => TransactionType::Income, 'total' => $row['amount']];
        }

        return $lines;
    }

    /**
     * Drop trailing zeros so memos read "120 units × 0.131".
     */
    public static function plain(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /**
     * The rate in the label currency and where it comes from, or the
     * reason none can be worked out.
     *
     * @return array{rate: numeric-string, source: string}|string
     */
    private function rate(Label $label, ToolSettings $settings, Release $release, Track $track, string $rateTable): array|string
    {
        // Custom rates are entered in the label currency. A rate on the
        // release applies to each of its tracks, not to the release once.
        foreach (['track' => $track->mechanicals_rate, 'release' => $release->mechanicals_rate] as $source => $custom) {
            $custom = Decimal::parse($custom);

            if ($custom !== null) {
                return ['rate' => $custom, 'source' => $source];
            }
        }

        $statutory = self::statutoryRate($rateTable, $track->duration);

        if ($statutory === null) {
            return 'No duration and no custom rate.';
        }

        if ($label->currency === 'USD') {
            return ['rate' => $statutory, 'source' => 'statutory'];
        }

        if ($settings->usdRate === null) {
            return "The statutory rate is in US dollars and the label has no conversion to {$label->currency}.";
        }

        return ['rate' => Decimal::mul($statutory, $settings->usdRate), 'source' => 'statutory'];
    }

    /**
     * @return list<string>
     */
    private function formats(ToolSettings $settings): array
    {
        $formats = [];

        foreach ([
            ...Config::array('royalties.mechanicals.download_formats'),
            ...($settings->includePhysical ? Config::array('royalties.formats.Physical') : []),
        ] as $format) {
            if (is_string($format)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /**
     * Units by release and track; key 0 holds sales booked on the release.
     *
     * @return array<int, array<int, numeric-string>>
     */
    private function unitsSold(Label $label, ToolSettings $settings, string $from, string $to): array
    {
        $quantity = $settings->ignoreNegativeQuantities ? 'CASE WHEN quantity > 0 THEN quantity ELSE 0 END' : 'quantity';

        $rows = $this->runs->sales($label, $from, $to)
            ->whereIn('format', $this->formats($settings))
            ->whereNotNull('release_id')
            ->whereNotNull('converted_total')
            ->whereNotNull('quantity')
            ->selectRaw("release_id, track_id, SUM({$quantity}) as units")
            ->groupBy('release_id', 'track_id')
            ->toBase()
            ->get();

        $sold = [];

        foreach ($rows as $row) {
            $sold[(int) $row->release_id][(int) ($row->track_id ?? 0)] = Decimal::parse($row->units) ?? Decimal::add('0', '0');
        }

        return $sold;
    }

    /**
     * Sales of the counted formats that could not be used.
     *
     * @return array{unconverted: int, without_quantity: int}
     */
    private function ignored(Label $label, ToolSettings $settings, string $from, string $to): array
    {
        $sales = fn (): Builder => $this->runs->sales($label, $from, $to)->whereIn('format', $this->formats($settings))->whereNotNull('release_id');

        return [
            'unconverted' => $sales()->whereNull('converted_total')->count(),
            'without_quantity' => $sales()->whereNotNull('converted_total')->whereNull('quantity')->count(),
        ];
    }
}
