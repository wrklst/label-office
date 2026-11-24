<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the payee list shows each payee with its tags and usage counts', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee', 'email' => 'payee@example.com', 'tags' => ['artist', 'remixer']]);
    $period = Period::factory()->create(['label_id' => $label->id]);
    ContractSplit::factory()->count(2)->create(['contract_id' => Contract::factory()->create(['label_id' => $label->id]), 'payee_id' => $payee->id]);
    Payout::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id]);
    Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('payees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payees/index')
            ->has('payees.data', 1)
            ->where('payees.data.0.name', 'Example Payee')
            ->where('payees.data.0.email', 'payee@example.com')
            ->where('payees.data.0.tags', ['artist', 'remixer'])
            ->where('payees.data.0.splits_count', 2)
            ->where('payees.data.0.payouts_count', 1)
            ->where('payees.data.0.statements_count', 1));
});

test('searching the payee list matches part of the name', function () {
    $label = Label::factory()->create();
    Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee']);
    Payee::factory()->create(['label_id' => $label->id, 'name' => 'Sample Publisher']);

    $this->actingAs(labelAdmin($label))
        ->get(route('payees.index', ['search' => 'publish']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('payees.data', 1)
            ->where('payees.data.0.name', 'Sample Publisher')
            ->where('filters.search', 'publish'));
});

test('the payee page shows what the payee is owed in the latest period', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee']);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'split_amount' => '0.5']);
    $release = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $contract->id]);
    Period::factory()->create(['label_id' => $label->id, 'name' => '2025 Q4', 'starts_on' => '2025-10-01', 'ends_on' => '2025-12-31']);
    $latest = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-03-31']);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'total' => '100']);

    $this->actingAs(labelAdmin($label))
        ->get(route('payees.show', $payee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payees/show')
            ->where('payee.name', 'Example Payee')
            ->where('period', ['id' => $latest->id, 'name' => '2026 Q1'])
            ->where('summary.totals.outstanding', '50.000000000000000')
            ->has('summary.ledgers', 1)
            ->missing('summary.ledgers.0.releases'));
});

test('the payee page has no summary while the label has no period', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('payees.show', $payee->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', null)
            ->where('summary', null));
});

test('the payee page lists the payee\'s own splits, payouts, statements and transfers', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $other = Payee::factory()->create(['label_id' => $label->id]);
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);
    $ledger = Ledger::factory()->create(['label_id' => $label->id, 'name' => 'Example Ledger']);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);
    $split = ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'ledger_id' => $ledger->id, 'split_amount' => '0.25']);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $other->id]);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'contract_id' => $contract->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track', 'contract_id' => $contract->id]);
    $shared = ['label_id' => $label->id, 'period_id' => $period->id];
    Payout::factory()->create([...$shared, 'payee_id' => $payee->id, 'total' => '20', 'paid_on' => '2026-04-10', 'memo' => 'Bank transfer']);
    Payout::factory()->create([...$shared, 'payee_id' => $payee->id, 'total' => '30', 'paid_on' => '2026-05-02']);
    Payout::factory()->create([...$shared, 'payee_id' => $other->id]);
    $statement = Statement::factory()->create([...$shared, 'payee_id' => $payee->id, 'name' => 'Example Statement', 'published_at' => '2026-04-01 09:00:00']);
    Statement::factory()->create([...$shared, 'payee_id' => $payee->id, 'archived' => true]);
    Statement::factory()->create([...$shared, 'payee_id' => $other->id]);
    $transfer = Transaction::factory()->create(['label_id' => $label->id, 'type' => 'transfer', 'payee_id' => $payee->id, 'release_id' => $release->id, 'ledger_id' => $ledger->id, 'total' => '500', 'paid_timestamp' => '2026-02-01', 'category' => 'Advance', 'memo' => 'Recording advance']);
    Transaction::factory()->create(['label_id' => $label->id, 'type' => 'transfer', 'payee_id' => $other->id]);
    Transaction::factory()->create(['label_id' => $label->id, 'type' => 'expense', 'payee_id' => $payee->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('payees.show', $payee->id))
        ->assertInertia(fn (Assert $page) => $page
            ->has('splits', 1)
            ->where('splits.0', [
                'id' => $split->id,
                'contract_id' => $contract->id,
                'contract' => 'Example Deal',
                'split_amount' => '0.250000000',
                'ledger' => 'Example Ledger',
                'territory' => null,
                'used_by' => ['Example Release', 'Example Track'],
                'used_by_links' => [
                    ['to' => 'release', 'id' => $release->id, 'name' => 'Example Release'],
                    ['to' => 'track', 'id' => $track->id, 'name' => 'Example Track'],
                ],
            ])
            ->has('payouts.data', 2)
            ->where('payouts.data.0.paid_on', '2026-05-02')
            ->where('payouts.data.1.paid_on', '2026-04-10')
            ->where('payouts.data.1.total', '20.000000000000000')
            ->where('payouts.data.1.period', '2026 Q1')
            ->where('payouts.data.1.memo', 'Bank transfer')
            ->has('statements.data', 1)
            ->where('statements.data.0.id', $statement->id)
            ->where('statements.data.0.name', 'Example Statement')
            ->where('statements.data.0.period', '2026 Q1')
            ->where('statements.data.0.published', true)
            ->has('transfers.data', 1)
            ->where('transfers.data.0.id', $transfer->id)
            ->where('transfers.data.0.paid_timestamp', '2026-02-01')
            ->where('transfers.data.0.total', '500.000000000000000')
            ->where('transfers.data.0.category', 'Advance')
            ->where('transfers.data.0.memo', 'Recording advance')
            ->where('transfers.data.0.release', 'Example Release')
            ->where('transfers.data.0.ledger', 'Example Ledger'));
});

