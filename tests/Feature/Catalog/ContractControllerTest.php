<?php

use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Item;
use App\Models\Label;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Territory;
use App\Models\Track;
use Inertia\Testing\AssertableInertia as Assert;

test('the contract list shows each contract with its splits and where it is used', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee']);
    $ledger = Ledger::factory()->create(['label_id' => $label->id, 'name' => 'Example Ledger']);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal', 'memo' => 'Signed in spring.']);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'ledger_id' => $ledger->id, 'split_amount' => '0.25']);
    $child = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Sub Deal']);
    $contract->children()->attach($child, ['weight' => 1]);
    $release = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $contract->id]);
    Track::factory()->count(2)->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $contract->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('contracts/index')
            ->has('contracts.data', 2)
            ->where('contracts.data.0.name', 'Example Deal')
            ->where('contracts.data.0.memo', 'Signed in spring.')
            ->where('contracts.data.0.releases_count', 1)
            ->where('contracts.data.0.tracks_count', 2)
            ->where('contracts.data.0.items_count', 0)
            ->where('contracts.data.0.children_count', 1)
            ->where('contracts.data.0.parents_count', 0)
            ->where('contracts.data.0.splits', [['payee' => 'Example Payee', 'payee_id' => $payee->id, 'split_amount' => '0.250000000', 'ledger' => 'Example Ledger']])
            ->where('contracts.data.1.name', 'Example Sub Deal')
            ->where('contracts.data.1.parents_count', 1));
});

test('searching the contract list matches part of the name', function () {
    $label = Label::factory()->create();
    Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);
    Contract::factory()->create(['label_id' => $label->id, 'name' => 'Sample Licence']);

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.index', ['search' => 'licen']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contracts.data', 1)
            ->where('contracts.data.0.name', 'Sample Licence'));
});

test('the unused filter hides contracts that a release, track, item or parent contract relies on', function () {
    $label = Label::factory()->create();
    Contract::factory()->create(['label_id' => $label->id, 'name' => 'Unused Deal']);
    $onRelease = Contract::factory()->create(['label_id' => $label->id]);
    $onTrack = Contract::factory()->create(['label_id' => $label->id]);
    $onItem = Contract::factory()->create(['label_id' => $label->id]);
    $asChild = Contract::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $onRelease->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $onTrack->id]);
    Item::factory()->create(['label_id' => $label->id, 'contract_id' => $onItem->id]);
    $onRelease->children()->attach($asChild, ['weight' => 1]);

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.index', ['unused' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contracts.data', 1)
            ->where('contracts.data.0.name', 'Unused Deal')
            ->where('filters.unused', true));
});

test('a contract is created with its splits stored as fractions and its child contracts weighted', function () {
    $label = Label::factory()->create();
    $firstPayee = Payee::factory()->create(['label_id' => $label->id]);
    $secondPayee = Payee::factory()->create(['label_id' => $label->id]);
    $ledger = Ledger::factory()->create(['label_id' => $label->id]);
    $territory = Territory::factory()->create(['label_id' => $label->id]);
    $child = Contract::factory()->create(['label_id' => $label->id]);

    $response = $this->actingAs(labelAdmin($label))->post(route('contracts.store'), [
        'name' => 'Example Deal',
        'memo' => 'Signed in spring.',
        'splits' => [
            ['payee_id' => $firstPayee->id, 'percentage' => 50],
            ['payee_id' => $secondPayee->id, 'percentage' => '12.5', 'ledger_id' => $ledger->id, 'territory_id' => $territory->id],
        ],
        'children' => [['contract_id' => $child->id, 'weight' => '2.5']],
    ]);

    $contract = Contract::query()->where('name', 'Example Deal')->sole();
    $response->assertSessionHasNoErrors()->assertRedirect(route('contracts.show', $contract));

    expect($contract->label_id)->toBe($label->id)
        ->and($contract->memo)->toBe('Signed in spring.');
    expect($contract->splits()->orderBy('id')->get()->map->only(['payee_id', 'ledger_id', 'territory_id', 'split_amount'])->all())->toBe([
        ['payee_id' => $firstPayee->id, 'ledger_id' => null, 'territory_id' => null, 'split_amount' => '0.500000000'],
        ['payee_id' => $secondPayee->id, 'ledger_id' => $ledger->id, 'territory_id' => $territory->id, 'split_amount' => '0.125000000'],
    ]);
    $this->assertDatabaseCount('contract_links', 1);
    $this->assertDatabaseHas('contract_links', ['parent_contract_id' => $contract->id, 'child_contract_id' => $child->id, 'weight' => 2.5]);
});

test('a split of the full 100 percent is accepted', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => 'Example Deal', 'splits' => [['payee_id' => $payee->id, 'percentage' => '100']]])
        ->assertSessionHasNoErrors();

    expect(ContractSplit::query()->sole()->split_amount)->toBe('1.000000000');
});

test('a contract cannot be created without a name', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'The name field is required.']);

    $this->assertDatabaseCount('contracts', 0);
});

