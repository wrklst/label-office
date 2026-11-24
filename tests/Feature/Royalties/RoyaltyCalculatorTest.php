<?php

use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Period;
use App\Models\Release;
use App\Models\Territory;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Royalties\PayoutService;
use App\Services\Royalties\RoyaltyCalculator;
use App\Services\Royalties\StatementGenerator;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->label = currentLabel();
    $this->period = Period::factory()->create(['label_id' => $this->label->id]);
    $this->payee = Payee::factory()->create(['label_id' => $this->label->id, 'name' => 'Artist A']);
    $this->contract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $this->contract->id, 'payee_id' => $this->payee->id, 'split_amount' => '0.5']);
    $this->release = Release::factory()->create(['label_id' => $this->label->id, 'contract_id' => $this->contract->id]);
});

function transaction(array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'label_id' => test()->label->id,
        'release_id' => test()->release->id,
        ...$attributes,
    ]);
}

function payeeReport(?Period $period = null, ?Payee $payee = null): ?array
{
    $payee ??= test()->payee;

    return collect(app(RoyaltyCalculator::class)->forPeriod($period ?? test()->period)['payees'])
        ->firstWhere('payee_id', $payee->id);
}

test('a payee earns their split of income minus expenses', function () {
    transaction(['total' => '100']);
    transaction(['type' => 'expense', 'total' => '-40', 'category' => 'Mastering']);

    $totals = payeeReport()['totals'];

    expect($totals['income'])->toBe('100.000000000000000')
        ->and($totals['expense'])->toBe('-40.000000000000000')
        ->and($totals['net'])->toBe('60.000000000000000')
        ->and($totals['payee_share'])->toBe('30.000000000000000')
        ->and($totals['total_payable'])->toBe('30.000000000000000');
});

test('the label keeps what is not split', function () {
    transaction(['total' => '100']);

    $totals = app(RoyaltyCalculator::class)->forPeriod($this->period)['totals'];

    expect($totals['payee_share'])->toBe('50.000000000000000')
        ->and($totals['label_share'])->toBe('50.000000000000000');
});

test('tiny streaming amounts are not lost to rounding', function () {
    transaction(['total' => '0.000271889626']);
    transaction(['total' => '0.000000134582']);

    expect(payeeReport()['totals']['payee_share'])->toBe('0.000136012104000');
});

test('a transaction counts in the period it was paid in', function () {
    transaction(['total' => '100', 'timestamp' => '2026-03-31', 'paid_timestamp' => '2026-04-15']);

    $next = Period::factory()->create(['label_id' => $this->label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);

    expect(payeeReport())->toBeNull()
        ->and(payeeReport($next)['totals']['payee_share'])->toBe('50.000000000000000');
});

test('a track\'s own contract pays for the track, and a sale of the release is divided between the contracts of its tracks', function () {
    $remixer = Payee::factory()->create(['label_id' => $this->label->id]);
    $remixContract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $remixContract->id, 'payee_id' => $remixer->id, 'split_amount' => '0.25']);
    $track = Track::factory()->create(['label_id' => $this->label->id, 'release_id' => $this->release->id, 'contract_id' => $remixContract->id]);

    // A second track without a contract of its own: the contract of the release is its contract.
    Track::factory()->create(['label_id' => $this->label->id, 'release_id' => $this->release->id, 'contract_id' => null]);

    transaction(['total' => '100', 'track_id' => $track->id]);
    transaction(['total' => '100']);

    // The remixer: a quarter of the track's 100 and of the remix's half of the release sale.
    expect(payeeReport(payee: $remixer)['totals']['payee_share'])->toBe('37.500000000000000')
        ->and(payeeReport()['totals']['payee_share'])->toBe('25.000000000000000');
});

