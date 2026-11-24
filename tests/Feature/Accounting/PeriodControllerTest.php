<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a payee who gets half of the given income, paid in February 2026.
 */
function payeeEarningHalf(Label $label, string $name, string $income): Payee
{
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => $name]);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => "{$name} contract"]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'split_amount' => '0.5']);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => "{$name} release"]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $contract->id]);
    Transaction::factory()->create([
        'label_id' => $label->id, 'release_id' => $release->id, 'total' => $income, 'format' => 'Download Track',
        'timestamp' => '2026-02-01', 'paid_timestamp' => '2026-02-15',
    ]);

    return $payee;
}

test('the period list shows the newest period first with its payouts and current statements', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $first = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);
    Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30', 'closed_at' => now()]);
    Period::factory()->create(['label_id' => Label::factory(), 'name' => 'Foreign']);
    Payout::factory()->count(2)->create(['label_id' => $label->id, 'period_id' => $first->id, 'payee_id' => $payee->id, 'total' => '15']);
    Statement::factory()->create(['label_id' => $label->id, 'period_id' => $first->id, 'payee_id' => $payee->id]);
    Statement::factory()->create(['label_id' => $label->id, 'period_id' => $first->id, 'payee_id' => $payee->id, 'archived' => true]);

    $this->actingAs(labelAdmin($label))
        ->get(route('periods.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('periods/index')
            ->has('periods.data', 2)
            ->where('periods.data.0.name', '2026 Q2')
            ->where('periods.data.0.closed', true)
            ->where('periods.data.0.payouts_count', 0)
            ->where('periods.data.0.statements_count', 0)
            ->where('periods.data.0.paid', '0.000000000000000')
            ->where('periods.data.1.id', $first->id)
            ->where('periods.data.1.starts_on', '2026-01-01')
            ->where('periods.data.1.ends_on', '2026-03-31')
            ->where('periods.data.1.closed', false)
            ->where('periods.data.1.payouts_count', 2)
            ->where('periods.data.1.statements_count', 1)
            ->where('periods.data.1.paid', fn (string $paid) => (float) $paid === 30.0));
});