test('a split outside the percentage range is rejected', function (mixed $percentage, string $message) {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => 'Example Deal', 'splits' => [['payee_id' => $payee->id, 'percentage' => $percentage]]])
        ->assertSessionHasErrors(['splits.0.percentage' => $message]);

    $this->assertDatabaseCount('contracts', 0);
    $this->assertDatabaseCount('contract_splits', 0);
})->with([
    'zero' => ['0', 'The split field must be greater than 0.'],
    'negative' => ['-5', 'The split field must be greater than 0.'],
    'above 100' => ['100.01', 'The split field must not be greater than 100.'],
    'not a number' => ['half', 'The split field must be a number.'],
    'missing' => ['', 'The split field is required.'],
]);

test('a split needs a payee', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => 'Example Deal', 'splits' => [['payee_id' => '', 'percentage' => 50]]])
        ->assertSessionHasErrors(['splits.0.payee_id' => 'The payee field is required.']);

    $this->assertDatabaseCount('contracts', 0);
});

test('a split cannot reference records of another label', function (string $field, string $model) {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $foreign = $model::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => 'Example Deal', 'splits' => [['payee_id' => $payee->id, 'percentage' => 50, $field => $foreign->id]]])
        ->assertSessionHasErrors("splits.0.{$field}");

    $this->assertDatabaseMissing('contracts', ['name' => 'Example Deal']);
    $this->assertDatabaseCount('contract_splits', 0);
})->with([
    'payee' => ['payee_id', Payee::class],
    'ledger' => ['ledger_id', Ledger::class],
    'territory' => ['territory_id', Territory::class],
]);

test('a contract of another label cannot be added as child contract', function () {
    $label = Label::factory()->create();
    $foreign = Contract::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => 'Example Deal', 'children' => [['contract_id' => $foreign->id, 'weight' => 1]]])
        ->assertSessionHasErrors('children.0.contract_id');

    $this->assertDatabaseMissing('contracts', ['name' => 'Example Deal']);
    $this->assertDatabaseCount('contract_links', 0);
});

test('the same child contract cannot be added twice', function () {
    $label = Label::factory()->create();
    $child = Contract::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => 'Example Deal', 'children' => [
            ['contract_id' => $child->id, 'weight' => 1],
            ['contract_id' => $child->id, 'weight' => 2],
        ]])
        ->assertSessionHasErrors(['children.0.contract_id' => 'The child contract field has a duplicate value.']);

    $this->assertDatabaseCount('contract_links', 0);
});

test('a child contract needs a weight above zero', function (mixed $weight, string $message) {
    $label = Label::factory()->create();
    $child = Contract::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->post(route('contracts.store'), ['name' => 'Example Deal', 'children' => [['contract_id' => $child->id, 'weight' => $weight]]])
        ->assertSessionHasErrors(['children.0.weight' => $message]);

    $this->assertDatabaseCount('contract_links', 0);
})->with([
    'zero' => ['0', 'The weight field must be greater than 0.'],
    'missing' => ['', 'The weight field is required.'],
]);

test('the contract form shows splits as percentages and its child contracts', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'split_amount' => '0.125']);
    $child = Contract::factory()->create(['label_id' => $label->id]);
    $parent = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Umbrella Deal']);
    $contract->children()->attach($child, ['weight' => '2.5']);
    $parent->children()->attach($contract, ['weight' => 1]);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'contract_id' => $contract->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track', 'contract_id' => $contract->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.edit', $contract->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('contracts/form')
            ->where('contract.name', 'Example Deal')
            ->where('contract.splits', [['payee_id' => $payee->id, 'ledger_id' => null, 'territory_id' => null, 'percentage' => '12.5']])
            ->where('contract.children', [['contract_id' => $child->id, 'weight' => '2.5']])
            ->missing('contractOptions')
            ->missing('payeeOptions'));
});

test('updating a contract replaces its splits and syncs its child contracts', function () {
    $label = Label::factory()->create();
    $oldPayee = Payee::factory()->create(['label_id' => $label->id]);
    $newPayee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $oldPayee->id, 'split_amount' => '0.5']);
    $removedChild = Contract::factory()->create(['label_id' => $label->id]);
    $keptChild = Contract::factory()->create(['label_id' => $label->id]);
    $addedChild = Contract::factory()->create(['label_id' => $label->id]);
    $contract->children()->attach([$removedChild->id => ['weight' => 1], $keptChild->id => ['weight' => 1]]);
    $otherContract = Contract::factory()->create(['label_id' => $label->id]);
    $untouched = ContractSplit::factory()->create(['contract_id' => $otherContract->id, 'payee_id' => $oldPayee->id]);

    $this->actingAs(labelAdmin($label))
        ->from(route('contracts.edit', $contract->id))
        ->put(route('contracts.update', $contract->id), [
            'name' => 'Example Deal (Renewed)',
            'memo' => 'Renewed for two years.',
            'splits' => [['payee_id' => $newPayee->id, 'percentage' => '33.3']],
            'children' => [
                ['contract_id' => $keptChild->id, 'weight' => 3],
                ['contract_id' => $addedChild->id, 'weight' => 1],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('contracts.show', $contract->id));

    $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'name' => 'Example Deal (Renewed)', 'memo' => 'Renewed for two years.']);
    $split = ContractSplit::query()->where('contract_id', $contract->id)->sole();
    expect($split->payee_id)->toBe($newPayee->id)
        ->and($split->split_amount)->toBe('0.333000000');
    $this->assertModelExists($untouched);
    $this->assertDatabaseCount('contract_links', 2);
    $this->assertDatabaseHas('contract_links', ['parent_contract_id' => $contract->id, 'child_contract_id' => $keptChild->id, 'weight' => 3]);
    $this->assertDatabaseHas('contract_links', ['parent_contract_id' => $contract->id, 'child_contract_id' => $addedChild->id, 'weight' => 1]);
});

