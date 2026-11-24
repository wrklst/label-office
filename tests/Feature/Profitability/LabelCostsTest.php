<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Period;
use App\Models\Receipt;
use App\Models\RecurringExpense;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Royalties\ProfitabilityReport;
use App\Services\Royalties\RecurringExpenseBooker;
use App\Services\Royalties\RoyaltyCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->label = Label::factory()->create();
    $this->actingAs(labelAdmin($this->label));
});

function bookedDates(RecurringExpense $expense): array
{
    return Transaction::query()->withoutGlobalScopes()->where('recurring_expense_id', $expense->id)
        ->orderBy('paid_timestamp')->get()->map(fn (Transaction $t): string => $t->paid_timestamp->toDateString())->all();
}

test('a one-time cost is booked as an expense the label bears alone', function () {
    $this->post(route('label-costs.store'), ['name' => 'Example Hosting', 'amount' => '120', 'currency' => 'eur', 'category' => 'Hosting', 'paid_timestamp' => '2026-03-01'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'label_id' => $this->label->id, 'type' => 'expense', 'recoupable' => false, 'release_id' => null, 'source' => 'Example Hosting',
        'category' => 'Hosting', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01',
    ]);

    expect(Transaction::query()->withoutGlobalScopes()->sole()->converted_total)->toBe('-120.000000000000000');
});

test('a cost in another currency is not counted when no rate is typed and none can be had', function () {
    $this->post(route('label-costs.store'), ['name' => 'Example Tool', 'amount' => '10', 'currency' => 'USD', 'paid_timestamp' => '2026-03-01']);
    $this->post(route('label-costs.store'), ['name' => 'Example Tool', 'amount' => '10', 'currency' => 'USD', 'conversion_rate' => '0.9', 'paid_timestamp' => '2026-03-02']);

    $costs = Transaction::query()->withoutGlobalScopes()->orderBy('paid_timestamp')->get();

    expect($costs[0]->converted_total)->toBeNull()
        ->and($costs[1]->converted_total)->toBe('-9.000000000000000');
});

