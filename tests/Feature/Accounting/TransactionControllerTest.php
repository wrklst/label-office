<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\DataImport;
use App\Models\Item;
use App\Models\Label;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A payload the transaction form would send for a plain income line.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transactionPayload(array $overrides = []): array
{
    return [
        'type' => 'income',
        'timestamp' => '2026-02-01',
        'paid_timestamp' => '2026-02-15',
        'total' => '100',
        'currency' => 'EUR',
        ...$overrides,
    ];
}

/**
 * Four transactions that differ in every attribute the list can filter on,
 * plus one of another label. Returns the ids the filters refer to.
 *
 * @return array{release: int, import: int}
 */
function transactionsToFilter(Label $label): array
{
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'catalog_number' => 'EX001']);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track', 'isrc' => 'ZZTST2600001']);
    $import = DataImport::factory()->create(['label_id' => $label->id]);
    $payee = Payee::factory()->create(['label_id' => $label->id]);

    Transaction::factory()->create([
        'label_id' => $label->id, 'memo' => 'Alpha', 'total' => '100', 'quantity' => 2, 'release_id' => $release->id, 'track_id' => $track->id,
        'data_import_id' => $import->id, 'retailer' => 'Example Store', 'external_id' => 'EXT-1', 'timestamp' => '2026-01-10', 'paid_timestamp' => '2026-02-10',
    ]);
    Transaction::factory()->create([
        'label_id' => $label->id, 'memo' => 'Bravo', 'type' => 'expense', 'total' => '-40', 'category' => 'Mastering', 'release_id' => $release->id,
        'timestamp' => '2026-02-10', 'paid_timestamp' => '2026-02-20',
    ]);
    Transaction::factory()->create([
        'label_id' => $label->id, 'memo' => 'Charlie', 'type' => 'transfer', 'total' => '-10', 'category' => 'Advance', 'payee_id' => $payee->id,
        'timestamp' => '2026-03-10', 'paid_timestamp' => '2026-03-10',
    ]);
    Transaction::factory()->create([
        'label_id' => $label->id, 'memo' => 'Delta', 'total' => '80', 'currency' => 'USD', 'conversion_rate' => null, 'converted_total' => null,
        'retailer' => 'Other Shop', 'timestamp' => '2026-04-10', 'paid_timestamp' => '2026-05-10',
    ]);
    Transaction::factory()->create(['label_id' => Label::factory(), 'memo' => 'Foreign', 'total' => '999', 'retailer' => 'Example Store']);

    return ['release' => $release->id, 'import' => $import->id];
}

test('the transaction list shows the newest sale first with totals for the label', function () {
    $label = Label::factory()->create();
    transactionsToFilter($label);

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/index')
            ->where('transactions.data', fn ($rows) => collect($rows)->pluck('memo')->all() === ['Delta', 'Charlie', 'Bravo', 'Alpha'])
            ->where('transactions.data.3.type', 'income')
            ->where('transactions.data.3.timestamp', '2026-01-10')
            ->where('transactions.data.3.paid_timestamp', '2026-02-10')
            ->where('transactions.data.3.release.name', 'Example Release')
            ->where('transactions.data.3.track', 'Example Track')
            ->where('summary.transactions', 4)
            ->where('summary.total', '50.000000000000000')
            ->where('summary.quantity', '5.000000000000000')
            ->where('platformOptions', [['id' => 'x-example-store', 'name' => 'Example Store'], ['id' => 'x-other-shop', 'name' => 'Other Shop'], ['id' => 'unknown', 'name' => 'Unknown']])
            ->has('importOptions', 1)
            ->missing('releaseOptions')
            ->missing('trackOptions'));
});

