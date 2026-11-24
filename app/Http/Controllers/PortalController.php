<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Settings\LabelBrandingController;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Statement;
use App\Support\CurrentLabel;
use App\Support\Decimal;
use App\Support\PageSize;
use App\Support\PayeeAccess;
use App\Support\Sorting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use SortDirection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a payee sees: for every label they are a payee of, their own
 * published statements, an overview taken from the latest of them and the
 * payouts they received. Figures the
 * label has not published never reach this controller's responses.
 */
class PortalController extends Controller
{
    public function __construct(private readonly CurrentLabel $currentLabel) {}

    public function index(Request $request): Response
    {
        $access = new PayeeAccess($request->user());
        $groups = $access->labels();
        $isAdmin = $this->currentLabel->isAdmin();

        // Someone who runs the label they work in sees what its payees see; a payee sees their own statements
        // of every label. Someone who is both gets both, in separate sections.
        $view = match (true) {
            $groups->isNotEmpty() => 'payee',
            $isAdmin => 'admin',
            default => 'unlinked',
        };

        $preview = null;
        $filters = [];

        if ($isAdmin) {
            $sorting = Sorting::for($request, [
                'payee' => Sorting::related(Payee::class, 'payee_id'),
                'period' => Sorting::related(Period::class, 'period_id'),
                'published_at' => 'published_at',
                'balance' => 'balance',
                'payable' => 'payable',
            ], fn (Builder $query) => $query->latest('id'), 'statements');
            $filters = [
                'period' => $request->integer('period') ?: null,
                'payee' => $request->integer('payee') ?: null,
                ...$sorting->toArray(),
            ];
            $preview = $this->adminStatements()->with(['period:id,name,starts_on,ends_on', 'payee:id,name'])
                ->when($filters['period'], fn ($query) => $query->where('period_id', $filters['period']))
                ->when($filters['payee'], fn ($query) => $query->where('payee_id', $filters['payee']))
                ->tap($sorting)
                ->paginate(PageSize::SECONDARY, pageName: 'statements_page')->withQueryString()
                ->through(fn (Statement $statement): array => $this->row($statement));
        }

        if ($view === 'admin') {
            return Inertia::render('portal/index', [
                'view' => $view,
                'groups' => [],
                'statements' => $preview,
                'filters' => $filters,
            ]);
        }

        $payload = [];

        foreach ($groups as $label) {
            $statementSorting = Sorting::for($request, [
                'period' => $access->periodOrder('period_id', 'name'),
                'published_at' => 'published_at',
                'balance' => 'balance',
                'payable' => 'payable',
            ], fn (Builder $query) => $query->orderByDesc('published_at')->orderByDesc('id'), "statements_{$label->id}");
            $payoutSorting = Sorting::for($request, [
                'paid_on' => 'paid_on',
                'period' => $access->periodOrder('period_id', 'name'),
                'memo' => Sorting::nullsLast('memo'),
                'total' => 'total',
            ], fn (Builder $query) => $query->orderByDesc('paid_on')->orderByDesc('id'), "payouts_{$label->id}");
            $filters = [...$filters, ...$statementSorting->toArray(), ...$payoutSorting->toArray()];

            $payload[] = [
                'label' => [
                    'id' => $label->id,
                    'name' => $label->name,
                    'currency' => $label->currency,
                    'logo_url' => LabelBrandingController::url($label, 'logo'),
                ],
                'overview' => $this->overview($access, $label),
                'payouts' => $access->payouts($label)->tap($payoutSorting)
                    ->paginate(PageSize::SECONDARY, pageName: "payouts_{$label->id}_page")->withQueryString()
                    ->through(fn (Payout $payout): array => [
                        'id' => $payout->id,
                        'paid_on' => $payout->paid_on->toDateString(),
                        'period' => $payout->period?->name,
                        'total' => $payout->total,
                        'memo' => $payout->memo,
                    ]),
                'statements' => $access->statements($label)->tap($statementSorting)
                    ->paginate(PageSize::SECONDARY, pageName: "statements_{$label->id}_page")->withQueryString()
                    ->through(fn (Statement $statement): array => $this->row($statement)),
            ];
        }

        return Inertia::render('portal/index', [
            'view' => $view,
            'groups' => $payload,
            'statements' => $preview,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, int $statement): Response
    {
        $statement = $this->find($request, $statement);

        return Inertia::render('statements/show', [
            'statement' => [
                ...$statement->only(['id', 'name', 'archived', 'revision_of_id', 'period_id', 'payee_id']),
                'published' => true,
                'created_at' => $statement->created_at->toIso8601String(),
                'data' => $statement->data,
            ],
            'statementLabel' => $this->brand($statement->label_id),
            'portal' => true,
        ]);
    }

    public function download(Request $request, int $statement): StreamedResponse
    {
        return StatementController::csv($this->find($request, $statement));
    }

    /**
     * A statement the user may read: their own in any label they are a
     * payee of, or, for an admin, any published one of the label they
     * work in. Anything else is not found, so nothing is revealed.
     */
    private function find(Request $request, int $id): Statement
    {
        $statement = (new PayeeAccess($request->user()))->statements()->find($id);

        if ($statement === null && $this->currentLabel->isAdmin()) {
            $statement = $this->adminStatements()->find($id);
        }

        return $statement ?? abort(404);
    }

    /**
     * The label of a statement the user was just allowed to read.
     *
     * @return array{id: int, name: string, logo_url: string|null}
     */
    private function brand(int $labelId): array
    {
        $label = Label::query()->findOrFail($labelId);

        return ['id' => $label->id, 'name' => $label->name, 'logo_url' => LabelBrandingController::url($label, 'logo')];
    }

    /**
     * @return array{id: int, name: string, balance: string, payable: string, period: string|null, payee: string|null, published_at: string|null}
     */
    private function row(Statement $statement): array
    {
        return [
            ...$statement->only(['id', 'name', 'balance', 'payable']),
            'period' => $statement->period?->name,
            'payee' => $statement->relationLoaded('payee') ? $statement->payee?->name : null,
            'published_at' => $statement->published_at?->toDateString(),
        ];
    }

    /**
     * Where the payee stands according to the statement published for
     * their most recent period. Live figures are deliberately not used:
     * they may contain imports and corrections the label has not released.
     *
     * @return array{
     *     statement_id: int,
     *     period: string,
     *     published_at: string|null,
     *     currency: string,
     *     owed: string,
     *     earned: string,
     *     paid: string,
     *     paid_since: string,
     *     ledgers: list<array{name: string, balance: string, unrecouped: bool}>
     * }|null
     */
    private function overview(PayeeAccess $access, Label $label): ?array
    {
        $statement = $access->statements($label)
            ->tap(fn (Builder $query) => $access->periodOrder('period_id', 'ends_on')($query, SortDirection::Descending))
            ->orderByDesc('id')
            ->first();

        if ($statement === null) {
            return null;
        }

        $ledgers = [];

        foreach (Arr::array($statement->data, 'ledgers', []) as $ledger) {
            $balance = (string) data_get($ledger, 'balance', '0');

            $ledgers[] = [
                'name' => (string) data_get($ledger, 'ledger_name', 'Primary'),
                'balance' => $balance,
                'unrecouped' => Decimal::cmp($balance, '0') < 0,
            ];
        }

        $currency = data_get($statement->data, 'currency');

        return [
            'statement_id' => $statement->id,
            'period' => (string) data_get($statement->data, 'period.name', $statement->period->name ?? $statement->name),
            'published_at' => $statement->published_at?->toDateString(),
            'currency' => is_string($currency) ? $currency : $label->currency,
            'owed' => (string) data_get($statement->data, 'totals.outstanding', $statement->payable),
            'earned' => (string) data_get($statement->data, 'totals.payee_share', '0'),
            'paid' => (string) data_get($statement->data, 'totals.total_paid', '0'),
            'paid_since' => Decimal::add('0', (string) $access->payouts($label)
                ->where('created_at', '>', $statement->created_at)
                ->sum('total')),
            'ledgers' => $ledgers,
        ];
    }

    /**
     * What the admin's portal view lists: published statements of the label they work in.
     *
     * @return Builder<Statement>
     */
    private function adminStatements(): Builder
    {
        return Statement::query()->where('archived', false)->whereNotNull('published_at');
    }
}
