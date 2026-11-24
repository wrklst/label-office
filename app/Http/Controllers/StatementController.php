<?php

namespace App\Http\Controllers;

use App\Models\Payee;
use App\Models\Period;
use App\Models\Statement;
use App\Support\Decimal;
use App\Support\PageSize;
use App\Support\Sorting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementController extends Controller
{
    public function index(Request $request): Response
    {
        $sorting = Sorting::for($request, [
            'payee' => Sorting::related(Payee::class, 'payee_id'),
            'period' => Sorting::related(Period::class, 'period_id'),
            'created_at' => 'created_at',
            'balance' => 'balance',
            'payable' => 'payable',
        ], fn (Builder $query) => $query->latest('id'));

        return Inertia::render('statements/index', [
            'statements' => Statement::query()
                ->with(['payee:id,name', 'period:id,name'])
                ->when(! $request->boolean('archived'), fn ($query) => $query->where('archived', false))
                ->when($request->integer('period'), fn ($query, int $period) => $query->where('period_id', $period))
                ->when($request->integer('payee'), fn ($query, int $payee) => $query->where('payee_id', $payee))
                ->tap($sorting)
                ->paginate(PageSize::MAIN)
                ->withQueryString()
                ->through(fn (Statement $statement): array => [
                    ...$statement->only(['id', 'name', 'archived', 'balance', 'payable', 'revision_of_id', 'payee_id', 'period_id']),
                    'payee' => $statement->payee?->name,
                    'period' => $statement->period?->name,
                    'published' => $statement->published_at !== null,
                    'created_at' => $statement->created_at->toIso8601String(),
                ]),
            'filters' => [
                'archived' => $request->boolean('archived'),
                'period' => $request->integer('period') ?: null,
                'payee' => $request->integer('payee') ?: null,
                ...$sorting->toArray(),
            ],
        ]);
    }

    public function show(Statement $statement): Response
    {
        return Inertia::render('statements/show', [
            'statement' => [
                ...$statement->only(['id', 'name', 'archived', 'revision_of_id', 'period_id', 'payee_id']),
                'published' => $statement->published_at !== null,
                'created_at' => $statement->created_at->toIso8601String(),
                'data' => $statement->data,
            ],
            'portal' => false,
        ]);
    }

    /**
     * Publish or unpublish a statement in the payee portal.
     */
    public function publish(Statement $statement): RedirectResponse
    {
        abort_if($statement->archived, 422, 'An archived revision cannot be published.');

        DB::transaction(function () use ($statement): void {
            $statement->update(['published_at' => $statement->published_at ? null : now()]);

            // The payee sees one statement per period: publishing a revision retires the ones before it.
            if ($statement->published_at !== null) {
                Statement::query()
                    ->where('period_id', $statement->period_id)
                    ->where('payee_id', $statement->payee_id)
                    ->whereKeyNot($statement->id)
                    ->where('archived', false)
                    ->update(['archived' => true]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $statement->published_at ? __('Statement published to the payee.') : __('Statement unpublished.')]);

        return back();
    }

    public function destroy(Statement $statement): RedirectResponse
    {
        $statement->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Statement deleted.')]);

        return to_route('statements.index');
    }

    public function download(Statement $statement): StreamedResponse
    {
        return self::csv($statement);
    }

    /**
     * Stream a statement as a CSV with one section per part of the report.
     */
    public static function csv(Statement $statement): StreamedResponse
    {
        $data = $statement->data;
        $money = fn (?string $value): string => Decimal::round($value, 4);

        return response()->streamDownload(function () use ($data, $money): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                throw new RuntimeException('The download stream could not be opened.');
            }

            // Text starting with =, +, - or @ would run as a formula in a spreadsheet.
            $safe = fn (mixed $cell): mixed => is_string($cell) && ! is_numeric($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell;
            $put = fn (array $row) => fputcsv($stream, array_map($safe, $row), ',', '"', '');

            $put(['Statement', $data['payee_name'] ?? '']);
            $put(['Period', $data['period']['name'] ?? '', $data['period']['starts_on'] ?? '', $data['period']['ends_on'] ?? '']);
            $put(['Currency', $data['currency'] ?? '']);
            $put([]);
            $put(['Summary']);
            $put(['Previous balance', 'Income', 'Expenses', 'Net', 'Your share', 'Transfers', 'Payable', 'Paid', 'Balance']);
            $totals = $data['totals'] ?? [];
            $put(array_map($money, [
                $totals['previous_balance'] ?? '0', $totals['income'] ?? '0', $totals['expense'] ?? '0', $totals['net'] ?? '0',
                $totals['payee_share'] ?? '0', $totals['transfers'] ?? '0', $totals['total_payable'] ?? '0', $totals['total_paid'] ?? '0', $totals['balance'] ?? '0',
            ]));

            foreach ($data['ledgers'] ?? [] as $ledger) {
                $put([]);
                $put(['Ledger', $ledger['ledger_name']]);
                $put(['Release', 'Track', 'Contract', 'Split %', 'Income', 'Expenses', 'Net', 'Your share', 'Previous balance', 'Balance']);

                foreach ($ledger['releases'] as $release) {
                    foreach ($release['tracks'] as $track) {
                        $put([
                            $release['release_name'], $track['track_name'], $track['contract_name'], Decimal::round(Decimal::mul($track['split'], '100'), 4),
                            $money($track['income']), $money($track['expense']), $money($track['net']), $money($track['payee_share']),
                            $money($track['previous_balance']), $money($track['balance']),
                        ]);
                    }
                }

                $put(['Ledger total', '', '', '', $money($ledger['income']), $money($ledger['expense']), $money($ledger['net']), $money($ledger['payee_share']), $money($ledger['previous_balance']), $money($ledger['balance'])]);
                $put(['Transfers', $money($ledger['transfers']), 'Payouts', $money($ledger['payouts'])]);
            }

            $put([]);
            $put(['Income']);
            $put(['Release', 'Track', 'Contract', 'Format', 'Units', 'Income', 'Split %', 'Your share']);

            foreach ($data['income_rows'] ?? [] as $row) {
                $put([$row['release_name'], $row['track_name'], $row['contract_name'], $row['format'], Decimal::round($row['quantity'], 2), $money($row['amount']), Decimal::round(Decimal::mul($row['split'], '100'), 4), $money($row['payee_share'])]);
            }

            $put([]);
            $put(['Expenses']);
            $put(['Release', 'Track', 'Contract', 'Category', 'Amount', 'Split %', 'Your share']);

            foreach ($data['expense_rows'] ?? [] as $row) {
                $put([$row['release_name'], $row['track_name'], $row['contract_name'], $row['category'], $money($row['amount']), Decimal::round(Decimal::mul($row['split'], '100'), 4), $money($row['payee_share'])]);
            }

            $put([]);
            $put(['Transfers and payouts']);
            $put(['Date', 'Type', 'Ledger', 'Release', 'Memo', 'Amount']);

            foreach ($data['transfer_rows'] ?? [] as $row) {
                $put([$row['date'], $row['category'] ?? 'Transfer', $row['ledger_name'], $row['release_name'], $row['memo'], $money($row['amount'])]);
            }

            foreach ($data['payout_rows'] ?? [] as $row) {
                $put([$row['date'], 'Payout', '', '', $row['memo'], $money(Decimal::sub('0', $row['amount']))]);
            }

            fclose($stream);
        }, str($statement->name)->slug().'.csv', ['Content-Type' => 'text/csv']);
    }
}