test('the transaction list and its totals follow the filter', function (array $query, array $memos, string $total) {
    $label = Label::factory()->create();
    $ids = transactionsToFilter($label);
    $query = array_map(fn (string $value): string => (string) ($ids[ltrim($value, ':')] ?? $value), $query);

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data', fn ($rows) => collect($rows)->pluck('memo')->sort()->values()->all() === $memos)
            ->where('summary.transactions', count($memos))
            ->where('summary.total', $total)
            ->where('filters', ['from' => null, 'to' => null, 'date' => null, ...$query, 'sort' => null, 'direction' => null]));
})->with([
    'type' => [['type' => 'expense'], ['Bravo'], '-40.000000000000000'],
    'release' => [['release' => ':release'], ['Alpha', 'Bravo'], '60.000000000000000'],
    'import' => [['import' => ':import'], ['Alpha'], '100.000000000000000'],
    'platform' => [['retailer' => 'x-example-store'], ['Alpha'], '100.000000000000000'],
    'sold from' => [['from' => '2026-02-01'], ['Bravo', 'Charlie', 'Delta'], '-50.000000000000000'],
    'sold until' => [['to' => '2026-02-28'], ['Alpha', 'Bravo'], '60.000000000000000'],
    'sold between' => [['from' => '2026-02-01', 'to' => '2026-02-28'], ['Bravo'], '-40.000000000000000'],
    'paid between' => [['from' => '2026-02-01', 'to' => '2026-02-28', 'date' => 'paid'], ['Alpha', 'Bravo'], '60.000000000000000'],
    'paid from' => [['from' => '2026-05-01', 'date' => 'paid'], ['Delta'], '0.000000000000000'],
    'without a release, transfers aside' => [['unassigned' => '1'], ['Delta'], '0.000000000000000'],
    'without a converted amount' => [['unconverted' => '1'], ['Delta'], '0.000000000000000'],
    'search in the memo' => [['search' => 'brav'], ['Bravo'], '-40.000000000000000'],
    'search in the retailer' => [['search' => 'Other'], ['Delta'], '0.000000000000000'],
    'search in the category' => [['search' => 'Master'], ['Bravo'], '-40.000000000000000'],
    'search in the track name' => [['search' => 'Example Track'], ['Alpha'], '100.000000000000000'],
    'search in the release name' => [['search' => 'Example Release'], ['Alpha', 'Bravo'], '60.000000000000000'],
    'search in the catalog number' => [['search' => 'EX001'], ['Alpha', 'Bravo'], '60.000000000000000'],
    'search for an ISRC' => [['search' => 'ZZTST2600001'], ['Alpha'], '100.000000000000000'],
    'search for an external id' => [['search' => 'EXT-1'], ['Alpha'], '100.000000000000000'],
    'combined filters' => [['type' => 'income', 'release' => ':release'], ['Alpha'], '100.000000000000000'],
    'nothing matches' => [['search' => 'Zulu'], [], '0.000000000000000'],
]);

test('the new transaction form is prefilled from the query string', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.create', ['type' => 'transfer', 'release' => 7, 'payee' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/form')
            ->where('transaction', null)
            ->where('defaults', ['type' => 'transfer', 'release_id' => 7, 'payee_id' => 3, 'recoupable' => true]));
});

test('the new transaction form defaults to an income and offers only records of the label', function () {
    $label = Label::factory()->create();
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'catalog_number' => 'EX001']);
    Release::factory()->create(['label_id' => Label::factory()]);
    Payee::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('defaults', ['type' => 'income', 'release_id' => null, 'payee_id' => null, 'recoupable' => true])
            ->missing('releaseOptions')
            ->missing('trackOptions')
            ->missing('payeeOptions')
            ->missing('contractOptions')
            ->missing('ledgerOptions')
            ->where('categories', config('royalties.categories'))
            ->where('formats', config('royalties.formats')));
});

test('the platform filter treats the spellings of one platform as one choice', function () {
    $label = Label::factory()->create();
    foreach (['Apple' => 'First', 'Apple/iTunes' => 'Second', 'Example Store' => 'Third'] as $retailer => $memo) {
        Transaction::factory()->create(['label_id' => $label->id, 'memo' => $memo, 'retailer' => $retailer]);
    }
    Transaction::factory()->create(['label_id' => $label->id, 'memo' => 'Fourth', 'retailer' => null]);

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.index', ['retailer' => 'apple_music']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('platformOptions', [['id' => 'apple_music', 'name' => 'Apple Music'], ['id' => 'x-example-store', 'name' => 'Example Store'], ['id' => 'unknown', 'name' => 'Unknown']])
            ->where('transactions.data', fn ($rows) => collect($rows)->pluck('memo')->sort()->values()->all() === ['First', 'Second']));

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.index', ['retailer' => 'unknown']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data', fn ($rows) => collect($rows)->pluck('memo')->all() === ['Fourth']));
});

