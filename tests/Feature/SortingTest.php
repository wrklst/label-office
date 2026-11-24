<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\DataImport;
use App\Models\Item;
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
 * Three rows in every paginated list, named Alpha, Bravo and Charlie (Beta
 * for the middle artist), with amounts chosen so that sorting them as text
 * would give a different order than sorting them as numbers.
 */
function sortingScenario(): User
{
    $label = Label::factory()->create();
    $owned = ['label_id' => $label->id];

    [$alphaArtist, $betaArtist] = [
        Artist::factory()->create([...$owned, 'name' => 'Alpha Artist']),
        Artist::factory()->create([...$owned, 'name' => 'Beta Artist', 'notes' => 'Example note']),
    ];
    Artist::factory()->create([...$owned, 'name' => 'Charlie Artist']);

    [$dealA, $dealB] = [
        Contract::factory()->create([...$owned, 'name' => 'Alpha Deal']),
        Contract::factory()->create([...$owned, 'name' => 'Bravo Deal']),
    ];
    Contract::factory()->create([...$owned, 'name' => 'Charlie Deal']);

    $alphaRelease = Release::factory()->create([...$owned, 'name' => 'Alpha Release', 'catalog_number' => 'EX003', 'upc' => '4000000000031', 'released_on' => '2026-01-01', 'artist_id' => $betaArtist->id]);
    $bravoRelease = Release::factory()->create([...$owned, 'name' => 'Bravo Release', 'catalog_number' => 'EX002', 'upc' => '4000000000024', 'released_on' => '2026-03-01', 'artist_id' => $alphaArtist->id]);
    Release::factory()->create([...$owned, 'name' => 'Charlie Release', 'catalog_number' => null, 'upc' => null, 'released_on' => null]);

    Track::factory()->create([...$owned, 'name' => 'Alpha Track', 'release_id' => $alphaRelease->id, 'artist_id' => $betaArtist->id, 'isrc' => 'ZZTST2600003', 'contract_id' => $dealB->id]);
    Track::factory()->create([...$owned, 'name' => 'Bravo Track', 'release_id' => $alphaRelease->id, 'artist_id' => $alphaArtist->id, 'isrc' => 'ZZTST2600001']);
    Track::factory()->create([...$owned, 'name' => 'Charlie Track', 'release_id' => $bravoRelease->id, 'artist_id' => $betaArtist->id, 'isrc' => null, 'contract_id' => $dealA->id]);

    $alphaItem = Item::factory()->create([...$owned, 'name' => 'Alpha Item', 'release_id' => $bravoRelease->id, 'format_group' => 'Vinyl']);
    $bravoItem = Item::factory()->create([...$owned, 'name' => 'Bravo Item', 'release_id' => $alphaRelease->id, 'format_group' => 'Digital', 'contract_id' => $dealB->id]);
    Item::factory()->create([...$owned, 'name' => 'Charlie Item']);

    Transaction::factory()->create([...$owned, 'memo' => 'Alpha', 'release_id' => $bravoRelease->id, 'item_id' => $alphaItem->id, 'total' => '300', 'quantity' => 5, 'timestamp' => '2026-01-10', 'paid_timestamp' => '2026-02-20', 'retailer' => 'Example Store B', 'region' => 'DE']);
    Transaction::factory()->create([...$owned, 'memo' => 'Bravo', 'release_id' => $alphaRelease->id, 'item_id' => $bravoItem->id, 'total' => '50', 'quantity' => 9, 'timestamp' => '2026-02-10', 'paid_timestamp' => '2026-02-15', 'retailer' => 'Example Store A', 'region' => 'FR']);
    Transaction::factory()->create([...$owned, 'memo' => 'Charlie', 'type' => 'expense', 'total' => '-20', 'quantity' => 1, 'timestamp' => '2026-03-10', 'paid_timestamp' => '2026-03-10', 'retailer' => null, 'region' => null]);

    $alphaPayee = Payee::factory()->create([...$owned, 'name' => 'Alpha Payee', 'email' => null]);
    $bravoPayee = Payee::factory()->create([...$owned, 'name' => 'Bravo Payee', 'email' => 'bravo@example.com']);
    $charliePayee = Payee::factory()->create([...$owned, 'name' => 'Charlie Payee', 'email' => 'alpha@example.com']);

    $firstPeriod = Period::factory()->create([...$owned, 'name' => 'Period A']);
    $secondPeriod = Period::factory()->create([...$owned, 'name' => 'Period B', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);

    Statement::factory()->create([...$owned, 'name' => 'Alpha Statement', 'payee_id' => $bravoPayee->id, 'period_id' => $firstPeriod->id, 'balance' => '9', 'payable' => '9']);
    Statement::factory()->create([...$owned, 'name' => 'Bravo Statement', 'payee_id' => $alphaPayee->id, 'period_id' => $secondPeriod->id, 'balance' => '200', 'payable' => '200']);
    Statement::factory()->create([...$owned, 'name' => 'Charlie Statement', 'payee_id' => $charliePayee->id, 'period_id' => $firstPeriod->id, 'balance' => '-5', 'payable' => '0']);

    Payout::factory()->create([...$owned, 'memo' => 'Alpha', 'payee_id' => $bravoPayee->id, 'period_id' => $firstPeriod->id, 'total' => '9', 'paid_on' => '2026-04-01']);
    Payout::factory()->create([...$owned, 'memo' => 'Bravo', 'payee_id' => $bravoPayee->id, 'period_id' => $secondPeriod->id, 'total' => '200', 'paid_on' => '2026-04-03']);
    Payout::factory()->create([...$owned, 'memo' => 'Charlie', 'payee_id' => $charliePayee->id, 'period_id' => $firstPeriod->id, 'total' => '30', 'paid_on' => '2026-04-02']);

    DataImport::factory()->create([...$owned, 'name' => 'Alpha Import', 'total' => '9', 'transactions_count' => 3]);
    DataImport::factory()->create([...$owned, 'name' => 'Bravo Import', 'total' => '200', 'transactions_count' => 1]);
    DataImport::factory()->create([...$owned, 'name' => 'Charlie Import', 'total' => '30', 'transactions_count' => 2]);

    return labelAdmin($label);
}

/**
 * The first word of each row's label, in the order the page lists them.
 *
 * @return list<string>
 */
function sortedLabels(mixed $rows, string $field): array
{
    return collect($rows)->map(fn (array $row): string => explode(' ', $row[$field])[0])->all();
}

test('a paginated list is ordered by the chosen column', function (string $route, array $query, string $prop, string $field, array $expected) {
    $this->actingAs(sortingScenario())
        ->get(route($route, $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("{$prop}.data", fn ($rows) => sortedLabels($rows, $field) === $expected)
            ->where('filters.sort', $query['sort'])
            ->where('filters.direction', $query['direction'] ?? 'asc'));
})->with([
    'releases by name' => ['releases.index', ['sort' => 'name', 'direction' => 'asc'], 'releases', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'releases by name, descending' => ['releases.index', ['sort' => 'name', 'direction' => 'desc'], 'releases', 'name', ['Charlie', 'Bravo', 'Alpha']],
    'releases by name without a direction' => ['releases.index', ['sort' => 'name'], 'releases', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'releases by catalog number, none last' => ['releases.index', ['sort' => 'catalog_number', 'direction' => 'desc'], 'releases', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'releases by artist' => ['releases.index', ['sort' => 'artist', 'direction' => 'asc'], 'releases', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'releases by artist, descending, none last' => ['releases.index', ['sort' => 'artist', 'direction' => 'desc'], 'releases', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'releases by the contract of their tracks' => ['releases.index', ['sort' => 'contract', 'direction' => 'asc'], 'releases', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'releases by release date' => ['releases.index', ['sort' => 'released_on', 'direction' => 'desc'], 'releases', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'releases by track count' => ['releases.index', ['sort' => 'tracks_count', 'direction' => 'desc'], 'releases', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'releases by income' => ['releases.index', ['sort' => 'income', 'direction' => 'desc'], 'releases', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'releases by income, ascending' => ['releases.index', ['sort' => 'income', 'direction' => 'asc'], 'releases', 'name', ['Charlie', 'Alpha', 'Bravo']],

    'tracks by name, descending' => ['tracks.index', ['sort' => 'name', 'direction' => 'desc'], 'tracks', 'name', ['Charlie', 'Bravo', 'Alpha']],
    'tracks by artist' => ['tracks.index', ['sort' => 'artist', 'direction' => 'asc'], 'tracks', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'tracks by release, descending' => ['tracks.index', ['sort' => 'release', 'direction' => 'desc'], 'tracks', 'name', ['Charlie', 'Alpha', 'Bravo']],
    'tracks by ISRC, none last' => ['tracks.index', ['sort' => 'isrc', 'direction' => 'asc'], 'tracks', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'tracks by contract, none last' => ['tracks.index', ['sort' => 'contract', 'direction' => 'asc'], 'tracks', 'name', ['Charlie', 'Alpha', 'Bravo']],

    'artists by name, descending' => ['artists.index', ['sort' => 'name', 'direction' => 'desc'], 'artists', 'name', ['Charlie', 'Beta', 'Alpha']],
    'artists by track count' => ['artists.index', ['sort' => 'tracks_count', 'direction' => 'desc'], 'artists', 'name', ['Beta', 'Alpha', 'Charlie']],
    'artists by notes, none last' => ['artists.index', ['sort' => 'notes', 'direction' => 'asc'], 'artists', 'name', ['Beta', 'Alpha', 'Charlie']],

    'items by name, descending' => ['items.index', ['search' => 'Item', 'sort' => 'name', 'direction' => 'desc'], 'items', 'name', ['Charlie', 'Bravo', 'Alpha']],
    'items by release, none last' => ['items.index', ['search' => 'Item', 'sort' => 'release', 'direction' => 'asc'], 'items', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'items by contract override' => ['items.index', ['search' => 'Item', 'sort' => 'contract', 'direction' => 'desc'], 'items', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'items by total' => ['items.index', ['search' => 'Item', 'sort' => 'total', 'direction' => 'desc'], 'items', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'items by transaction count, ascending' => ['items.index', ['search' => 'Item', 'sort' => 'transactions_count', 'direction' => 'asc'], 'items', 'name', ['Charlie', 'Alpha', 'Bravo']],

    'contracts by name, descending' => ['contracts.index', ['sort' => 'name', 'direction' => 'desc'], 'contracts', 'name', ['Charlie', 'Bravo', 'Alpha']],
    'contracts by use' => ['contracts.index', ['sort' => 'usage', 'direction' => 'desc'], 'contracts', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'contracts by use, ascending' => ['contracts.index', ['sort' => 'usage', 'direction' => 'asc'], 'contracts', 'name', ['Charlie', 'Alpha', 'Bravo']],

    'payees by name, descending' => ['payees.index', ['sort' => 'name', 'direction' => 'desc'], 'payees', 'name', ['Charlie', 'Bravo', 'Alpha']],
    'payees by email, none last' => ['payees.index', ['sort' => 'email', 'direction' => 'asc'], 'payees', 'name', ['Charlie', 'Bravo', 'Alpha']],
    'payees by payout count' => ['payees.index', ['sort' => 'payouts_count', 'direction' => 'desc'], 'payees', 'name', ['Bravo', 'Charlie', 'Alpha']],

    'transactions by retailer, none last' => ['transactions.index', ['sort' => 'retailer', 'direction' => 'asc'], 'transactions', 'memo', ['Bravo', 'Alpha', 'Charlie']],
    'transactions by retailer, descending, none last' => ['transactions.index', ['sort' => 'retailer', 'direction' => 'desc'], 'transactions', 'memo', ['Alpha', 'Bravo', 'Charlie']],
    'transactions by release' => ['transactions.index', ['sort' => 'release', 'direction' => 'asc'], 'transactions', 'memo', ['Bravo', 'Alpha', 'Charlie']],
    'transactions by sale date, ascending' => ['transactions.index', ['sort' => 'timestamp', 'direction' => 'asc'], 'transactions', 'memo', ['Alpha', 'Bravo', 'Charlie']],
    'transactions by paid date' => ['transactions.index', ['sort' => 'paid_timestamp', 'direction' => 'asc'], 'transactions', 'memo', ['Bravo', 'Alpha', 'Charlie']],
    'transactions by units' => ['transactions.index', ['sort' => 'quantity', 'direction' => 'desc'], 'transactions', 'memo', ['Bravo', 'Alpha', 'Charlie']],
    'transactions by amount' => ['transactions.index', ['sort' => 'converted_total', 'direction' => 'desc'], 'transactions', 'memo', ['Alpha', 'Bravo', 'Charlie']],
    'transactions by amount within a filter' => ['transactions.index', ['type' => 'income', 'sort' => 'converted_total', 'direction' => 'asc'], 'transactions', 'memo', ['Bravo', 'Alpha']],

    'statements by payee' => ['statements.index', ['sort' => 'payee', 'direction' => 'asc'], 'statements', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'statements by period, descending' => ['statements.index', ['sort' => 'period', 'direction' => 'desc'], 'statements', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'statements by balance' => ['statements.index', ['sort' => 'balance', 'direction' => 'desc'], 'statements', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'statements by payable, ascending' => ['statements.index', ['sort' => 'payable', 'direction' => 'asc'], 'statements', 'name', ['Charlie', 'Alpha', 'Bravo']],

    'payouts by memo, descending' => ['payouts.index', ['sort' => 'memo', 'direction' => 'desc'], 'payouts', 'memo', ['Charlie', 'Bravo', 'Alpha']],
    'payouts by payee' => ['payouts.index', ['sort' => 'payee', 'direction' => 'asc'], 'payouts', 'memo', ['Alpha', 'Bravo', 'Charlie']],
    'payouts by amount, ascending' => ['payouts.index', ['sort' => 'total', 'direction' => 'asc'], 'payouts', 'memo', ['Alpha', 'Charlie', 'Bravo']],

    'imports by name' => ['data-imports.index', ['sort' => 'name', 'direction' => 'asc'], 'imports', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'imports by total' => ['data-imports.index', ['sort' => 'total', 'direction' => 'desc'], 'imports', 'name', ['Bravo', 'Charlie', 'Alpha']],
    'imports by transaction count, ascending' => ['data-imports.index', ['sort' => 'transactions_count', 'direction' => 'asc'], 'imports', 'name', ['Bravo', 'Charlie', 'Alpha']],
]);

test('a sort the list does not offer leaves it in its default order', function (string $route, array $filter, string $prop, string $field, array $default, array $sorting) {
    $this->actingAs(sortingScenario())
        ->get(route($route, [...$filter, ...$sorting]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("{$prop}.data", fn ($rows) => sortedLabels($rows, $field) === $default)
            ->where('filters.sort', null)
            ->where('filters.direction', null));

    expect(Release::query()->withoutGlobalScopes()->count())->toBe(3);
})->with([
    'releases' => ['releases.index', [], 'releases', 'name', ['Bravo', 'Alpha', 'Charlie']],
    'tracks' => ['tracks.index', [], 'tracks', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'artists' => ['artists.index', [], 'artists', 'name', ['Alpha', 'Beta', 'Charlie']],
    'items' => ['items.index', ['search' => 'Item'], 'items', 'name', ['Charlie', 'Alpha', 'Bravo']],
    'contracts' => ['contracts.index', [], 'contracts', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'payees' => ['payees.index', [], 'payees', 'name', ['Alpha', 'Bravo', 'Charlie']],
    'transactions' => ['transactions.index', [], 'transactions', 'memo', ['Charlie', 'Bravo', 'Alpha']],
    'statements' => ['statements.index', [], 'statements', 'name', ['Charlie', 'Bravo', 'Alpha']],
    'payouts' => ['payouts.index', [], 'payouts', 'memo', ['Bravo', 'Charlie', 'Alpha']],
    'imports' => ['data-imports.index', [], 'imports', 'name', ['Charlie', 'Bravo', 'Alpha']],
])->with([
    'no sort' => [[]],
    'an unknown column' => [['sort' => 'label_id', 'direction' => 'desc']],
    'an invalid direction' => [['sort' => 'name', 'direction' => 'sideways']],
    'SQL in the column' => [['sort' => 'name;drop table releases', 'direction' => 'desc']],
    'SQL in the direction' => [['sort' => 'name', 'direction' => 'desc, (select 1)']],
    'a list of columns' => [['sort' => ['name'], 'direction' => 'desc']],
]);

test('sorting leaves the transaction totals as they are', function () {
    $this->actingAs(sortingScenario())
        ->get(route('transactions.index', ['sort' => 'converted_total', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.transactions', 3)
            ->where('summary.total', '330.000000000000000'));
});

test('a sorted and filtered list keeps its order on the next page', function () {
    $label = Label::factory()->create();
    Artist::factory()->create(['label_id' => $label->id, 'name' => 'Sample Name']);

    foreach (range(1, 60) as $number) {
        Artist::factory()->create(['label_id' => $label->id, 'name' => sprintf('Example Artist %02d', $number)]);
    }

    $query = ['search' => 'Example', 'sort' => 'name', 'direction' => 'desc'];
    $admin = labelAdmin($label);

    $this->actingAs($admin)
        ->get(route('artists.index', $query))
        ->assertInertia(fn (Assert $page) => $page
            ->has('artists.data', 50)
            ->where('artists.data.0.name', 'Example Artist 60')
            ->where('artists.next_page_url', fn (string $url) => str_contains($url, 'sort=name') && str_contains($url, 'direction=desc') && str_contains($url, 'search=Example')));

    $this->actingAs($admin)
        ->get(route('artists.index', [...$query, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('artists.data', 10)
            ->where('artists.data.0.name', 'Example Artist 10')
            ->where('artists.data.9.name', 'Example Artist 01')
            ->where('filters', [...$query, 'aliases' => false]));
});

test('rows that tie on the sorted column keep a fixed order', function () {
    $label = Label::factory()->create();
    $names = ['Example C', 'Example A', 'Example B'];

    foreach ($names as $name) {
        Payee::factory()->create(['label_id' => $label->id, 'name' => $name, 'email' => 'same@example.com']);
    }

    $admin = labelAdmin($label);

    foreach (['asc', 'desc'] as $direction) {
        $this->actingAs($admin)
            ->get(route('payees.index', ['sort' => 'email', 'direction' => $direction]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payees.data', fn ($rows) => collect($rows)->pluck('name')->all() === $names));
    }
});
