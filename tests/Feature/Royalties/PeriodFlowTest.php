<?php

use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Period;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Track;
use App\Models\Transaction;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->label = Label::factory()->create();
    $this->admin = labelAdmin($this->label);
    $this->period = Period::factory()->create(['label_id' => $this->label->id]);
    $this->payee = Payee::factory()->create(['label_id' => $this->label->id]);
    $contract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $this->payee->id, 'split_amount' => '0.5']);
    $release = Release::factory()->create(['label_id' => $this->label->id]);
    Track::factory()->create(['label_id' => $this->label->id, 'release_id' => $release->id, 'contract_id' => $contract->id]);
    Transaction::factory()->create(['label_id' => $this->label->id, 'release_id' => $release->id, 'total' => '100']);
    $this->actingAs($this->admin);
});

test('the period page shows what each payee is owed', function () {
    $this->get(route('periods.show', $this->period))
        ->assertInertia(fn (Assert $page) => $page
            ->component('periods/show')
            ->where('payees.0.payee_id', $this->payee->id)
            ->where('payees.0.totals.outstanding', '50.000000000000000')
            ->where('payees.0.statement', null)
            ->where('audit.missing_contract', 0));
});

test('periods may not overlap', function () {
    $this->post(route('periods.store'), ['name' => 'Overlap', 'starts_on' => '2026-03-01', 'ends_on' => '2026-05-31'])
        ->assertSessionHasErrors('starts_on');

    $this->assertDatabaseMissing('periods', ['name' => 'Overlap']);
});

test('statements are generated as drafts and become visible once published', function () {
    $this->post(route('periods.statements', $this->period))->assertRedirect();

    $statement = Statement::query()->withoutGlobalScopes()->sole();

    expect($statement->payee_id)->toBe($this->payee->id)
        ->and($statement->published_at)->toBeNull()
        ->and($statement->payable)->toBe('50.000000000000000');

    $this->post(route('statements.publish', $statement->id))->assertRedirect();

    expect($statement->refresh()->published_at)->not->toBeNull();
});

test('a statement can be downloaded as csv', function () {
    $this->post(route('periods.statements', $this->period));
    $statement = Statement::query()->withoutGlobalScopes()->sole();

    $response = $this->get(route('statements.download', $statement->id))->assertOk();

    expect($response->streamedContent())->toContain($this->payee->name)->toContain('50.0000');
});

test('a recorded payout reduces what is still owed', function () {
    $this->post(route('periods.payout', $this->period), ['payee_id' => $this->payee->id, 'total' => '20', 'paid_on' => '2026-04-10'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('payouts', ['payee_id' => $this->payee->id, 'period_id' => $this->period->id, 'user_id' => $this->admin->id]);

    $this->get(route('periods.show', $this->period))
        ->assertInertia(fn (Assert $page) => $page->where('payees.0.totals.outstanding', '30.000000000000000'));
});

test('a payout above the payable amount is rejected', function () {
    $this->post(route('periods.payout', $this->period), ['payee_id' => $this->payee->id, 'total' => '50.02', 'paid_on' => '2026-04-10'])
        ->assertSessionHasErrors('total');

    $this->assertDatabaseCount('payouts', 0);
});

test('an expense entered by hand lowers the payee share', function () {
    $release = Release::query()->withoutGlobalScopes()->sole();

    $this->post(route('transactions.store'), [
        'type' => 'expense', 'timestamp' => '2026-02-01', 'paid_timestamp' => '2026-02-01', 'total' => '40',
        'currency' => 'EUR', 'release_id' => $release->id, 'category' => 'Mastering',
    ])->assertSessionHasNoErrors();

    $this->get(route('periods.show', $this->period))
        ->assertInertia(fn (Assert $page) => $page->where('payees.0.totals.payee_share', '30.000000000000000'));
});

test('a foreign currency transaction without a rate is stored but not counted', function () {
    $this->post(route('transactions.store'), [
        'type' => 'income', 'timestamp' => '2026-02-01', 'paid_timestamp' => '2026-02-01', 'total' => '80', 'currency' => 'usd',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', ['currency' => 'USD', 'converted_total' => null]);
});