test('a link with a statement\'s own spelling of the platform still filters', function () {
    $label = Label::factory()->create();
    Transaction::factory()->create(['label_id' => $label->id, 'memo' => 'First', 'retailer' => 'Example Store']);
    Transaction::factory()->create(['label_id' => $label->id, 'memo' => 'Second', 'retailer' => 'Other Shop']);

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.index', ['retailer' => 'Example Store']))
        ->assertInertia(fn (Assert $page) => $page->where('transactions.data', fn ($rows) => collect($rows)->pluck('memo')->all() === ['First']));
});

test('an array given as a filter is refused or ignored, never an error', function (string $url) {
    $label = Label::factory()->create();
    Transaction::factory()->create(['label_id' => $label->id]);

    // The transaction list refuses it with a validation message; the others read it as no text.
    expect($this->actingAs(labelAdmin($label))->get($url)->status())->toBeIn([200, 302]);
})->with([
    'transactions search' => '/transactions?search[]=x',
    'releases search' => '/releases?search[]=x',
    'releases status' => '/releases?status[]=x',
    'tracks search' => '/tracks?search[]=x',
    'catalog search' => '/catalog?search[]=x',
    'artists search' => '/artists?search[]=x',
    'items search' => '/items?search[]=x',
    'imports search' => '/data-imports?search[]=x&source[]=y',
    'airplay search' => '/airplays?search[]=x&origin[]=y',
    'overheads search' => '/label-costs?search[]=x',
    'contracts search' => '/contracts?search[]=x',
    'payees search' => '/payees?search[]=x',
]);

test('a transaction of each type can be entered by hand', function (string $type, string $typed, string $total) {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $ledger = Ledger::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload([
            'type' => $type, 'total' => $typed, 'payee_id' => $payee->id, 'ledger_id' => $ledger->id, 'contract_id' => $contract->id,
            'quantity' => '3', 'price' => '5', 'category' => 'Rights', 'retailer' => 'Example Store', 'provider' => 'Example Distribution',
            'external_id' => 'EXT-9', 'memo' => 'Entered by hand', 'published' => false, 'billable' => false,
        ]))
        ->assertRedirect(route('transactions.index'))
        ->assertInertiaFlash('toast.message', 'Transaction created.');

    $this->assertDatabaseHas('transactions', [
        'label_id' => $label->id, 'type' => $type, 'total' => $total, 'converted_total' => $total, 'timestamp' => '2026-02-01', 'paid_timestamp' => '2026-02-15',
        'payee_id' => $payee->id, 'ledger_id' => $ledger->id, 'contract_id' => $contract->id, 'quantity' => 3, 'price' => 5,
        'category' => 'Rights', 'retailer' => 'Example Store', 'provider' => 'Example Distribution', 'external_id' => 'EXT-9',
        'memo' => 'Entered by hand', 'published' => false, 'billable' => false, 'source' => 'Manual', 'importer_source' => 'manual',
    ]);
})->with([
    'income' => ['income', '100', '100'],
    'expense, typed positive and stored negative' => ['expense', '40', '-40'],
    'transfer, typed with its sign' => ['transfer', '-25', '-25'],
]);

test('a transaction in the label currency is stored at rate 1 even when another rate is sent', function () {
    $label = Label::factory()->create(['currency' => 'EUR']);

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['total' => '100', 'currency' => 'eur', 'conversion_rate' => '2']))
        ->assertSessionHasNoErrors();

    $transaction = Transaction::query()->withoutGlobalScopes()->sole();

    expect($transaction->currency)->toBe('EUR')
        ->and($transaction->conversion_rate)->toEqual(1)
        ->and($transaction->total)->toBe('100.000000000000000')
        ->and($transaction->converted_total)->toBe('100.000000000000000');
});

test('a foreign currency transaction is converted with the given rate', function () {
    $label = Label::factory()->create(['currency' => 'EUR']);

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['total' => '80', 'currency' => 'usd', 'conversion_rate' => '0.5']))
        ->assertSessionHasNoErrors();

    $transaction = Transaction::query()->withoutGlobalScopes()->sole();

    expect($transaction->currency)->toBe('USD')
        ->and($transaction->conversion_rate)->toEqual(0.5)
        ->and($transaction->total)->toBe('80.000000000000000')
        ->and($transaction->converted_total)->toBe('40.000000000000000');
});

