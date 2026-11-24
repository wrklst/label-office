<?php

namespace App\Services\Royalties;

use App\Models\Label;
use App\Models\Release;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shows what the label itself is left with per release.
 *
 * - Income and recoupable expenses form the net that is split with payees,
 *   using the same contract and territory rules as the royalty calculator.
 * - Label costs are expenses that are not recoupable: the label bears them
 *   alone, so they lower the label result but not the payee share.
 * - Label result = net − payee share + label costs (costs are negative).
 * - Label costs without a release (subscriptions, admin) are listed as
 *   "Overheads".
 * - Advances are transfers given to payees. They are prepaid royalties, not
 *   costs, and are listed for information only.
 * - Transactions without a converted amount are left out and counted.
 *
 * @phpstan-import-type Split from SplitResolver
 *
 * @phpstan-type Figures array{
 *     income: numeric-string,
 *     expense: numeric-string,
 *     net: numeric-string,
 *     payee_share: numeric-string,
 *     label_costs: numeric-string,
 *     label_result: numeric-string,
 *     margin: numeric-string|null,
 *     advances: numeric-string
 * }
 * @phpstan-type ReleaseRow array{
 *     release_id: int|null,
 *     release_name: string,
 *     catalog_number: string|null,
 *     artist_name: string|null,
 *     artist_id: int|null,
 *     income: numeric-string,
 *     expense: numeric-string,
 *     net: numeric-string,
 *     payee_share: numeric-string,
 *     label_costs: numeric-string,
 *     label_result: numeric-string,
 *     margin: numeric-string|null,
 *     advances: numeric-string
 * }
 * @phpstan-type Sums array{
 *     income: numeric-string,
 *     expense: numeric-string,
 *     payee_share: numeric-string,
 *     label_costs: numeric-string,
 *     advances: numeric-string
 * }
 * @phpstan-type ActivityRow array{
 *     contract_id: int|null,
 *     release_id: int|null,
 *     type: string,
 *     recoupable: int|bool|string,
 *     region: string|null,
 *     total: int|float|string|null
 * }
 * @phpstan-type AdvanceRow array{release_id: int|null, total: int|float|string|null}
 * @phpstan-type Report array{
 *     currency: string,
 *     rows: list<ReleaseRow>,
 *     totals: Figures,
 *     unconverted: int
 * }
 */
class ProfitabilityReport
{
    public const string UNASSIGNED = 'Not assigned to a release';

    public const string OVERHEADS = 'Overheads';

    /** Row key for label costs that belong to no release. */
    private const int OVERHEAD_KEY = -1;

    private const string RESOLVED_CONTRACT = 'COALESCE(t.contract_id, i.contract_id, tr.contract_id, r.contract_id)';

    public function __construct(private readonly SplitResolver $splitResolver) {}

    /**
     * @param  string|null  $from  First day counted, or null for no lower bound.
     * @param  string|null  $to  Last day counted, or null for no upper bound.
     * @param  bool  $byPaidDate  Apply the range to the paid date instead of the sale date.
     * @param  int|null  $artistId  Only releases of this artist.
     * @return Report
     */
    public function forLabel(Label $label, ?string $from = null, ?string $to = null, bool $byPaidDate = false, ?int $artistId = null): array
    {
        $splits = $this->splitResolver->forLabel($label->id);
        $usesTerritories = collect($splits)->flatten(1)->contains(fn (array $split): bool => $split['countries'] !== null);
        $scope = fn (): Builder => $this->scope($label->id, $from, $to, $byPaidDate, $artistId);

        $releases = [];

        foreach ($this->activity($scope(), $usesTerritories, $label->id) as $row) {
            $isLabelCost = $row['type'] === 'expense' && ! $row['recoupable'];
            $key = $row['release_id'] ?? ($isLabelCost ? self::OVERHEAD_KEY : 0);
            $releases[$key] ??= $this->emptySums();
            $amount = Decimal::parse($row['total']) ?? '0';

            if ($isLabelCost) {
                $releases[$key]['label_costs'] = Decimal::add($releases[$key]['label_costs'], $amount);

                continue;
            }

            $column = $row['type'] === 'income' ? 'income' : 'expense';
            $releases[$key][$column] = Decimal::add($releases[$key][$column], $amount);

            foreach ($splits[$row['contract_id']] ?? [] as $split) {
                if ($split['countries'] !== null && ! in_array($row['region'], $split['countries'], true)) {
                    continue;
                }

                $releases[$key]['payee_share'] = Decimal::add($releases[$key]['payee_share'], Decimal::mul($amount, $split['fraction']));
            }
        }

        foreach ($this->advances($scope()) as $row) {
            $key = $row['release_id'] ?? 0;
            $releases[$key] ??= $this->emptySums();
            $releases[$key]['advances'] = Decimal::add($releases[$key]['advances'], Decimal::parse($row['total']) ?? '0');
        }

        $catalog = Release::query()
            ->withoutGlobalScope('label')
            ->where('label_id', $label->id)
            ->whereIn('id', array_keys($releases))
            ->with(['artist' => fn ($query) => $query->withoutGlobalScope('label')])
            ->get()
            ->keyBy('id');

        $rows = [];
        $unassigned = null;
        $overheads = null;
        $totals = $this->emptySums();

        foreach ($releases as $releaseId => $sums) {
            foreach ($sums as $column => $value) {
                $totals[$column] = Decimal::add($totals[$column], $value);
            }

            if ($releaseId === self::OVERHEAD_KEY) {
                $overheads = ['release_id' => null, 'release_name' => self::OVERHEADS, 'catalog_number' => null, 'artist_name' => null, 'artist_id' => null, ...$this->figures($sums)];

                continue;
            }

            if ($releaseId === 0) {
                $unassigned = ['release_id' => null, 'release_name' => self::UNASSIGNED, 'catalog_number' => null, 'artist_name' => null, 'artist_id' => null, ...$this->figures($sums)];

                continue;
            }

            $release = $catalog->get($releaseId);

            $rows[] = [
                'release_id' => $releaseId,
                'release_name' => $release->name ?? 'Unknown release',
                'catalog_number' => $release?->catalog_number,
                'artist_name' => $release?->artist?->name,
                'artist_id' => $release?->artist?->id,
                ...$this->figures($sums),
            ];
        }

        usort($rows, fn (array $a, array $b): int => Decimal::cmp($b['label_result'], $a['label_result']) ?: strcasecmp($a['release_name'], $b['release_name']));

        if ($unassigned !== null) {
            $rows[] = $unassigned;
        }

        if ($overheads !== null) {
            $rows[] = $overheads;
        }

        return [
            'currency' => $label->currency,
            'rows' => $rows,
            'totals' => $this->figures($totals),
            'unconverted' => $scope()->whereNull('t.converted_total')->count(),
        ];
    }