test('a label cost is refused when it is not a positive amount with a currency', function (array $input, string $field) {
    $this->post(route('label-costs.store'), ['name' => 'Example', 'amount' => '10', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01', ...$input])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('transactions', 0);
})->with([
    'a negative amount' => [['amount' => '-10'], 'amount'],
    'zero' => [['amount' => '0'], 'amount'],
    'no name' => [['name' => ''], 'name'],
    'a currency that is not a code' => [['currency' => 'EURO'], 'currency'],
    'no date' => [['paid_timestamp' => ''], 'paid_timestamp'],
]);

test('a recurring cost books every due date up to today and none in the future', function () {
    $this->travelTo('2026-04-20');

    $this->post(route('label-costs.recurring.store'), ['name' => 'Example Subscription', 'amount' => '10', 'currency' => 'EUR', 'interval' => 'monthly', 'starts_on' => '2026-01-15'])
        ->assertSessionHasNoErrors();

    $expense = RecurringExpense::query()->withoutGlobalScopes()->sole();

    expect(bookedDates($expense))->toBe(['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15'])
        ->and($expense->occurrences_booked)->toBe(4)
        ->and($expense->nextDueOn()->toDateString())->toBe('2026-05-15');
});

test('booking again does not book a due date twice', function () {
    $expense = RecurringExpense::factory()->create(['label_id' => $this->label->id, 'starts_on' => '2026-01-15']);
    $booker = app(RecurringExpenseBooker::class);

    expect($booker->bookDue($this->label, CarbonImmutable::parse('2026-03-20')))->toBe(3)
        ->and($booker->bookDue($this->label, CarbonImmutable::parse('2026-03-20')))->toBe(0)
        ->and($booker->bookDue($this->label, CarbonImmutable::parse('2026-04-15')))->toBe(1)
        ->and(bookedDates($expense))->toHaveCount(4);
});

test('due dates follow the interval and stay at the end of short months', function (string $interval, string $start, string $until, array $expected) {
    $expense = RecurringExpense::factory()->create(['label_id' => $this->label->id, 'interval' => $interval, 'starts_on' => $start]);

    app(RecurringExpenseBooker::class)->bookDue($this->label, CarbonImmutable::parse($until));

    expect(bookedDates($expense))->toBe($expected);
})->with([
    'monthly from the 31st' => ['monthly', '2026-01-31', '2026-04-30', ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']],
    'quarterly' => ['quarterly', '2026-01-10', '2026-08-01', ['2026-01-10', '2026-04-10', '2026-07-10']],
    'yearly' => ['yearly', '2024-06-01', '2026-06-01', ['2024-06-01', '2025-06-01', '2026-06-01']],
]);

test('a recurring cost stops after its last payment date', function () {
    $expense = RecurringExpense::factory()->create(['label_id' => $this->label->id, 'starts_on' => '2026-01-15', 'ends_on' => '2026-02-20']);

    app(RecurringExpenseBooker::class)->bookDue($this->label, CarbonImmutable::parse('2026-12-31'));

    expect(bookedDates($expense))->toBe(['2026-01-15', '2026-02-15'])
        ->and($expense->refresh()->nextDueOn())->toBeNull();
});

test('changing a recurring cost applies to later payments only', function () {
    $this->travelTo('2026-02-20');
    $expense = RecurringExpense::factory()->create(['label_id' => $this->label->id, 'starts_on' => '2026-01-15', 'amount' => '10']);
    app(RecurringExpenseBooker::class)->bookDue($this->label);

    $this->put(route('label-costs.recurring.update', $expense->id), ['name' => 'Example subscription', 'amount' => '15', 'currency' => 'EUR'])->assertSessionHasNoErrors();
    $this->travelTo('2026-03-20');
    app(RecurringExpenseBooker::class)->bookDue($this->label);

    $totals = Transaction::query()->withoutGlobalScopes()->orderBy('paid_timestamp')->pluck('total')->all();

    expect($totals)->toBe(['-10.000000000000000', '-10.000000000000000', '-15.000000000000000']);
});

test('stopping a recurring cost keeps what was booked', function () {
    $expense = RecurringExpense::factory()->create(['label_id' => $this->label->id, 'starts_on' => '2026-01-15']);
    app(RecurringExpenseBooker::class)->bookDue($this->label, CarbonImmutable::parse('2026-02-20'));

    $this->delete(route('label-costs.recurring.destroy', $expense->id))->assertRedirect();

    $this->assertDatabaseCount('recurring_expenses', 0);
    $this->assertDatabaseCount('transactions', 2);
    $this->assertDatabaseHas('transactions', ['recurring_expense_id' => null]);
});

test('deleting a recurring cost with its payments removes exactly those and frees their receipts', function () {
    $expense = RecurringExpense::factory()->create(['label_id' => $this->label->id, 'starts_on' => '2026-01-15']);
    app(RecurringExpenseBooker::class)->bookDue($this->label, CarbonImmutable::parse('2026-02-20'));
    $other = RecurringExpense::factory()->create(['label_id' => $this->label->id, 'name' => 'Another subscription', 'starts_on' => '2026-02-01']);
    app(RecurringExpenseBooker::class)->bookDue($this->label, CarbonImmutable::parse('2026-02-20'));
    $oneTime = Transaction::factory()->create(['label_id' => $this->label->id, 'type' => 'expense', 'recoupable' => false, 'release_id' => null]);
    $receipt = Receipt::factory()->create(['label_id' => $this->label->id, 'transaction_id' => Transaction::query()->where('recurring_expense_id', $expense->id)->value('id')]);

    $this->delete(route('label-costs.recurring.destroy', ['recurring_expense' => $expense->id, 'payments' => 1]))
        ->assertInertiaFlash('toast.message', 'Recurring cost deleted with its 2 booked payments.');

    expect(RecurringExpense::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Transaction::query()->where('recurring_expense_id', $other->id)->count())->toBe(1)
        ->and(Transaction::query()->whereKey($oneTime->id)->exists())->toBeTrue()
        ->and(Transaction::query()->count())->toBe(2)
        ->and($receipt->refresh()->transaction_id)->toBeNull();
});

test('a recurring cost needs a known interval and an end after its start', function (array $input, string $field) {
    $this->post(route('label-costs.recurring.store'), ['name' => 'Example', 'amount' => '10', 'currency' => 'EUR', 'interval' => 'monthly', 'starts_on' => '2026-01-15', ...$input])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('recurring_expenses', 0);
})->with([
    'an unknown interval' => [['interval' => 'weekly'], 'interval'],
    'an end before the start' => [['ends_on' => '2026-01-01'], 'ends_on'],
    'a start in the distant past' => [['starts_on' => '1900-01-01'], 'starts_on'],
]);

test('the scheduled command books due costs of every label', function () {
    $this->travelTo('2026-02-20');
    RecurringExpense::factory()->create(['label_id' => $this->label->id, 'starts_on' => '2026-01-15']);
    RecurringExpense::factory()->create(['label_id' => Label::factory(), 'starts_on' => '2026-02-01']);

    $this->artisan('royalties:book-recurring-expenses')->assertSuccessful();

    $this->assertDatabaseCount('transactions', 3);
});

test('the page lists recurring and booked costs of this label only and books what is due', function () {
    $this->travelTo('2026-02-20');
    RecurringExpense::factory()->create(['label_id' => $this->label->id, 'name' => 'Ours', 'starts_on' => '2026-01-15']);
    RecurringExpense::factory()->create(['label_id' => Label::factory(), 'name' => 'Theirs']);
    Transaction::factory()->labelCost()->create(['label_id' => $this->label->id, 'total' => '-5', 'release_id' => Release::factory()->create(['label_id' => $this->label->id])]);

    $this->get(route('label-costs.index'))->assertInertia(fn (Assert $page) => $page
        ->component('label-costs/index')
        ->has('recurring.data', 1)
        ->where('recurring.data.0.name', 'Ours')
        ->where('recurring.data.0.next_due_on', '2026-03-15')
        ->has('costs.data', 2)
        ->where('costs.data.0.recurring', true)
        ->where('totals.this_year', '-20.000000000000000'));
});

test('a recurring cost of another label cannot be changed or stopped', function () {
    $foreign = RecurringExpense::factory()->create(['label_id' => Label::factory()]);

    $this->put(route('label-costs.recurring.update', $foreign->id), ['name' => 'Hijacked', 'amount' => '1', 'currency' => 'EUR'])->assertNotFound();
    $this->delete(route('label-costs.recurring.destroy', $foreign->id))->assertNotFound();

    $this->assertDatabaseHas('recurring_expenses', ['id' => $foreign->id, 'name' => 'Example subscription']);
});

test('payees cannot see or book label costs', function () {
    $user = User::factory()->create(['current_label_id' => $this->label->id]);
    $this->label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => Payee::factory()->create(['label_id' => $this->label->id])->id]);

    $this->actingAs($user)->get(route('label-costs.index'))->assertRedirect(route('portal.index'));
    $this->actingAs($user)->post(route('label-costs.store'), ['name' => 'x', 'amount' => '1', 'currency' => 'EUR', 'paid_timestamp' => '2026-01-01'])->assertForbidden();
});

test('label costs lower the label result as overheads and leave royalties untouched', function () {
    $payee = Payee::factory()->create(['label_id' => $this->label->id]);
    $contract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'split_amount' => '0.5']);
    $release = Release::factory()->create(['label_id' => $this->label->id, 'name' => 'Example Release']);
    Track::factory()->create(['label_id' => $this->label->id, 'release_id' => $release->id, 'contract_id' => $contract->id]);
    Transaction::factory()->create(['label_id' => $this->label->id, 'release_id' => $release->id, 'total' => '100']);
    $period = Period::factory()->create(['label_id' => $this->label->id]);

    $this->post(route('label-costs.store'), ['name' => 'Example Hosting', 'amount' => '30', 'currency' => 'EUR', 'paid_timestamp' => '2026-02-01']);

    $report = app(ProfitabilityReport::class)->forLabel($this->label);
    $royalties = app(RoyaltyCalculator::class)->forPeriod($period);

    expect(array_column($report['rows'], 'release_name'))->toBe(['Example Release', 'Overheads'])
        ->and($report['rows'][1]['label_costs'])->toBe('-30.000000000000000')
        ->and($report['totals']['label_result'])->toBe('20.000000000000000')
        ->and($royalties['payees'][0]['totals']['payee_share'])->toBe('50.000000000000000')
        ->and($royalties['audit']['unassigned'])->toBe(0)
        ->and($royalties['totals']['label_costs'])->toBe('-30.000000000000000');
});