test('a transfer without a payee is rejected', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['type' => 'transfer', 'total' => '-25', 'payee_id' => '']))
        ->assertSessionHasErrors(['payee_id' => 'A transfer needs a payee.']);

    $this->assertDatabaseCount('transactions', 0);
});

test('a transaction for a track is booked on the release of that track', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $otherRelease = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['track_id' => $track->id, 'release_id' => $otherRelease->id]))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'track_id' => $track->id,
        'release_id' => $release->id,
        'item_id' => Item::query()->withoutGlobalScopes()->where('release_id', $release->id)->where('is_additionals', true)->sole()->id,
    ]);
});

test('a transaction for a release is booked on its additionals item', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    Item::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Release (LP)']);
    $additionals = Item::query()->withoutGlobalScopes()->where('release_id', $release->id)->where('name', '[Additionals]')->sole();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['release_id' => $release->id]))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', ['release_id' => $release->id, 'item_id' => $additionals->id]);
});

test('a transaction without a release has no item', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['release_id' => '', 'track_id' => '']))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', ['release_id' => null, 'track_id' => null, 'item_id' => null]);
});

test('the region and the currency are stored in upper case', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['region' => 'de', 'currency' => 'eur']))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', ['region' => 'DE', 'currency' => 'EUR']);
});

test('the format is stored in the configured spelling', function (?string $entered, ?string $stored) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['format' => $entered]))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', ['format' => $stored]);
})->with([
    'different case' => ['download track', 'Download Track'],
    'surrounding spaces' => ['  2xlp ', '2xLP'],
    'unknown format is kept' => ['Minidisc', 'Minidisc'],
    'empty' => ['', null],
]);

test('empty select boxes and inputs are stored as null', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload([
            'conversion_rate' => '', 'quantity' => '', 'price' => '', 'release_id' => '', 'track_id' => '',
            'contract_id' => '', 'payee_id' => '', 'ledger_id' => '', 'region' => '',
        ]))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'quantity' => null, 'price' => null, 'release_id' => null, 'track_id' => null,
        'contract_id' => null, 'payee_id' => null, 'ledger_id' => null, 'region' => null,
    ]);
});

test('a transaction needs a type, both dates, a total and a currency', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), [])
        ->assertSessionHasErrors(['type', 'timestamp', 'paid_timestamp', 'total', 'currency']);

    $this->assertDatabaseCount('transactions', 0);
});

test('a transaction with an invalid value is rejected', function (string $field, mixed $value) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload([$field => $value]))
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('transactions', 0);
})->with([
    'unknown type' => ['type', 'refund'],
    'sale date that is no date' => ['timestamp', 'someday'],
    'paid date that is no date' => ['paid_timestamp', 'someday'],
    'total that is no number' => ['total', 'a lot'],
    'currency with four letters' => ['currency', 'EURO'],
    'currency with digits' => ['currency', 'E1R'],
    'conversion rate of zero' => ['conversion_rate', '0'],
    'negative conversion rate' => ['conversion_rate', '-1.1'],
    'quantity that is no number' => ['quantity', 'many'],
    'price that is no number' => ['price', 'cheap'],
    'region with three letters' => ['region', 'DEU'],
    'memo above 1000 characters' => ['memo', str_repeat('a', 1001)],
    'published that is no boolean' => ['published', 'maybe'],
]);

test('a transaction cannot reference a record of another label', function (string $field, string $model) {
    $label = Label::factory()->create();
    $foreign = $model::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['type' => 'transfer', $field => $foreign->id]))
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('transactions', 0);
})->with([
    'release' => ['release_id', Release::class],
    'track' => ['track_id', Track::class],
    'contract' => ['contract_id', Contract::class],
    'payee' => ['payee_id', Payee::class],
    'ledger' => ['ledger_id', Ledger::class],
]);

test('a transaction cannot be filed under another label or given a converted amount directly', function () {
    $label = Label::factory()->create();
    $other = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('transactions.store'), transactionPayload(['label_id' => $other->id, 'converted_total' => '5000', 'importer_source' => 'bandcamp']))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('transactions', ['label_id' => $label->id, 'converted_total' => 100, 'importer_source' => 'manual']);
    $this->assertDatabaseMissing('transactions', ['label_id' => $other->id]);
});

