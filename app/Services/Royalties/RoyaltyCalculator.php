<?php

namespace App\Services\Royalties;

use App\Models\Contract;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Period;
use App\Models\Release;
use App\Models\Track;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Calculates what every payee earned and is owed for a period.
 *
 * - A transaction counts towards the period its paid date falls in.
 * - Its contract is the most specific one set: transaction, then item,
 *   then track, then release. Without a contract the label keeps it all.
 * - Net = income + expenses (costs are stored negative). The payee share
 *   is net × split and is booked on the ledger named by the split.
 * - An expense that is not recoupable is borne by the label alone: it is
 *   left out of the net that is split and only lowers what the label keeps.
 * - Ledger balance = previous balance + payee share + transfers − payouts.
 *   A negative ledger is unrecouped and carries forward; only positive
 *   ledgers are payable, and ledgers never offset one another.
 *
 * @phpstan-type PayeeSummary array{
 *     payee_id: int,
 *     payee_name: string,
 *     totals: array<string, string>,
 *     ledgers: list<array<string, mixed>>,
 *     income_rows: list<array<string, mixed>>,
 *     expense_rows: list<array<string, mixed>>,
 *     transfer_rows: array<int, array<string, mixed>>,
 *     payout_rows: list<array<string, mixed>>
 * }
 * @phpstan-type ActivityRow array{
 *     contract_id: int|null,
 *     release_id: int|null,
 *     track_id: int|null,
 *     type: string,
 *     format: string|null,
 *     category: string|null,
 *     region: string|null,
 *     is_prev: int|string,
 *     total: int|float|string|null,
 *     quantity: int|float|string|null
 * }
 * @phpstan-type TransferRow array{
 *     id: int,
 *     payee_id: int,
 *     ledger_id: int|null,
 *     release_id: int|null,
 *     paid_timestamp: string,
 *     converted_total: int|float|string,
 *     category: string|null,
 *     memo: string|null,
 *     contract_ids: list<int>
 * }
 * @phpstan-type PayoutRow array{
 *     payout_id: int,
 *     payee_id: int,
 *     period_id: int|string,
 *     paid_on: string,
 *     memo: string|null,
 *     ledger_id: int|null,
 *     amount: int|float|string
 * }
 */
class RoyaltyCalculator
{
    private const string RESOLVED_CONTRACT = 'COALESCE(t.contract_id, i.contract_id, tr.contract_id)';

    /** Nothing pays for the transaction: no contract of its own, and booked on a release whose tracks do not all have one. */
    private const string WITHOUT_CONTRACT = self::RESOLVED_CONTRACT.' IS NULL AND ('.ReleaseSales::BOOKED_ON_RELEASE.' = 0 OR '.ReleaseSales::WITHOUT_CONTRACT.')';

    public function __construct(private readonly SplitResolver $splitResolver) {}

