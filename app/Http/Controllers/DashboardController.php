<?php

namespace App\Http\Controllers;

use App\Models\DataImport;
use App\Models\Item;
use App\Models\PlayCount;
use App\Models\Release;
use App\Models\Transaction;
use App\Services\Reports\IncomeReport;
use App\Services\Royalties\MoneyFlow;
use App\Support\DateRange;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // The dashboard shows the last twelve months unless other dates are chosen; the control says so.
        $chosen = DateRange::fromRequest($request);
        $last = $chosen->to === null ? now()->endOfMonth() : CarbonImmutable::parse($chosen->to);
        $range = new DateRange($chosen->from ?? $last->subMonths(11)->startOfMonth()->toDateString(), $last->toDateString(), $chosen->paid);
        $dateColumn = $range->column();
        $from = $range->from;
        $to = $range->to;

        $selection = fn () => $range->apply(Transaction::query());

        $byType = $selection()->toBase()->selectRaw('type, SUM(converted_total) as total, COUNT(*) as transactions')->groupBy('type')->get()->keyBy('type');

        return Inertia::render('dashboard', [
            'filters' => $range->toArray(),
            'totals' => [
                'income' => Decimal::parse($byType['income']->total ?? 0) ?? '0',
                'expense' => Decimal::parse($byType['expense']->total ?? 0) ?? '0',
                'transactions' => (int) $byType->sum('transactions'),
            ],
            'flow' => MoneyFlow::of($selection()),
            'report' => IncomeReport::of(
                $selection(),
                PlayCount::query()->where('period_end', '>=', $from)->where('period_start', '<=', $to),
                $dateColumn,
            ),
            'movers' => IncomeReport::movers($selection(), 'release_id', dateColumn: $dateColumn),
            'attention' => [
                'unassigned_items' => Item::query()->whereNull('release_id')->count(),
                'releases_without_contract' => Release::query()->withoutContract()->count(),
                'unconverted' => Transaction::query()->whereNull('converted_total')->count(),
                'imports' => DataImport::query()->where('needs_cleanup', true)->count(),
            ],
            'hasData' => Transaction::query()->exists(),
        ]);
    }
}