test('the edit form shows the stored transaction', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $transaction = Transaction::factory()->create([
        'label_id' => $label->id, 'release_id' => $release->id, 'type' => 'expense', 'total' => '-40', 'category' => 'Mastering',
        'timestamp' => '2026-02-01', 'paid_timestamp' => '2026-02-15', 'memo' => 'Example memo',
    ]);

    $this->actingAs(labelAdmin($label))
        ->get(route('transactions.edit', $transaction->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transactions/form')
            ->where('defaults', null)
            ->where('transaction.id', $transaction->id)
            ->where('transaction.type', 'expense')
            ->where('transaction.total', '40.000000000000000')
            ->where('transaction.refund', false)
            ->where('transaction.converted_total', '-40.000000000000000')
            ->where('transaction.timestamp', '2026-02-01')
            ->where('transaction.paid_timestamp', '2026-02-15')
            ->where('transaction.release_id', $release->id)
            ->where('transaction.category', 'Mastering')
            ->where('transaction.memo', 'Example memo')
            ->missing('releaseOptions'));
});

test('updating a transaction saves the new values and converts the total again', function () {
    $label = Label::factory()->create(['currency' => 'EUR']);
    $release = Release::factory()->create(['label_id' => $label->id]);
    $transaction = Transaction::factory()->create(['label_id' => $label->id, 'total' => '10', 'source' => 'Example Distribution', 'importer_source' => 'template']);

    $this->actingAs(labelAdmin($label))
        ->put(route('transactions.update', $transaction->id), transactionPayload([
            'type' => 'expense', 'total' => '60', 'currency' => 'gbp', 'conversion_rate' => '1.5', 'release_id' => $release->id,
            'category' => 'Mastering', 'format' => 'cd', 'region' => 'gb', 'memo' => 'Corrected',
        ]))
        ->assertRedirect(route('transactions.index'))
        ->assertInertiaFlash('toast.message', 'Transaction saved.');

    $this->assertDatabaseCount('transactions', 1);
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id, 'type' => 'expense', 'total' => -60, 'currency' => 'GBP', 'conversion_rate' => 1.5, 'converted_total' => -90,
        'release_id' => $release->id, 'category' => 'Mastering', 'format' => 'CD', 'region' => 'GB', 'memo' => 'Corrected',
        'timestamp' => '2026-02-01', 'paid_timestamp' => '2026-02-15', 'source' => 'Example Distribution', 'importer_source' => 'template',
    ]);
});

test('updating a transaction with invalid data changes nothing', function () {
    $label = Label::factory()->create();
    $foreignRelease = Release::factory()->create(['label_id' => Label::factory()]);
    $transaction = Transaction::factory()->create(['label_id' => $label->id, 'total' => '10', 'memo' => 'Original']);

    $this->actingAs(labelAdmin($label))
        ->put(route('transactions.update', $transaction->id), transactionPayload(['release_id' => $foreignRelease->id, 'memo' => 'Changed']))
        ->assertSessionHasErrors('release_id');

    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'memo' => 'Original', 'release_id' => null]);
});

test('deleting a transaction removes it', function () {
    $label = Label::factory()->create();
    $transaction = Transaction::factory()->create(['label_id' => $label->id]);
    $kept = Transaction::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->from(route('transactions.index'))
        ->delete(route('transactions.destroy', $transaction->id))
        ->assertRedirect(route('transactions.index'))
        ->assertInertiaFlash('toast.message', 'Transaction deleted.');

    $this->assertModelMissing($transaction);
    $this->assertModelExists($kept);
});

test('a transaction of another label cannot be opened, changed or deleted', function () {
    $label = Label::factory()->create();
    $foreign = Transaction::factory()->create(['label_id' => Label::factory(), 'memo' => 'Theirs']);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('transactions.edit', $foreign->id))->assertNotFound();
    $this->actingAs($admin)->put(route('transactions.update', $foreign->id), transactionPayload(['memo' => 'Hijacked']))->assertNotFound();
    $this->actingAs($admin)->delete(route('transactions.destroy', $foreign->id))->assertNotFound();

    $this->assertDatabaseHas('transactions', ['id' => $foreign->id, 'memo' => 'Theirs']);
});

test('a payee cannot enter transactions', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    $this->actingAs($user)->post(route('transactions.store'), transactionPayload())->assertForbidden();

    $this->assertDatabaseCount('transactions', 0);
});

test('a guest is sent to the login page instead of the transactions', function () {
    $this->get(route('transactions.index'))->assertRedirect(route('login'));
});