test('the last full quarter is suggested when the label has no period', function (string $today, array $suggestion) {
    $label = Label::factory()->create();
    Period::factory()->create(['label_id' => Label::factory(), 'starts_on' => '2030-01-01', 'ends_on' => '2030-03-31']);
    $this->travelTo($today);

    $this->actingAs(labelAdmin($label))
        ->get(route('periods.index'))
        ->assertInertia(fn (Assert $page) => $page->where('suggestion', $suggestion));
})->with([
    'in the middle of a quarter' => ['2026-11-18 12:00:00', ['name' => '2026 Q3', 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30']],
    'on the first day of a year' => ['2026-01-01 00:30:00', ['name' => '2025 Q4', 'starts_on' => '2025-10-01', 'ends_on' => '2025-12-31']],
    'on the last day of a short month' => ['2026-06-30 23:00:00', ['name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']],
]);

test('the quarter after the latest period is suggested', function (array $latest, array $suggestion) {
    $label = Label::factory()->create();
    Period::factory()->create(['label_id' => $label->id, 'name' => 'Earlier', 'starts_on' => '2025-01-01', 'ends_on' => '2025-03-31']);
    Period::factory()->create(['label_id' => $label->id, 'name' => 'Latest', ...$latest]);
    $this->travelTo('2026-11-18 12:00:00');

    $this->actingAs(labelAdmin($label))
        ->get(route('periods.index'))
        ->assertInertia(fn (Assert $page) => $page->where('suggestion', $suggestion));
})->with([
    'after a quarter' => [['starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'], ['name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']],
    'after a whole year' => [['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'], ['name' => '2027 Q1', 'starts_on' => '2027-01-01', 'ends_on' => '2027-03-31']],
]);

test('a period can be created', function () {
    $label = Label::factory()->create();

    $response = $this->actingAs(labelAdmin($label))
        ->post(route('periods.store'), ['name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);

    $period = Period::query()->withoutGlobalScopes()->sole();
    $response->assertRedirect(route('periods.show', $period))->assertInertiaFlash('toast.message', 'Period created.');
    $this->assertDatabaseHas('periods', [
        'label_id' => $label->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', 'closed_at' => null,
    ]);
});

test('a period needs a name and both dates', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.store'), [])
        ->assertSessionHasErrors(['name', 'starts_on', 'ends_on']);

    $this->assertDatabaseCount('periods', 0);
});

test('a period with invalid dates is rejected', function (string $field, array $input) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.store'), ['name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', ...$input])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('periods', 0);
})->with([
    'end before the start' => ['ends_on', ['ends_on' => '2025-12-31']],
    'start that is no date' => ['starts_on', ['starts_on' => 'someday']],
    'end that is no date' => ['ends_on', ['ends_on' => 'someday']],
    'name above 255 characters' => ['name', ['name' => str_repeat('a', 256)]],
]);

test('a period may last a single day', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.store'), ['name' => 'One day', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-01'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('periods', ['name' => 'One day']);
});

test('a period touching the dates of another one is rejected and names it', function (string $startsOn, string $endsOn) {
    $label = Label::factory()->create();
    Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.store'), ['name' => 'Overlap', 'starts_on' => $startsOn, 'ends_on' => $endsOn])
        ->assertSessionHasErrors(['starts_on' => 'These dates overlap with the period "2026 Q1". A transaction may only count in one period.']);

    $this->assertDatabaseMissing('periods', ['name' => 'Overlap']);
})->with([
    'starts on its last day' => ['2026-03-31', '2026-06-30'],
    'ends on its first day' => ['2025-10-01', '2026-01-01'],
    'lies inside it' => ['2026-02-01', '2026-02-28'],
    'encloses it' => ['2025-12-01', '2026-04-30'],
]);

test('periods may follow one another without a gap', function (string $startsOn, string $endsOn) {
    $label = Label::factory()->create();
    Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.store'), ['name' => 'Neighbour', 'starts_on' => $startsOn, 'ends_on' => $endsOn])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('periods', ['name' => 'Neighbour', 'label_id' => $label->id]);
})->with([
    'the day after' => ['2026-04-01', '2026-06-30'],
    'the day before' => ['2025-10-01', '2025-12-31'],
]);

test('a period of another label does not block the same dates', function () {
    $label = Label::factory()->create();
    Period::factory()->create(['label_id' => Label::factory(), 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.store'), ['name' => 'Ours', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('periods', ['name' => 'Ours', 'label_id' => $label->id]);
});

test('a period can be renamed and moved within its own dates', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);

    $this->actingAs(labelAdmin($label))
        ->from(route('periods.show', $period))
        ->put(route('periods.update', $period), ['name' => 'First quarter', 'starts_on' => '2026-01-15', 'ends_on' => '2026-03-15'])
        ->assertRedirect(route('periods.show', $period))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Period saved.');

    $this->assertDatabaseHas('periods', ['id' => $period->id, 'name' => 'First quarter', 'starts_on' => '2026-01-15', 'ends_on' => '2026-03-15']);
});

test('a period cannot be moved onto another period', function () {
    $label = Label::factory()->create();
    Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);

    $this->actingAs(labelAdmin($label))
        ->put(route('periods.update', $period), ['name' => '2026 Q2', 'starts_on' => '2026-03-31', 'ends_on' => '2026-06-30'])
        ->assertSessionHasErrors(['starts_on' => 'These dates overlap with the period "2026 Q1". A transaction may only count in one period.']);

    $this->assertDatabaseHas('periods', ['id' => $period->id, 'starts_on' => '2026-04-01']);
});

test('updating a period with an end before its start changes nothing', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);

    $this->actingAs(labelAdmin($label))
        ->put(route('periods.update', $period), ['name' => 'Changed', 'starts_on' => '2026-03-01', 'ends_on' => '2026-02-01'])
        ->assertSessionHasErrors('ends_on');

    $this->assertDatabaseHas('periods', ['id' => $period->id, 'name' => '2026 Q1']);
});

test('a period without payouts can be deleted', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->delete(route('periods.destroy', $period))
        ->assertRedirect(route('periods.index'))
        ->assertInertiaFlash('toast.message', 'Period deleted.');

    $this->assertModelMissing($period);
});

test('a period with payouts cannot be deleted', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    $payout = Payout::factory()->create(['label_id' => $label->id, 'period_id' => $period->id, 'payee_id' => Payee::factory()->create(['label_id' => $label->id])]);

    $this->actingAs(labelAdmin($label))
        ->from(route('periods.show', $period))
        ->delete(route('periods.destroy', $period))
        ->assertRedirect(route('periods.show', $period))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This period has payouts or published statements and cannot be deleted.']);

    $this->assertModelExists($period);
    $this->assertModelExists($payout);
});

