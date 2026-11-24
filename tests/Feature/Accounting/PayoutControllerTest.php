<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\PayoutAllocation;
use App\Models\Period;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Royalties\PayoutService;
use Inertia\Testing\AssertableInertia as Assert;

test('the payout list shows the latest payment first with the ledgers it was booked on', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);
    $ledger = Ledger::factory()->create(['label_id' => $label->id, 'name' => 'Album advance']);
    $earlier = Payout::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id, 'paid_on' => '2026-04-10']);
    $later = Payout::factory()->create([
        'label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id, 'user_id' => $admin->id,
        'total' => '30', 'paid_on' => '2026-04-20', 'memo' => 'Example payout',
    ]);
    PayoutAllocation::factory()->create(['payout_id' => $later->id, 'ledger_id' => null, 'amount' => '20']);
    PayoutAllocation::factory()->create(['payout_id' => $later->id, 'ledger_id' => $ledger->id, 'amount' => '10']);
    Payout::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs($admin)
        ->get(route('payouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payouts/index')
            ->has('payouts.data', 2)
            ->where('payouts.data.0.id', $later->id)
            ->where('payouts.data.0.total', '30.000000000000000')
            ->where('payouts.data.0.paid_on', '2026-04-20')
            ->where('payouts.data.0.memo', 'Example payout')
            ->where('payouts.data.0.payee', 'Example Artist')
            ->where('payouts.data.0.period', '2026 Q1')
            ->where('payouts.data.0.user', $admin->name)
            ->where('payouts.data.0.ledgers.0.name', 'Primary')
            ->where('payouts.data.0.ledgers.1.name', 'Album advance')
            ->where('payouts.data.1.id', $earlier->id)
            ->where('payouts.data.1.user', null)
            ->has('payouts.data.1.ledgers', 0));
});

test('deleting a payout makes the amount payable again', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'split_amount' => '0.5']);
    $release = Release::factory()->create(['label_id' => $label->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $contract->id]);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'total' => '100']);
    $payout = app(PayoutService::class)->record($period, $payee, '20', '2026-04-10');
    $this->actingAs(labelAdmin($label));

    $this->from(route('payouts.index'))
        ->delete(route('payouts.destroy', $payout->id))
        ->assertRedirect(route('payouts.index'))
        ->assertInertiaFlash('toast.message', 'Payout deleted. The amount is payable again.');

    $this->assertModelMissing($payout);
    $this->assertDatabaseCount('payout_allocations', 0);
    $this->get(route('periods.show', $period))
        ->assertInertia(fn (Assert $page) => $page
            ->where('payees.0.totals.total_paid', '0.000000000000000')
            ->where('payees.0.totals.outstanding', '50.000000000000000'));
});

test('a payout of another label cannot be deleted', function () {
    $label = Label::factory()->create();
    $foreign = Payout::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))->delete(route('payouts.destroy', $foreign->id))->assertNotFound();

    $this->assertModelExists($foreign);
});

test('a payee cannot delete payouts', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $payout = Payout::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => Period::factory()->create(['label_id' => $label->id])]);
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    $this->actingAs($user)->delete(route('payouts.destroy', $payout->id))->assertForbidden();

    $this->assertModelExists($payout);
});