test('a sale of a release whose tracks all have one contract goes to that contract whole, also when the release has none', function () {
    $contract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $this->payee->id, 'split_amount' => '0.4']);
    $single = Release::factory()->create(['label_id' => $this->label->id, 'contract_id' => null]);
    Track::factory()->create(['label_id' => $this->label->id, 'release_id' => $single->id, 'contract_id' => $contract->id]);

    transaction(['total' => '100', 'release_id' => $single->id]);

    $report = app(RoyaltyCalculator::class)->forPeriod($this->period);

    expect($report['totals']['payee_share'])->toBe('40.000000000000000')
        ->and($report['audit']['missing_contract'])->toBe(0);
});

test('a contract set on the transaction overrides the catalog', function () {
    $other = Payee::factory()->create(['label_id' => $this->label->id]);
    $override = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $override->id, 'payee_id' => $other->id, 'split_amount' => '1']);

    transaction(['total' => '80', 'contract_id' => $override->id]);

    expect(payeeReport())->toBeNull()
        ->and(payeeReport(payee: $other)['totals']['payee_share'])->toBe('80.000000000000000');
});

test('a prorated contract distributes income across its child contracts by weight', function () {
    $second = Payee::factory()->create(['label_id' => $this->label->id]);
    $secondContract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $secondContract->id, 'payee_id' => $second->id, 'split_amount' => '0.5']);

    $compilation = Contract::factory()->create(['label_id' => $this->label->id]);
    $compilation->children()->attach([$this->contract->id => ['weight' => 3], $secondContract->id => ['weight' => 1]]);
    $this->release->update(['contract_id' => $compilation->id]);

    transaction(['total' => '100']);

    expect(payeeReport()['totals']['payee_share'])->toBe('37.500000000000000')
        ->and(payeeReport(payee: $second)['totals']['payee_share'])->toBe('12.500000000000000');
});

test('a territory split only earns from its countries', function () {
    $territory = Territory::factory()->create(['label_id' => $this->label->id, 'countries' => ['DE']]);
    $this->contract->splits()->update(['territory_id' => $territory->id]);

    transaction(['total' => '100', 'region' => 'DE']);
    transaction(['total' => '100', 'region' => 'US']);

    expect(payeeReport()['totals']['payee_share'])->toBe('50.000000000000000');
});

test('an unrecouped ledger carries forward and does not reduce other ledgers', function () {
    $advance = Ledger::factory()->create(['label_id' => $this->label->id, 'name' => 'Album advance']);
    $second = Release::factory()->create(['label_id' => $this->label->id]);
    $secondContract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $secondContract->id, 'payee_id' => $this->payee->id, 'ledger_id' => $advance->id, 'split_amount' => '0.5']);
    $second->update(['contract_id' => $secondContract->id]);

    transaction(['total' => '100']);
    transaction(['total' => '200', 'release_id' => $second->id]);
    transaction(['type' => 'transfer', 'total' => '-500', 'payee_id' => $this->payee->id, 'release_id' => $second->id, 'category' => 'Advance']);

    $report = payeeReport();
    $ledgers = collect($report['ledgers'])->keyBy('ledger_name');

    expect($ledgers['Primary']['balance'])->toBe('50.000000000000000')
        ->and($ledgers['Album advance']['balance'])->toBe('-400.000000000000000')
        ->and($ledgers['Album advance']['unrecouped'])->toBeTrue()
        ->and($report['totals']['total_payable'])->toBe('50.000000000000000');

    $next = Period::factory()->create(['label_id' => $this->label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);
    transaction(['total' => '300', 'release_id' => $second->id, 'timestamp' => '2026-05-01', 'paid_timestamp' => '2026-05-01']);

    $advanceLedger = collect(payeeReport($next)['ledgers'])->firstWhere('ledger_name', 'Album advance');

    expect($advanceLedger['previous_balance'])->toBe('-400.000000000000000')
        ->and($advanceLedger['balance'])->toBe('-250.000000000000000');
});