test('a contract cannot be its own child contract', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);

    $this->actingAs(labelAdmin($label))
        ->put(route('contracts.update', $contract->id), ['name' => 'Example Deal', 'children' => [['contract_id' => $contract->id, 'weight' => 1]]])
        ->assertSessionHasErrors(['children.0.contract_id' => 'The selected child contract is invalid.']);

    $this->assertDatabaseCount('contract_links', 0);
});

test('a rejected contract update keeps the existing splits', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);
    $split = ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id]);

    $this->actingAs(labelAdmin($label))
        ->put(route('contracts.update', $contract->id), ['name' => 'Changed Deal', 'splits' => [['payee_id' => $payee->id, 'percentage' => '150']]])
        ->assertSessionHasErrors('splits.0.percentage');

    $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'name' => 'Example Deal']);
    $this->assertModelExists($split);
});

test('deleting a contract removes its splits and links and leaves its releases without a contract', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => Payee::factory()->create(['label_id' => $label->id])]);
    $child = Contract::factory()->create(['label_id' => $label->id]);
    $contract->children()->attach($child, ['weight' => 1]);
    $release = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $contract->id]);

    $this->actingAs(labelAdmin($label))
        ->delete(route('contracts.destroy', $contract->id))
        ->assertRedirect(route('contracts.index'));

    $this->assertModelMissing($contract);
    $this->assertModelExists($child);
    $this->assertDatabaseCount('contract_splits', 0);
    $this->assertDatabaseCount('contract_links', 0);
    $this->assertDatabaseHas('releases', ['id' => $release->id, 'contract_id' => null]);
});

test('the contract page shows its terms, its splits and where it applies', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee']);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal', 'memo' => 'Signed in spring.']);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'split_amount' => '0.125']);
    $child = Contract::factory()->create(['label_id' => $label->id]);
    $parent = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Umbrella Deal']);
    $contract->children()->attach($child, ['weight' => '2.5']);
    $parent->children()->attach($contract, ['weight' => 1]);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'contract_id' => $contract->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track', 'contract_id' => $contract->id]);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Other Release']);

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.show', $contract->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('contracts/show')
            ->where('contract.name', 'Example Deal')
            ->where('contract.memo', 'Signed in spring.')
            ->where('contract.splits.0.payee', ['id' => $payee->id, 'name' => 'Example Payee'])
            ->where('contract.splits.0.percentage', '12.5')
            ->where('contract.children', [['id' => $child->id, 'name' => $child->name, 'weight' => '2.5']])
            ->where('contract.parents', [['id' => $parent->id, 'name' => 'Example Umbrella Deal']])
            ->where('releases.total', 1)
            ->where('releases.data.0.name', 'Example Release')
            ->where('tracks.total', 1)
            ->where('tracks.data.0', ['id' => $track->id, 'name' => 'Example Track', 'release' => ['id' => $release->id, 'name' => 'Example Release']]));
});

test('the releases and tracks of a contract are paged on their own', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    Release::factory()->count(27)->create(['label_id' => $label->id, 'contract_id' => $contract->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.show', ['contract' => $contract->id, 'releases_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('releases.total', 27)
            ->has('releases.data', 2)
            ->where('releases.current_page', 2));
});

test('the page of a contract of another label is not found', function () {
    $contract = Contract::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin(Label::factory()->create()))->get(route('contracts.show', $contract->id))->assertNotFound();
});

test('saving a contract returns to the page the form was opened from, and only to a local one', function (string $returnTo, bool $accepted) {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);

    $response = $this->actingAs(labelAdmin($label))
        ->put(route('contracts.update', $contract->id), ['name' => 'Example Deal', 'return_to' => $returnTo]);

    $response->assertRedirect($accepted ? $returnTo : route('contracts.show', $contract->id));
})->with([
    'the contracts list with a filter' => ['/contracts?search=deal', true],
    'a payee page' => ['/payees/3', true],
    'another host' => ['https://evil.example/contracts', false],
    'a scheme-less address' => ['//evil.example', false],
]);

test('the contract form hands the page it was opened from to the form', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.edit', ['contract' => $contract->id, 'return_to' => '/contracts?unused=1']))
        ->assertInertia(fn (Assert $page) => $page->where('returnTo', '/contracts?unused=1'));

    $this->actingAs(labelAdmin($label))
        ->get(route('contracts.edit', ['contract' => $contract->id, 'return_to' => '//evil.example']))
        ->assertInertia(fn (Assert $page) => $page->where('returnTo', null));
});