test('a cost in another currency without a typed rate takes the reference rate of the day it was paid', function () {
    Http::fake(['api.frankfurter.dev/v1/2026-03-01*' => Http::response(['base' => 'USD', 'date' => '2026-02-27', 'rates' => ['EUR' => 0.8]])]);

    $this->post(route('label-costs.store'), ['name' => 'Example Tool', 'amount' => '10', 'currency' => 'USD', 'paid_timestamp' => '2026-03-01'])->assertSessionHasNoErrors();
    $this->post(route('label-costs.store'), ['name' => 'Example Tool', 'amount' => '10', 'currency' => 'USD', 'conversion_rate' => '0.9', 'paid_timestamp' => '2026-03-01']);

    expect(Transaction::query()->withoutGlobalScopes()->orderBy('id')->pluck('converted_total')->all())->toBe(['-8.000000000000000', '-9.000000000000000']);

    // The rate of a past day is asked for once.
    Http::assertSentCount(1);
});

test('a recurring cost in another currency converts each payment at the rate of its day, unless it has a rate of its own', function () {
    Http::fake([
        'api.frankfurter.dev/v1/2026-01-15*' => Http::response(['rates' => ['EUR' => 0.9]]),
        'api.frankfurter.dev/v1/2026-02-15*' => Http::response(['rates' => ['EUR' => 0.8]]),
        'api.frankfurter.dev/v1/2026-03-15*' => Http::response(['message' => 'not found'], 404),
    ]);
    $shared = ['label_id' => $this->label->id, 'amount' => '10', 'currency' => 'USD', 'interval' => 'monthly', 'starts_on' => '2026-01-15'];
    $daily = RecurringExpense::factory()->create([...$shared, 'conversion_rate' => null]);
    $fixed = RecurringExpense::factory()->create([...$shared, 'conversion_rate' => '0.5']);

    app(RecurringExpenseBooker::class)->bookDue($this->label, CarbonImmutable::parse('2026-03-20'));

    $converted = fn (RecurringExpense $expense): array => Transaction::query()->withoutGlobalScopes()->where('recurring_expense_id', $expense->id)->orderBy('paid_timestamp')->pluck('converted_total')->all();

    expect($converted($daily))->toBe(['-9.000000000000000', '-8.000000000000000', null])
        ->and($converted($fixed))->toBe(['-5.000000000000000', '-5.000000000000000', '-5.000000000000000']);
});