test('a period of another label cannot be opened, changed or deleted', function () {
    $label = Label::factory()->create();
    $foreign = Period::factory()->create(['label_id' => Label::factory(), 'name' => 'Theirs']);
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('periods.show', $foreign->id))->assertNotFound();
    $this->actingAs($admin)->get(route('periods.payee', [$foreign->id, $payee->id]))->assertNotFound();
    $this->actingAs($admin)->put(route('periods.update', $foreign->id), ['name' => 'Hijacked', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'])->assertNotFound();
    $this->actingAs($admin)->post(route('periods.statements', $foreign->id))->assertNotFound();
    $this->actingAs($admin)->delete(route('periods.destroy', $foreign->id))->assertNotFound();

    $this->assertDatabaseHas('periods', ['id' => $foreign->id, 'name' => 'Theirs']);
    $this->assertDatabaseCount('statements', 0);
});

test('the payee breakdown shows what the payee earned in the period', function () {
    $label = Label::factory()->create(['currency' => 'EUR']);
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);
    $payee = payeeEarningHalf($label, 'Example Artist', '100');
    payeeEarningHalf($label, 'Other Artist', '300');

    $this->actingAs(labelAdmin($label))
        ->get(route('periods.payee', [$period, $payee]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('periods/payee')
            ->where('period', ['id' => $period->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'])
            ->where('payee', ['id' => $payee->id, 'name' => 'Example Artist'])
            ->where('currency', 'EUR')
            ->where('statement.payee_id', $payee->id)
            ->where('statement.currency', 'EUR')
            ->where('statement.period.name', '2026 Q1')
            ->where('statement.totals.income', '100.000000000000000')
            ->where('statement.totals.payee_share', '50.000000000000000')
            ->where('statement.totals.outstanding', '50.000000000000000')
            ->has('statement.ledgers', 1)
            ->where('statement.ledgers.0.ledger_name', 'Primary')
            ->where('statement.ledgers.0.releases.0.release_name', 'Example Artist release')
            ->has('statement.income_rows', 1)
            ->where('statement.income_rows.0.format', 'Download Track')
            ->where('statement.income_rows.0.payee_share', '50.000000000000000'));
});

test('the payee breakdown is empty for a payee without activity', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    payeeEarningHalf($label, 'Other Artist', '300');

    $this->actingAs(labelAdmin($label))
        ->get(route('periods.payee', [$period, $payee]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payee', ['id' => $payee->id, 'name' => 'Example Artist'])
            ->where('statement', null));
});

test('the payee breakdown of a payee of another label is not found', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    $foreign = Payee::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->get(route('periods.payee', [$period->id, $foreign->id]))
        ->assertNotFound();
});

test('statements are generated for every payee with activity', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);
    $first = payeeEarningHalf($label, 'Example Artist', '100');
    $second = payeeEarningHalf($label, 'Other Artist', '300');
    $idle = Payee::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->from(route('periods.show', $period))
        ->post(route('periods.statements', $period))
        ->assertRedirect(route('periods.show', $period))
        ->assertInertiaFlash('toast.message', '2 statements generated.');

    $this->assertDatabaseCount('statements', 2);
    $this->assertDatabaseHas('statements', ['label_id' => $label->id, 'period_id' => $period->id, 'payee_id' => $first->id, 'name' => 'Example Artist – 2026 Q1', 'payable' => 50, 'archived' => false, 'published_at' => null]);
    $this->assertDatabaseHas('statements', ['period_id' => $period->id, 'payee_id' => $second->id, 'name' => 'Other Artist – 2026 Q1', 'payable' => 150]);
    $this->assertDatabaseMissing('statements', ['payee_id' => $idle->id]);
});

test('a statement can be generated for a single payee', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    $first = payeeEarningHalf($label, 'Example Artist', '100');
    payeeEarningHalf($label, 'Other Artist', '300');

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.statements', $period), ['payee_id' => $first->id])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', '1 statement generated.');

    $this->assertDatabaseCount('statements', 1);
    $this->assertDatabaseHas('statements', ['payee_id' => $first->id, 'period_id' => $period->id]);
});