test('a payout reduces the balance carried into the next period', function () {
    transaction(['total' => '100']);

    app(PayoutService::class)->record($this->period, $this->payee, '30', '2026-04-10');

    $totals = payeeReport()['totals'];

    expect($totals['total_payable'])->toBe('50.000000000000000')
        ->and($totals['total_paid'])->toBe('30.000000000000000')
        ->and($totals['outstanding'])->toBe('20.000000000000000');

    $next = Period::factory()->create(['label_id' => $this->label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);

    expect(payeeReport($next)['totals']['previous_payable'])->toBe('20.000000000000000');
});

test('a payout cannot exceed what is payable', function () {
    transaction(['total' => '100']);

    app(PayoutService::class)->record($this->period, $this->payee, '50.01', '2026-04-10');
})->throws(ValidationException::class);

test('transactions without a converted amount are flagged instead of counted', function () {
    transaction(['total' => '100', 'currency' => 'GBP', 'conversion_rate' => null, 'converted_total' => null]);

    $report = app(RoyaltyCalculator::class)->forPeriod($this->period);

    expect($report['audit']['unconverted'])->toBe(1)
        ->and($report['totals']['income'])->toBe('0.000000000000000');
});

test('releases without a contract are reported in the audit', function () {
    $orphan = Release::factory()->create(['label_id' => $this->label->id]);
    transaction(['total' => '10', 'release_id' => $orphan->id]);
    transaction(['total' => '10', 'release_id' => null]);

    $audit = app(RoyaltyCalculator::class)->forPeriod($this->period)['audit'];

    expect($audit['missing_contract'])->toBe(1)
        ->and($audit['unassigned'])->toBe(1)
        ->and($audit['releases_without_contract'][0]['release_id'])->toBe($orphan->id);
});

test('regenerating a statement archives the previous one as a revision', function () {
    transaction(['total' => '100']);
    $generator = app(StatementGenerator::class);

    $first = $generator->generate($this->period)->sole();
    transaction(['total' => '50']);
    $second = $generator->generate($this->period)->sole();

    expect($first->refresh()->archived)->toBeTrue()
        ->and($first->data['totals']['payee_share'])->toBe('50.000000000000000')
        ->and($second->revision_of_id)->toBe($first->id)
        ->and($second->data['totals']['payee_share'])->toBe('75.000000000000000');
});

test('an expense the label bears alone does not reduce what the payee earns', function () {
    transaction(['total' => '100']);
    transaction(['type' => 'expense', 'total' => '-40', 'category' => 'Mastering']);
    transaction(['type' => 'expense', 'total' => '-20', 'category' => 'Remix', 'recoupable' => false]);

    $payee = payeeReport();

    expect($payee['totals']['expense'])->toBe('-40.000000000000000')
        ->and($payee['totals']['net'])->toBe('60.000000000000000')
        ->and($payee['totals']['payee_share'])->toBe('30.000000000000000')
        ->and($payee['ledgers'][0]['releases'][0]['expense'])->toBe('-40.000000000000000')
        ->and(array_column($payee['expense_rows'], 'category'))->toBe(['Mastering']);
});

test('the label keeps its share minus the expenses it bears alone', function () {
    transaction(['total' => '100']);
    transaction(['type' => 'expense', 'total' => '-40', 'category' => 'Mastering']);
    transaction(['type' => 'expense', 'total' => '-20', 'category' => 'Remix', 'recoupable' => false]);

    $totals = app(RoyaltyCalculator::class)->forPeriod($this->period)['totals'];

    expect($totals['income'])->toBe('100.000000000000000')
        ->and($totals['expense'])->toBe('-40.000000000000000')
        ->and($totals['net'])->toBe('60.000000000000000')
        ->and($totals['payee_share'])->toBe('30.000000000000000')
        ->and($totals['label_costs'])->toBe('-20.000000000000000')
        ->and($totals['label_share'])->toBe('10.000000000000000');
});

test('without label costs the label keeps net minus payee share', function () {
    transaction(['total' => '100']);
    transaction(['type' => 'expense', 'total' => '-40']);

    $totals = app(RoyaltyCalculator::class)->forPeriod($this->period)['totals'];

    expect($totals['label_costs'])->toBe('0.000000000000000')
        ->and($totals['label_share'])->toBe('30.000000000000000');
});

test('a label cost counts in the period it was paid in and never reaches a payee balance', function () {
    transaction(['type' => 'expense', 'total' => '-20', 'recoupable' => false]);
    transaction(['type' => 'expense', 'total' => '-7', 'recoupable' => false, 'timestamp' => '2026-03-31', 'paid_timestamp' => '2026-04-15']);
    transaction(['type' => 'expense', 'total' => '-3', 'recoupable' => false, 'currency' => 'GBP', 'conversion_rate' => null, 'converted_total' => null]);
    Transaction::factory()->labelCost()->create(['label_id' => Label::factory(), 'total' => '-500']);
    $next = Period::factory()->create(['label_id' => $this->label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);

    $first = app(RoyaltyCalculator::class)->forPeriod($this->period);
    $second = app(RoyaltyCalculator::class)->forPeriod($next);

    expect($first['totals']['label_costs'])->toBe('-20.000000000000000')
        ->and($first['payees'])->toBe([])
        ->and($second['totals']['label_costs'])->toBe('-7.000000000000000')
        ->and($second['payees'])->toBe([]);
});

test('only an expense can be left out of the split', function () {
    transaction(['total' => '100', 'recoupable' => false]);

    expect(payeeReport()['totals']['payee_share'])->toBe('50.000000000000000');
});

test('naming who an expense was paid to does not touch that payee\'s ledger', function () {
    transaction(['total' => '100']);
    transaction(['type' => 'expense', 'total' => '-40', 'payee_id' => $this->payee->id]);
    transaction(['type' => 'expense', 'total' => '-20', 'payee_id' => $this->payee->id, 'recoupable' => false]);

    $report = payeeReport();

    expect($report['totals']['transfers'])->toBe('0.000000000000000')
        ->and($report['transfer_rows'])->toBe([])
        ->and($report['totals']['payee_share'])->toBe('30.000000000000000')
        ->and($report['totals']['total_payable'])->toBe('30.000000000000000');
});

test('a balance paid on a later period cannot be paid again on the earlier one', function () {
    transaction(['total' => '100']);
    $next = Period::factory()->create(['label_id' => $this->label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);
    $payouts = app(PayoutService::class);
    $payouts->record($next, $this->payee, '50', '2026-07-10');

    $payouts->record($this->period, $this->payee, '50', '2026-07-11');
})->throws(ValidationException::class, 'The payout exceeds the 0.00 payable');

test('a payee with two splits of one contract sees the income once at the combined split', function () {
    $territory = Territory::factory()->create(['label_id' => $this->label->id, 'countries' => ['DE']]);
    ContractSplit::factory()->create(['contract_id' => $this->contract->id, 'payee_id' => $this->payee->id, 'split_amount' => '0.1', 'territory_id' => $territory->id]);
    transaction(['total' => '100', 'region' => 'DE', 'quantity' => 4]);

    $report = payeeReport();
    $line = collect($report['ledgers'][0]['releases'][0]['tracks'])->sole();
    $detail = collect($report['income_rows'])->sole();

    expect($line['income'])->toBe('100.000000000000000')
        ->and($line['split'])->toBe('0.600000000000000')
        ->and($line['payee_share'])->toBe('60.000000000000000')
        ->and($detail['amount'])->toBe('100.000000000000000')
        ->and($detail['quantity'])->toBe('4.000000000000000')
        ->and($detail['payee_share'])->toBe('60.000000000000000');
});

test('regenerating statements archives the draft of a payee with nothing left to state', function () {
    $transaction = transaction(['total' => '100']);
    $generator = app(StatementGenerator::class);
    $draft = $generator->generate($this->period)->sole();
    $transaction->forceDelete();

    expect($generator->generate($this->period))->toBeEmpty()
        ->and($draft->refresh()->archived)->toBeTrue();
});