test('a payee is created with a comma-separated tag list stored as separate tags', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->from(route('payees.index'))
        ->post(route('payees.store'), [
            'label_id' => Label::factory()->create()->id,
            'name' => 'Example Payee',
            'email' => 'payee@example.com',
            'tags' => ' artist, remixer ,, producer',
            'notes' => 'Prefers bank transfer.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('payees.index'));

    $payee = Payee::query()->where('name', 'Example Payee')->sole();

    expect($payee->label_id)->toBe($label->id)
        ->and($payee->email)->toBe('payee@example.com')
        ->and($payee->tags)->toBe(['artist', 'remixer', 'producer'])
        ->and($payee->notes)->toBe('Prefers bank transfer.');
});

test('a payee can be created with tags sent as a list or with none at all', function (mixed $tags, ?array $stored) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('payees.store'), ['name' => 'Example Payee', 'tags' => $tags])
        ->assertSessionHasNoErrors();

    expect(Payee::query()->where('name', 'Example Payee')->sole()->tags)->toBe($stored);
})->with([
    'a list' => [['artist', 'remixer'], ['artist', 'remixer']],
    'an empty field' => ['', null],
]);

test('a payee is rejected when a value is invalid', function (array $input, string $field, string $message) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('payees.store'), ['name' => 'Example Payee', ...$input])
        ->assertSessionHasErrors([$field => $message]);

    $this->assertDatabaseCount('payees', 0);
})->with([
    'missing name' => [['name' => ''], 'name', 'The name field is required.'],
    'malformed email' => [['email' => 'not-an-email'], 'email', 'The email field must be a valid email address.'],
    'tag longer than 50 characters' => [['tags' => 'artist, '.str_repeat('x', 51)], 'tags.1', 'The tags.1 field must not be greater than 50 characters.'],
]);

test('a payee is updated', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee', 'tags' => ['artist']]);

    $this->actingAs(labelAdmin($label))
        ->from(route('payees.show', $payee->id))
        ->put(route('payees.update', $payee->id), ['name' => 'Renamed Payee', 'email' => 'renamed@example.com', 'tags' => 'producer, publisher', 'notes' => 'Updated.'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('payees.show', $payee->id))
        ->assertInertiaFlash('toast.message', 'Payee saved.');

    $payee->refresh();

    expect($payee->name)->toBe('Renamed Payee')
        ->and($payee->email)->toBe('renamed@example.com')
        ->and($payee->tags)->toBe(['producer', 'publisher'])
        ->and($payee->notes)->toBe('Updated.');
});

test('a payee keeps who they are as a party to an agreement', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);

    $this->actingAs(labelAdmin($label))
        ->put(route('payees.update', $payee->id), ['name' => 'Example Artist', 'legal_name' => 'Alex Example', 'address' => "Example Road 2\nLondon", 'country' => 'gb', 'vat_id' => ''])
        ->assertSessionHasNoErrors();

    expect($payee->refresh()->only(['legal_name', 'address', 'country', 'vat_id']))
        ->toBe(['legal_name' => 'Alex Example', 'address' => "Example Road 2\nLondon", 'country' => 'GB', 'vat_id' => null]);

    $this->actingAs(labelAdmin($label))
        ->put(route('payees.update', $payee->id), ['name' => 'Example Artist', 'country' => 'GBR'])
        ->assertSessionHasErrors('country');
});

test('a payee with payouts or statements is not deleted', function (string $model) {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $model::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => Period::factory()->create(['label_id' => $label->id])]);

    $this->actingAs(labelAdmin($label))
        ->from(route('payees.show', $payee->id))
        ->delete(route('payees.destroy', $payee->id))
        ->assertRedirect(route('payees.show', $payee->id));

    $this->assertModelExists($payee);
})->with([
    'a payout' => [Payout::class],
    'a statement' => [Statement::class],
]);

test('a payee without payouts or statements is deleted together with its splits', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id]);

    $this->actingAs(labelAdmin($label))
        ->delete(route('payees.destroy', $payee->id))
        ->assertRedirect(route('payees.index'));

    $this->assertModelMissing($payee);
    $this->assertModelExists($contract);
    $this->assertDatabaseCount('contract_splits', 0);
});

test('deleting a payee unlinks the member who signed in as them', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $member = User::factory()->create();
    $label->users()->attach($member, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    $this->actingAs(labelAdmin($label))->delete(route('payees.destroy', $payee->id));

    $this->assertModelMissing($payee);
    $this->assertDatabaseHas('label_user', ['user_id' => $member->id, 'payee_id' => null]);
});