test('a booked cost is edited in the dialog it was booked in: typed positive, stored negative', function () {
    $this->post(route('label-costs.store'), ['name' => 'Example Hosting', 'amount' => '49.90', 'currency' => 'EUR', 'category' => 'Hosting', 'paid_timestamp' => '2026-03-01']);
    $cost = Transaction::query()->sole();

    $this->get(route('label-costs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('costs.data.0.amount', '49.900000000000000')
            ->where('costs.data.0.paid_timestamp', '2026-03-01'));

    $this->put(route('label-costs.update', $cost->id), ['name' => 'Example Hosting Pro', 'amount' => '59.90', 'currency' => 'EUR', 'category' => 'Hosting', 'memo' => 'Upgraded', 'paid_timestamp' => '2026-03-05'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Cost saved.');

    $cost->refresh();

    expect($cost->total)->toBe('-59.900000000000000')
        ->and($cost->converted_total)->toBe('-59.900000000000000')
        ->and($cost->source)->toBe('Example Hosting Pro')
        ->and($cost->memo)->toBe('Upgraded')
        ->and($cost->paid_timestamp->toDateString())->toBe('2026-03-05')
        ->and($cost->recoupable)->toBeFalse();
});

test('a booked cost refuses a minus sign, and a credit booked positive stays one', function () {
    $this->post(route('label-costs.store'), ['name' => 'Example Hosting', 'amount' => '10', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01']);
    $cost = Transaction::query()->sole();

    $this->put(route('label-costs.update', $cost->id), ['name' => 'Example Hosting', 'amount' => '-10', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01'])
        ->assertSessionHasErrors('amount');

    $credit = Transaction::factory()->labelCost()->create(['label_id' => $this->label->id, 'total' => '15', 'converted_total' => '15']);

    $this->put(route('label-costs.update', $credit->id), ['name' => 'Example Refund', 'amount' => '20', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01'])->assertSessionHasNoErrors();

    expect($cost->refresh()->total)->toBe('-10.000000000000000')
        ->and($credit->refresh()->total)->toBe('20.000000000000000');
});

test('only a cost of the label itself can be changed there', function () {
    $release = Release::factory()->create(['label_id' => $this->label->id]);
    $onRelease = Transaction::factory()->create(['label_id' => $this->label->id, 'type' => 'expense', 'recoupable' => false, 'release_id' => $release->id, 'total' => '-5']);
    $income = Transaction::factory()->create(['label_id' => $this->label->id, 'type' => 'income', 'total' => '5']);
    $foreign = Transaction::factory()->labelCost()->create(['label_id' => Label::factory(), 'total' => '-5']);
    $payload = ['name' => 'Example', 'amount' => '9', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01'];

    foreach ([$onRelease, $income, $foreign] as $transaction) {
        $this->put(route('label-costs.update', $transaction->id), $payload)->assertNotFound();
    }
});

test('a refund or credit is booked and edited in the overheads dialogs like on the transaction form', function () {
    $this->post(route('label-costs.store'), ['name' => 'Example Refund', 'amount' => '30', 'refund' => '1', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01'])->assertSessionHasNoErrors();
    $this->post(route('label-costs.store'), ['name' => 'Example Hosting', 'amount' => '30', 'refund' => '0', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01']);

    [$credit, $cost] = Transaction::query()->orderBy('id')->get()->all();

    expect($credit->total)->toBe('30.000000000000000')
        ->and($cost->total)->toBe('-30.000000000000000');

    $this->get(route('label-costs.index'))->assertInertia(fn (Assert $page) => $page->where('costs.data.1.refund', true)->where('costs.data.0.refund', false));

    $payload = ['name' => 'Example', 'amount' => '30', 'currency' => 'EUR', 'paid_timestamp' => '2026-03-01'];
    $this->put(route('label-costs.update', $credit->id), [...$payload, 'refund' => '0'])->assertSessionHasNoErrors();
    $this->put(route('label-costs.update', $cost->id), [...$payload, 'refund' => '1'])->assertSessionHasNoErrors();

    expect($credit->refresh()->total)->toBe('-30.000000000000000')
        ->and($credit->converted_total)->toBe('-30.000000000000000')
        ->and($cost->refresh()->total)->toBe('30.000000000000000');
});

test('editing an overhead in its dialog leaves the sale date and the supplier alone', function () {
    $cost = Transaction::factory()->labelCost()->create([
        'label_id' => $this->label->id, 'total' => '-10', 'converted_total' => '-10', 'currency' => 'EUR',
        'timestamp' => '2026-02-01', 'paid_timestamp' => '2026-03-01', 'source' => 'Example Supplier', 'memo' => 'Annual fee',
    ]);

    $this->put(route('label-costs.update', $cost->id), ['name' => 'Example Supplier', 'amount' => '12', 'currency' => 'EUR', 'memo' => 'Annual fee', 'paid_timestamp' => '2026-03-05'])->assertSessionHasNoErrors();

    $cost->refresh();

    expect($cost->timestamp->toDateString())->toBe('2026-02-01')
        ->and($cost->paid_timestamp->toDateString())->toBe('2026-03-05')
        ->and($cost->source)->toBe('Example Supplier')
        ->and($cost->total)->toBe('-12.000000000000000');

    $this->put(route('label-costs.update', $cost->id), ['name' => 'Example Supplier 2', 'amount' => '12', 'currency' => 'EUR', 'memo' => 'Annual fee', 'paid_timestamp' => '2026-03-05']);

    expect($cost->refresh()->source)->toBe('Example Supplier 2');
});

test('an overhead without a supplier keeps it empty when only the amount changes', function () {
    $cost = Transaction::factory()->labelCost()->create([
        'label_id' => $this->label->id, 'total' => '-10', 'converted_total' => '-10', 'currency' => 'EUR', 'source' => null, 'memo' => 'Domain name',
    ]);

    $this->put(route('label-costs.update', $cost->id), ['name' => 'Domain name', 'amount' => '11', 'currency' => 'EUR', 'memo' => 'Domain name', 'paid_timestamp' => $cost->paid_timestamp->toDateString()])->assertSessionHasNoErrors();

    expect($cost->refresh()->source)->toBeNull()
        ->and($cost->total)->toBe('-11.000000000000000');
});

test('recurring per year converts costs in another currency and never adds currencies together', function () {
    $this->travelTo('2026-04-20');
    Http::fake(['api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.5]])]);
    $shared = ['label_id' => $this->label->id, 'interval' => 'monthly', 'starts_on' => '2026-01-15', 'occurrences_booked' => 4];
    RecurringExpense::factory()->create([...$shared, 'amount' => '10', 'currency' => 'EUR', 'conversion_rate' => null]);
    RecurringExpense::factory()->create([...$shared, 'amount' => '10', 'currency' => 'USD', 'conversion_rate' => '0.8']);
    RecurringExpense::factory()->create([...$shared, 'amount' => '10', 'currency' => 'GBP', 'conversion_rate' => null]);

    $this->get(route('label-costs.index'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.recurring_per_year', '276.000000000000000')
        ->where('totals.recurring_without_rate', 0));
});

test('a recurring cost whose rate cannot be had is left out of the yearly figure and counted apart', function () {
    $this->travelTo('2026-04-20');
    Http::fake(['api.frankfurter.dev/*' => Http::response([], 500)]);
    $shared = ['label_id' => $this->label->id, 'interval' => 'monthly', 'starts_on' => '2026-01-15', 'occurrences_booked' => 4];
    RecurringExpense::factory()->create([...$shared, 'amount' => '10', 'currency' => 'EUR', 'conversion_rate' => null]);
    RecurringExpense::factory()->create([...$shared, 'amount' => '10', 'currency' => 'USD', 'conversion_rate' => '0.8']);
    RecurringExpense::factory()->create([...$shared, 'amount' => '10', 'currency' => 'GBP', 'conversion_rate' => null]);

    $this->get(route('label-costs.index'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.recurring_per_year', '216.000000000000000')
        ->where('totals.recurring_without_rate', 1));
});
