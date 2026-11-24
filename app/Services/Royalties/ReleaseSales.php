<?php

namespace App\Services\Royalties;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Pays what is booked on a release without a track through the contracts
 * of its tracks.
 *
 * Contracts belong to tracks. A contract set on a release is the contract
 * of every track on it that has none of its own. So a sale of the release
 * as a whole, or a cost for it, is divided in equal parts between its
 * tracks, and each part goes to that track's contract.
 */
class ReleaseSales
{
    /** A transaction is booked on the release as a whole, and nobody chose a contract for it or its item. */
    public const string BOOKED_ON_RELEASE = 'CASE WHEN t.track_id IS NULL AND t.release_id IS NOT NULL AND t.contract_id IS NULL AND i.contract_id IS NULL THEN 1 ELSE 0 END';

    /** Such a transaction has no contract: the release has none, and not every track on it has one. */
    public const string WITHOUT_CONTRACT = 'r.contract_id IS NULL AND (NOT EXISTS (SELECT 1 FROM release_track rt WHERE rt.release_id = t.release_id)'
        .' OR EXISTS (SELECT 1 FROM release_track rt JOIN tracks rtr ON rtr.id = rt.track_id WHERE rt.release_id = t.release_id AND rtr.contract_id IS NULL))';

    /** @var array<int, array<string, int>> release id => contract id ("" for none) => how many of its tracks it pays for */
    private array $contracts = [];

    public function __construct(int $labelId)
    {
        $tracks = DB::table('release_track')
            ->join('tracks', 'tracks.id', '=', 'release_track.track_id')
            ->join('releases', 'releases.id', '=', 'release_track.release_id')
            ->where('releases.label_id', $labelId)
            ->get(['release_track.release_id', 'tracks.contract_id as track_contract_id', 'releases.contract_id as release_contract_id']);

        foreach ($tracks as $track) {
            $contract = (string) ($track->track_contract_id ?? $track->release_contract_id);
            $this->contracts[$track->release_id][$contract] = ($this->contracts[$track->release_id][$contract] ?? 0) + 1;
        }
    }

    /**
     * The contracts that pay for what a row of transactions sums up, each
     * with its fraction of the amount; null stands for all of it. A row that
     * is booked on a track, or on a release without tracks, keeps its contract.
     *
     * @return list<array{0: int|null, 1: numeric-string|null}>
     */
    public function parts(?int $contractId, ?int $releaseId, bool $bookedOnRelease): array
    {
        $contracts = $bookedOnRelease ? ($this->contracts[$releaseId] ?? []) : [];

        if ($contracts === []) {
            return [[$contractId, null]];
        }

        $tracks = (string) array_sum($contracts);
        $parts = [];

        foreach ($contracts as $contract => $count) {
            $parts[] = [$contract === '' ? null : (int) $contract, count($contracts) === 1 ? null : Decimal::div((string) $count, $tracks)];
        }

        return $parts;
    }

    /**
     * A part of an amount; all of it without a fraction.
     */
    public static function part(mixed $amount, ?string $fraction): mixed
    {
        return $fraction === null || $amount === null ? $amount : Decimal::mul(Decimal::parse($amount) ?? '0', $fraction);
    }
}