    /**
     * @return Sums
     */
    private function emptySums(): array
    {
        $zero = Decimal::add('0', '0');

        return ['income' => $zero, 'expense' => $zero, 'payee_share' => $zero, 'label_costs' => $zero, 'advances' => $zero];
    }

    /**
     * @param  Sums  $sums
     * @return Figures
     */
    private function figures(array $sums): array
    {
        $net = Decimal::add($sums['income'], $sums['expense']);
        $result = Decimal::add(Decimal::sub($net, $sums['payee_share']), $sums['label_costs']);

        return [
            'income' => $sums['income'],
            'expense' => $sums['expense'],
            'net' => $net,
            'payee_share' => $sums['payee_share'],
            'label_costs' => $sums['label_costs'],
            'label_result' => $result,
            'margin' => Decimal::isZero($sums['income']) ? null : Decimal::div($result, $sums['income']),
            'advances' => $sums['advances'],
        ];
    }

    /**
     * Every transaction of the label inside the date range and artist filter.
     */
    private function scope(int $labelId, ?string $from, ?string $to, bool $byPaidDate, ?int $artistId): Builder
    {
        $dateColumn = $byPaidDate ? 't.paid_timestamp' : 't.timestamp';

        return DB::table('transactions as t')
            ->leftJoin('items as i', 'i.id', '=', 't.item_id')
            ->leftJoin('tracks as tr', 'tr.id', '=', 't.track_id')
            ->leftJoin('releases as r', 'r.id', '=', 't.release_id')
            ->where('t.label_id', $labelId)
            ->when($from !== null, fn (Builder $query) => $query->where($dateColumn, '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where($dateColumn, '<=', $to))
            ->when($artistId !== null, fn (Builder $query) => $query->where('r.artist_id', $artistId));
    }

    /**
     * Income and expenses summed per release, applicable contract and,
     * when territory splits exist, country.
     *
     * @return iterable<ActivityRow>
     */
    private function activity(Builder $scope, bool $withRegion, int $labelId): iterable
    {
        $region = $withRegion ? 't.region' : 'NULL';

        $rows = $scope
            ->whereIn('t.type', ['income', 'expense'])
            ->whereNotNull('t.converted_total')
            ->selectRaw(self::RESOLVED_CONTRACT." as contract_id, t.release_id, t.type, t.recoupable, {$region} as region, ".ReleaseSales::BOOKED_ON_RELEASE.' as booked_on_release, SUM(t.converted_total) as total')
            ->groupByRaw('1, 2, 3, 4, 5, 6')
            ->cursor();
        $releaseSales = new ReleaseSales($labelId);

        foreach ($rows as $row) {
            // As on the statements: what is booked on a release as a whole is paid through the contracts of its tracks.
            foreach ($releaseSales->parts($row->contract_id, $row->release_id, (bool) $row->booked_on_release) as [$contractId, $fraction]) {
                yield [
                    'contract_id' => $contractId,
                    'release_id' => $row->release_id,
                    'type' => $row->type,
                    'recoupable' => $row->recoupable,
                    'region' => $row->region,
                    'total' => ReleaseSales::part($row->total, $fraction),
                ];
            }
        }
    }

    /**
     * Transfers given to payees (negative amounts) per release.
     *
     * @return iterable<AdvanceRow>
     */
    private function advances(Builder $scope): iterable
    {
        $rows = $scope
            ->where('t.type', 'transfer')
            ->whereNotNull('t.payee_id')
            ->where('t.converted_total', '<', 0)
            ->selectRaw('t.release_id, SUM(t.converted_total) as total')
            ->groupBy('t.release_id')
            ->get();

        foreach ($rows as $row) {
            yield ['release_id' => $row->release_id, 'total' => $row->total];
        }
    }
}