    /**
     * The totals describe the royalty base: income, expense and net leave
     * out expenses that are not recoupable. Those are summed in label_costs
     * (negative), and label_share = net − payee_share + label_costs.
     *
     * @return array{
     *     period: array{id: int, name: string, starts_on: string, ends_on: string},
     *     currency: string,
     *     totals: array<string, string>,
     *     audit: array<string, mixed>,
     *     payees: list<PayeeSummary>
     * }
     */
    public function forPeriod(Period $period, ?int $onlyPayeeId = null): array
    {
        $labelId = $period->label_id;
        $start = $period->starts_on->toDateString();
        $end = $period->ends_on->toDateString();

        $splits = $this->splitResolver->forLabel($labelId);
        $usesTerritories = collect($splits)->flatten(1)->contains(fn (array $split): bool => $split['countries'] !== null);

        $payees = [];
        $totals = array_fill_keys(['income', 'expense', 'payee_share'], Decimal::add('0', '0'));

        foreach ($this->activity($labelId, $start, $end, $usesTerritories) as $row) {
            $amount = Decimal::parse($row['total']) ?? '0';
            $isPrevious = (bool) $row['is_prev'];
            $isIncome = $row['type'] === 'income';

            if (! $isPrevious) {
                $totals[$isIncome ? 'income' : 'expense'] = Decimal::add($totals[$isIncome ? 'income' : 'expense'], $amount);
            }

            // A payee can hold several splits of one contract. The row's amount
            // then counts once per line, at the splits' combined fraction.
            $lineFractions = [];
            $detailFractions = [];

            foreach ($splits[$row['contract_id']] ?? [] as $split) {
                if ($split['countries'] !== null && ! in_array($row['region'], $split['countries'], true)) {
                    continue;
                }

                $share = Decimal::mul($amount, $split['fraction']);
                $ledger = &$this->ledger($payees, $split['payee_id'], $split['ledger_id']);
                $lineKey = implode('|', [$row['release_id'], $row['track_id'], $row['contract_id']]);

                $ledgerKey = $split['payee_id'].'|'.$split['ledger_id'];
                $isFirstOnLine = ! isset($lineFractions[$ledgerKey]);
                $lineFractions[$ledgerKey] = Decimal::add($lineFractions[$ledgerKey] ?? '0', $split['fraction']);
                $isFirstOnDetail = ! isset($detailFractions[$split['payee_id']]);
                $detailFractions[$split['payee_id']] = Decimal::add($detailFractions[$split['payee_id']] ?? '0', $split['fraction']);

                $ledger['lines'][$lineKey] ??= [
                    'release_id' => $row['release_id'], 'track_id' => $row['track_id'], 'contract_id' => $row['contract_id'],
                    'split' => $split['fraction'], 'income' => '0', 'expense' => '0', 'payee_share' => '0', 'previous_balance' => '0',
                ];
                $line = &$ledger['lines'][$lineKey];
                $line['split'] = $lineFractions[$ledgerKey];

                if ($isPrevious) {
                    $line['previous_balance'] = Decimal::add($line['previous_balance'], $share);
                    unset($line, $ledger);

                    continue;
                }

                if ($isFirstOnLine) {
                    $line[$isIncome ? 'income' : 'expense'] = Decimal::add($line[$isIncome ? 'income' : 'expense'], $amount);
                }

                $line['payee_share'] = Decimal::add($line['payee_share'], $share);
                $totals['payee_share'] = Decimal::add($totals['payee_share'], $share);

                $detailKey = implode('|', [$lineKey, $row['format'], $row['category']]);
                $details = &$payees[$split['payee_id']][$isIncome ? 'income_rows' : 'expense_rows'];
                $details[$detailKey] ??= [
                    'release_id' => $row['release_id'], 'track_id' => $row['track_id'], 'contract_id' => $row['contract_id'],
                    'format' => $row['format'], 'category' => $row['category'], 'split' => $split['fraction'],
                    'quantity' => '0', 'amount' => '0', 'payee_share' => '0',
                ];
                $details[$detailKey]['split'] = $detailFractions[$split['payee_id']];

                if ($isFirstOnDetail) {
                    $details[$detailKey]['quantity'] = Decimal::add($details[$detailKey]['quantity'], Decimal::parse($row['quantity']) ?? '0');
                    $details[$detailKey]['amount'] = Decimal::add($details[$detailKey]['amount'], $amount);
                }

                $details[$detailKey]['payee_share'] = Decimal::add($details[$detailKey]['payee_share'], $share);

                unset($line, $ledger, $details);
            }
        }

        foreach ($this->transfers($labelId, $end) as $transfer) {
            $amount = Decimal::parse($transfer['converted_total']) ?? '0';
            $ledgerId = $transfer['ledger_id'];

            if ($ledgerId === null) {
                foreach ($transfer['contract_ids'] as $contractId) {
                    $viaSplit = collect($splits[$contractId] ?? [])->firstWhere('payee_id', $transfer['payee_id']);

                    if ($viaSplit !== null) {
                        $ledgerId = $viaSplit['ledger_id'];

                        break;
                    }
                }
            }

            $ledger = &$this->ledger($payees, $transfer['payee_id'], $ledgerId);

            if ($transfer['paid_timestamp'] < $start) {
                $ledger['previous_transfers'] = Decimal::add($ledger['previous_transfers'], $amount);
            } else {
                $ledger['transfers'] = Decimal::add($ledger['transfers'], $amount);
                $payees[$transfer['payee_id']]['transfer_rows'][] = [
                    'id' => $transfer['id'], 'date' => $transfer['paid_timestamp'], 'release_id' => $transfer['release_id'],
                    'ledger_id' => $ledgerId, 'category' => $transfer['category'], 'memo' => $transfer['memo'], 'amount' => $amount,
                ];
            }

            unset($ledger);
        }

        foreach ($this->payouts($labelId, $period) as $payout) {
            $amount = Decimal::parse($payout['amount']) ?? '0';
            $ledger = &$this->ledger($payees, $payout['payee_id'], $payout['ledger_id']);

            if ((int) $payout['period_id'] === $period->id) {
                $ledger['payouts'] = Decimal::add($ledger['payouts'], $amount);
                $payees[$payout['payee_id']]['payout_rows'][$payout['payout_id']] ??= [
                    'id' => $payout['payout_id'], 'date' => $payout['paid_on'], 'memo' => $payout['memo'], 'amount' => '0',
                ];
                $payees[$payout['payee_id']]['payout_rows'][$payout['payout_id']]['amount'] = Decimal::add(
                    $payees[$payout['payee_id']]['payout_rows'][$payout['payout_id']]['amount'], $amount,
                );
            } else {
                $ledger['previous_payouts'] = Decimal::add($ledger['previous_payouts'], $amount);
            }

            unset($ledger);
        }

        $names = $this->names($labelId);
        $result = [];
        $grand = ['previous_payable' => '0', 'transfers' => '0', 'total_payable' => '0', 'total_paid' => '0', 'outstanding' => '0'];

        foreach ($payees as $payeeId => $payee) {
            if (! isset($names['payees'][$payeeId])) {
                continue;
            }

            $summary = $this->summarise($payeeId, $payee, $names);

            foreach (array_keys($grand) as $key) {
                $grand[$key] = Decimal::add($grand[$key], $summary['totals'][$key]);
            }

            if ($onlyPayeeId === null || $onlyPayeeId === $payeeId) {
                $result[] = $summary;
            }
        }

        usort($result, fn (array $a, array $b): int => strcasecmp($a['payee_name'], $b['payee_name']));

        $net = Decimal::add($totals['income'], $totals['expense']);
        $labelCosts = $this->labelCosts($labelId, $start, $end);

        return [
            'period' => ['id' => $period->id, 'name' => $period->name, 'starts_on' => $start, 'ends_on' => $end],
            'currency' => $period->label()->withoutGlobalScopes()->value('currency') ?? 'EUR',
            'totals' => [
                'income' => $totals['income'],
                'expense' => $totals['expense'],
                'net' => $net,
                'payee_share' => $totals['payee_share'],
                'label_costs' => $labelCosts,
                'label_share' => Decimal::add(Decimal::sub($net, $totals['payee_share']), $labelCosts),
                ...$grand,
            ],
            'audit' => $this->audit($labelId, $start, $end, $names),
            'payees' => $result,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payees
     * @return array<string, mixed>
     */
    private function &ledger(array &$payees, int $payeeId, ?int $ledgerId): array
    {
        $payees[$payeeId] ??= ['ledgers' => [], 'income_rows' => [], 'expense_rows' => [], 'transfer_rows' => [], 'payout_rows' => []];
        $payees[$payeeId]['ledgers'][$ledgerId ?? 0] ??= [
            'ledger_id' => $ledgerId, 'lines' => [], 'transfers' => '0', 'payouts' => '0',
            'previous_transfers' => '0', 'previous_payouts' => '0',
        ];

        return $payees[$payeeId]['ledgers'][$ledgerId ?? 0];
    }

    /**
     * @param  array<string, mixed>  $payee
     * @param  array<string, array<int, string>>  $names
     * @return PayeeSummary
     */
    private function summarise(int $payeeId, array $payee, array $names): array
    {
        $ledgers = [];
        $totals = [
            'income' => '0', 'expense' => '0', 'net' => '0', 'payee_share' => '0', 'transfers' => '0',
            'previous_balance' => '0', 'previous_payable' => '0', 'total_payable' => '0', 'total_paid' => '0',
            'balance' => '0', 'outstanding' => '0',
        ];

        foreach ($payee['ledgers'] as $ledger) {
            $releases = [];
            $sum = ['income' => '0', 'expense' => '0', 'payee_share' => '0', 'previous_balance' => '0'];

            foreach ($ledger['lines'] as $line) {
                $releaseKey = $line['release_id'] ?? 0;
                $releases[$releaseKey] ??= [
                    'release_id' => $line['release_id'],
                    'release_name' => $names['releases'][$line['release_id'] ?? ''] ?? 'Unassigned',
                    'income' => '0', 'expense' => '0', 'net' => '0', 'payee_share' => '0', 'previous_balance' => '0', 'balance' => '0',
                    'tracks' => [],
                ];

                $track = [
                    'track_id' => $line['track_id'],
                    'track_name' => $names['tracks'][$line['track_id'] ?? ''] ?? 'N/A',
                    'contract_id' => $line['contract_id'],
                    'contract_name' => $names['contracts'][$line['contract_id'] ?? ''] ?? null,
                    'split' => $line['split'],
                    'income' => $line['income'],
                    'expense' => $line['expense'],
                    'net' => Decimal::add($line['income'], $line['expense']),
                    'payee_share' => $line['payee_share'],
                    'previous_balance' => $line['previous_balance'],
                    'balance' => Decimal::add($line['previous_balance'], $line['payee_share']),
                ];
                $releases[$releaseKey]['tracks'][] = $track;

                foreach (['income', 'expense', 'net', 'payee_share', 'previous_balance', 'balance'] as $key) {
                    $releases[$releaseKey][$key] = Decimal::add($releases[$releaseKey][$key], $track[$key]);
                }

                foreach (array_keys($sum) as $key) {
                    $sum[$key] = Decimal::add($sum[$key], $line[$key]);
                }
            }

            foreach ($releases as &$release) {
                usort($release['tracks'], fn (array $a, array $b): int => strcasecmp($a['track_name'], $b['track_name']));
            }
            unset($release);

            $releases = array_values($releases);
            usort($releases, fn (array $a, array $b): int => strcasecmp($a['release_name'], $b['release_name']));

            $previous = Decimal::sub(Decimal::add($sum['previous_balance'], $ledger['previous_transfers']), $ledger['previous_payouts']);
            $beforePayouts = Decimal::add(Decimal::add($previous, $sum['payee_share']), $ledger['transfers']);
            $balance = Decimal::sub($beforePayouts, $ledger['payouts']);

            $ledgers[] = [
                'ledger_id' => $ledger['ledger_id'],
                'ledger_name' => $ledger['ledger_id'] === null ? 'Primary' : ($names['ledgers'][$ledger['ledger_id']] ?? 'Deleted ledger'),
                'income' => $sum['income'],
                'expense' => $sum['expense'],
                'net' => Decimal::add($sum['income'], $sum['expense']),
                'payee_share' => $sum['payee_share'],
                'transfers' => $ledger['transfers'],
                'payouts' => $ledger['payouts'],
                'previous_balance' => $previous,
                'balance' => $balance,
                'unrecouped' => Decimal::cmp($balance, '0') < 0,
                'releases' => $releases,
            ];

            $totals['income'] = Decimal::add($totals['income'], $sum['income']);
            $totals['expense'] = Decimal::add($totals['expense'], $sum['expense']);
            $totals['payee_share'] = Decimal::add($totals['payee_share'], $sum['payee_share']);
            $totals['transfers'] = Decimal::add($totals['transfers'], $ledger['transfers']);
            $totals['previous_balance'] = Decimal::add($totals['previous_balance'], $previous);
            $totals['previous_payable'] = Decimal::add($totals['previous_payable'], Decimal::max($previous, '0'));
            $totals['total_payable'] = Decimal::add($totals['total_payable'], Decimal::max($beforePayouts, '0'));
            $totals['total_paid'] = Decimal::add($totals['total_paid'], $ledger['payouts']);
            $totals['balance'] = Decimal::add($totals['balance'], $balance);
            $totals['outstanding'] = Decimal::add($totals['outstanding'], Decimal::max($balance, '0'));
        }

        $totals['net'] = Decimal::add($totals['income'], $totals['expense']);

        usort($ledgers, fn (array $a, array $b): int => [$a['ledger_id'] !== null, $a['ledger_name']] <=> [$b['ledger_id'] !== null, $b['ledger_name']]);

        $describe = fn (array $row): array => [
            ...$row,
            'release_name' => $names['releases'][$row['release_id'] ?? ''] ?? 'Unassigned',
            'track_name' => isset($row['track_id']) ? ($names['tracks'][$row['track_id']] ?? 'N/A') : null,
            'contract_name' => isset($row['contract_id']) ? ($names['contracts'][$row['contract_id']] ?? null) : null,
        ];
        $byShare = fn (array $a, array $b): int => Decimal::cmp($b['payee_share'], $a['payee_share']);

        $incomeRows = array_map($describe, array_values($payee['income_rows']));
        $expenseRows = array_map($describe, array_values($payee['expense_rows']));
        usort($incomeRows, $byShare);
        usort($expenseRows, fn (array $a, array $b): int => $byShare($b, $a));

        return [
            'payee_id' => $payeeId,
            'payee_name' => $names['payees'][$payeeId],
            'totals' => $totals,
            'ledgers' => $ledgers,
            'income_rows' => $incomeRows,
            'expense_rows' => $expenseRows,
            'transfer_rows' => array_map(fn (array $row): array => [
                ...$row,
                'release_name' => $names['releases'][$row['release_id'] ?? ''] ?? null,
                'ledger_name' => $row['ledger_id'] === null ? 'Primary' : ($names['ledgers'][$row['ledger_id']] ?? 'Deleted ledger'),
            ], $payee['transfer_rows']),
            'payout_rows' => array_values($payee['payout_rows']),
        ];
    }

    private function base(int $labelId): Builder
    {
        return DB::table('transactions as t')
            ->leftJoin('items as i', 'i.id', '=', 't.item_id')
            ->leftJoin('tracks as tr', 'tr.id', '=', 't.track_id')
            ->leftJoin('releases as r', 'r.id', '=', 't.release_id')
            ->where('t.label_id', $labelId);
    }

    /**
     * Income and recoupable expenses up to the period end, grouped as far
     * as the statement needs and flagged as before or inside the period.
     *
     * @return iterable<ActivityRow>
     */
    private function activity(int $labelId, string $start, string $end, bool $withRegion): iterable
    {
        $region = $withRegion ? 't.region' : 'NULL';

        $rows = $this->base($labelId)
            ->where(fn (Builder $query) => $query
                ->where('t.type', 'income')
                ->orWhere(fn (Builder $query) => $query->where('t.type', 'expense')->where('t.recoupable', true)))
            ->where('t.paid_timestamp', '<=', $end)
            ->whereNotNull('t.converted_total')
            ->selectRaw(
                self::RESOLVED_CONTRACT." as contract_id, t.release_id, t.track_id, t.type, t.format, t.category, {$region} as region, "
                .'CASE WHEN t.paid_timestamp < ? THEN 1 ELSE 0 END as is_prev, '.ReleaseSales::BOOKED_ON_RELEASE.' as booked_on_release, '
                .'SUM(t.converted_total) as total, SUM(t.quantity) as quantity',
                [$start],
            )
            ->groupByRaw('1, 2, 3, 4, 5, 6, 7, 8, 9')
            ->cursor();
        $releaseSales = new ReleaseSales($labelId);

        foreach ($rows as $row) {
            // What is booked on a release as a whole is paid through the contracts of its tracks.
            foreach ($releaseSales->parts($row->contract_id, $row->release_id, (bool) $row->booked_on_release) as [$contractId, $fraction]) {
                yield [
                    'contract_id' => $contractId,
                    'release_id' => $row->release_id,
                    'track_id' => $row->track_id,
                    'type' => $row->type,
                    'format' => $row->format,
                    'category' => $row->category,
                    'region' => $row->region,
                    'is_prev' => $row->is_prev,
                    'total' => ReleaseSales::part($row->total, $fraction),
                    'quantity' => ReleaseSales::part($row->quantity, $fraction),
                ];
            }
        }
    }

    /**
     * Expenses paid in the period that the label bears alone.
     *
     * @return numeric-string
     */
    private function labelCosts(int $labelId, string $start, string $end): string
    {
        $total = DB::table('transactions')
            ->where('label_id', $labelId)
            ->where('type', 'expense')
            ->where('recoupable', false)
            ->whereBetween('paid_timestamp', [$start, $end])
            ->whereNotNull('converted_total')
            ->sum('converted_total');

        return Decimal::parse($total) ?? Decimal::add('0', '0');
    }

    /**
     * @return iterable<TransferRow>
     */
    private function transfers(int $labelId, string $end): iterable
    {
        $releaseSales = new ReleaseSales($labelId);
        $rows = $this->base($labelId)
            ->where('t.type', 'transfer')
            ->whereNotNull('t.payee_id')
            ->whereNotNull('t.converted_total')
            ->where('t.paid_timestamp', '<=', $end)
            ->orderBy('t.paid_timestamp')
            ->selectRaw('t.id, t.payee_id, t.ledger_id, t.release_id, t.paid_timestamp, t.converted_total, t.category, t.memo, '.self::RESOLVED_CONTRACT.' as contract_id')
            ->get();

        foreach ($rows as $row) {
            yield [
                'id' => $row->id,
                'payee_id' => $row->payee_id,
                'ledger_id' => $row->ledger_id,
                'release_id' => $row->release_id,
                'paid_timestamp' => $row->paid_timestamp,
                'converted_total' => $row->converted_total,
                'category' => $row->category,
                'memo' => $row->memo,
                // A transfer booked on a release as a whole follows the contracts of that release's tracks.
                'contract_ids' => $row->contract_id !== null ? [(int) $row->contract_id] : ($row->release_id === null ? [] : $releaseSales->contractIds((int) $row->release_id)),
            ];
        }
    }

    /**
     * Payouts belong to the period they settle, not the day they were paid.
     *
     * @return iterable<PayoutRow>
     */
    private function payouts(int $labelId, Period $period): iterable
    {
        $rows = DB::table('payout_allocations as a')
            ->join('payouts as p', 'p.id', '=', 'a.payout_id')
            ->join('periods as pp', 'pp.id', '=', 'p.period_id')
            ->where('p.label_id', $labelId)
            ->where(fn (Builder $query) => $query
                ->where('pp.id', $period->id)
                ->orWhere('pp.ends_on', '<', $period->starts_on->toDateString()))
            ->orderBy('p.paid_on')
            ->get(['p.id as payout_id', 'p.payee_id', 'p.period_id', 'p.paid_on', 'p.memo', 'a.ledger_id', 'a.amount']);

        foreach ($rows as $row) {
            yield [
                'payout_id' => $row->payout_id,
                'payee_id' => $row->payee_id,
                'period_id' => $row->period_id,
                'paid_on' => $row->paid_on,
                'memo' => $row->memo,
                'ledger_id' => $row->ledger_id,
                'amount' => $row->amount,
            ];
        }
    }

    /**
     * Things to fix before statements go out.
     *
     * @param  array<string, array<int, string>>  $names
     * @return array<string, mixed>
     */
    private function audit(int $labelId, string $start, string $end, array $names): array
    {
        $counts = $this->base($labelId)
            ->whereBetween('t.paid_timestamp', [$start, $end])
            ->selectRaw(
                'COUNT(*) as transactions, '
                ."SUM(CASE WHEN t.type <> 'transfer' AND t.release_id IS NULL AND (t.type <> 'expense' OR t.recoupable = 1) THEN 1 ELSE 0 END) as unassigned, "
                ."SUM(CASE WHEN t.type <> 'transfer' AND t.release_id IS NOT NULL AND ".self::WITHOUT_CONTRACT.' THEN 1 ELSE 0 END) as missing_contract, '
                .'SUM(CASE WHEN t.converted_total IS NULL THEN 1 ELSE 0 END) as unconverted',
            )
            ->first();

        $withoutContract = $this->base($labelId)
            ->whereBetween('t.paid_timestamp', [$start, $end])
            ->where('t.type', '<>', 'transfer')
            ->whereNotNull('t.release_id')
            ->whereRaw(self::WITHOUT_CONTRACT)
            ->selectRaw('t.release_id, COUNT(*) as transactions, SUM(t.converted_total) as total')
            ->groupBy('t.release_id')
            ->get()
            ->map(fn (object $row): array => [
                'release_id' => $row->release_id,
                'release_name' => $names['releases'][$row->release_id] ?? 'Unknown',
                'transactions' => (int) $row->transactions,
                'total' => Decimal::parse($row->total) ?? '0',
            ])
            ->sortByDesc(fn (array $row): float => (float) $row['total'])
            ->values()->all();

        return [
            'transactions' => (int) ($counts->transactions ?? 0),
            'unassigned' => (int) ($counts->unassigned ?? 0),
            'missing_contract' => (int) ($counts->missing_contract ?? 0),
            'unconverted' => (int) ($counts->unconverted ?? 0),
            'releases_without_contract' => $withoutContract,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function names(int $labelId): array
    {
        $pluck = fn (string $model): array => $model::query()->withoutGlobalScope('label')
            ->where('label_id', $labelId)->pluck('name', 'id')->all();

        return [
            'payees' => $pluck(Payee::class),
            'ledgers' => $pluck(Ledger::class),
            'releases' => $pluck(Release::class),
            'tracks' => $pluck(Track::class),
            'contracts' => $pluck(Contract::class),
        ];
    }
}