test('no statement is generated for a payee without activity or of another label', function (bool $ownLabel) {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    payeeEarningHalf($label, 'Example Artist', '100');
    $payee = Payee::factory()->create(['label_id' => $ownLabel ? $label->id : Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.statements', $period), ['payee_id' => $payee->id])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', '0 statements generated.');

    $this->assertDatabaseCount('statements', 0);
})->with([
    'payee without activity' => [true],
    'payee of another label' => [false],
]);

test('a revision of a published statement is a draft and the published one stays with the payee until then', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    $payee = payeeEarningHalf($label, 'Example Artist', '100');
    $published = Statement::factory()->create(['label_id' => $label->id, 'period_id' => $period->id, 'payee_id' => $payee->id, 'published_at' => '2026-04-05 10:00:00']);

    $this->actingAs(labelAdmin($label))
        ->post(route('periods.statements', $period), ['payee_id' => $payee->id])
        ->assertRedirect();

    $this->assertDatabaseHas('statements', ['id' => $published->id, 'archived' => false, 'published_at' => '2026-04-05 10:00:00']);
    $this->assertDatabaseHas('statements', ['revision_of_id' => $published->id, 'archived' => false, 'published_at' => null, 'payable' => 50]);

    $revision = Statement::query()->withoutGlobalScopes()->where('revision_of_id', $published->id)->sole();
    $this->post(route('statements.publish', $revision->id))->assertRedirect();

    $this->assertDatabaseHas('statements', ['id' => $published->id, 'archived' => true]);
    expect($revision->refresh()->published_at)->not->toBeNull();
});

test('the next period is suggested without overlapping the latest one', function (string $today, ?array $latest, array $expected) {
    $label = Label::factory()->create();

    if ($latest !== null) {
        Period::factory()->create(['label_id' => $label->id, 'starts_on' => $latest[0], 'ends_on' => $latest[1]]);
    }

    $this->travelTo($today);

    $this->actingAs(labelAdmin($label))->get(route('periods.index'))
        ->assertInertia(fn (Assert $page) => $page->where('suggestion', $expected));
})->with([
    'the last full quarter on the last day of a year' => ['2026-12-31', null, ['name' => '2026 Q3', 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30']],
    'the rest of the quarter after a period ending mid-quarter' => ['2026-08-01', ['2026-04-01', '2026-05-15'], ['name' => '2026 Q2', 'starts_on' => '2026-05-16', 'ends_on' => '2026-06-30']],
    'the following quarter after a full quarter' => ['2026-08-01', ['2026-01-01', '2026-03-31'], ['name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']],
]);

test('a period with a published statement cannot be deleted', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    Statement::factory()->create(['label_id' => $label->id, 'period_id' => $period->id, 'published_at' => now()]);

    $this->actingAs(labelAdmin($label))->delete(route('periods.destroy', $period->id));

    $this->assertDatabaseHas('periods', ['id' => $period->id]);
    $this->assertDatabaseCount('statements', 1);
});

test('a payee cannot create periods or generate statements', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    $payee = payeeEarningHalf($label, 'Example Artist', '100');
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    $this->actingAs($user)->post(route('periods.store'), ['name' => 'Sneaky', 'starts_on' => '2027-01-01', 'ends_on' => '2027-03-31'])->assertForbidden();
    $this->actingAs($user)->post(route('periods.statements', $period))->assertForbidden();
    $this->actingAs($user)->get(route('periods.payee', [$period, $payee]))->assertRedirect(route('portal.index'));

    $this->assertDatabaseMissing('periods', ['name' => 'Sneaky']);
    $this->assertDatabaseCount('statements', 0);
});

test('a period with payouts can be renamed but keeps its dates', function () {
    $label = Label::factory()->create();
    $period = Period::factory()->create(['label_id' => $label->id]);
    Payout::factory()->create(['label_id' => $label->id, 'period_id' => $period->id, 'payee_id' => Payee::factory()->create(['label_id' => $label->id])]);
    $this->actingAs(labelAdmin($label));

    $this->put(route('periods.update', $period), ['name' => 'First quarter', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31'])
        ->assertSessionHasNoErrors();
    $this->put(route('periods.update', $period), ['name' => 'First quarter', 'starts_on' => '2026-01-01', 'ends_on' => '2026-04-30'])
        ->assertSessionHasErrors(['starts_on' => 'This period has payouts, so its dates can no longer change.']);

    expect($period->refresh()->name)->toBe('First quarter')
        ->and($period->ends_on->toDateString())->toBe('2026-03-31');
});
