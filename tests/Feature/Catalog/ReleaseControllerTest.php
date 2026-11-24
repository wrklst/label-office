<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\Item;
use App\Models\Label;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use Inertia\Testing\AssertableInertia as Assert;

test('searching the release list matches name, catalog number and UPC', function (string $search) {
    $label = Label::factory()->create();
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'catalog_number' => 'EX001', 'upc' => '4000000000017']);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Sample Album', 'catalog_number' => 'SMP900', 'upc' => '4999999999994']);

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.index', ['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('releases.data', 1)
            ->where('releases.data.0.name', 'Example Release'));
})->with([
    'part of the name' => 'xample Rel',
    'catalog number' => 'EX001',
    'part of the UPC' => '40000000',
]);

test('the release list can be narrowed to one artist', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'artist_id' => $artist->id]);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Sample Album']);

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.index', ['artist' => $artist->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('releases.data', 1)
            ->where('releases.data.0.name', 'Example Release')
            ->where('releases.data.0.artist', 'Example Artist')
            ->where('filters.artist', $artist->id));
});

test('the release list can be narrowed to releases with a track without a contract, which a release without tracks is not', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $covered = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $covered->id, 'contract_id' => $contract->id]);
    $partly = Release::factory()->create(['label_id' => $label->id, 'name' => 'Partly Covered']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $partly->id, 'contract_id' => $contract->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $partly->id]);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Sample Album']);

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.index', ['without_contract' => 1, 'sort' => 'name', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('releases.data', 1)
            ->where('releases.data.0.name', 'Partly Covered')
            ->where('releases.data.0.mixed', true)
            ->where('filters.without_contract', true));
});

test('the release list sums income only and puts releases without a catalog number last', function () {
    $label = Label::factory()->create();
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Uncatalogued', 'catalog_number' => null]);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'catalog_number' => 'EX001', 'released_on' => '2026-02-06']);
    Track::factory()->count(2)->create(['label_id' => $label->id, 'release_id' => $release->id]);
    Transaction::factory()->count(2)->create(['label_id' => $label->id, 'release_id' => $release->id, 'total' => '12.50']);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'type' => 'expense', 'total' => '-40']);

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('releases/index')
            ->has('releases.data', 2)
            ->where('releases.data.0.name', 'Example Release')
            ->where('releases.data.0.released_on', '2026-02-06')
            ->where('releases.data.0.tracks_count', 2)
            ->where('releases.data.0.income', fn (string $income) => bccomp($income, '25', 4) === 0)
            ->where('releases.data.1.name', 'Uncatalogued')
            ->where('releases.data.1.income', '0'));
});

test('a release is created with its tracks and normalised identifiers', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    $trackContract = Contract::factory()->create(['label_id' => $label->id]);

    $response = $this->actingAs(labelAdmin($label))->post(route('releases.store'), [
        'name' => 'Example Release',
        'artist_id' => $artist->id,
        'catalog_number' => 'EX001',
        'upc' => '4-000000-000017',
        'released_on' => '2026-02-06',
        'accounts_mechanicals' => true,
        'mechanicals_rate' => '9.1',
        'reserves_rate' => '25',
        'notes' => 'First pressing.',
        'tracks' => [
            ['name' => 'Example Track One', 'isrc' => 'zz-tst-26-00001', 'track_number' => 1, 'duration' => 215, 'artist_id' => $artist->id],
            ['name' => 'Example Track Two', 'isrc' => 'ZZTST2600002', 'track_number' => 2, 'contract_id' => $trackContract->id],
        ],
    ]);

    $release = Release::query()->sole();
    $response->assertSessionHasNoErrors()->assertRedirect(route('releases.show', $release));

    $this->assertDatabaseHas('releases', [
        'id' => $release->id,
        'label_id' => $label->id,
        'name' => 'Example Release',
        'artist_id' => $artist->id,
        'catalog_number' => 'EX001',
        'upc' => '4000000000017',
        'accounts_mechanicals' => true,
        'notes' => 'First pressing.',
    ]);
    expect($release->released_on->toDateString())->toBe('2026-02-06');
    $this->assertDatabaseCount('tracks', 2);
    $this->assertDatabaseHas('tracks', ['release_id' => $release->id, 'label_id' => $label->id, 'name' => 'Example Track One', 'isrc' => 'ZZTST2600001', 'track_number' => 1, 'duration' => 215, 'artist_id' => $artist->id, 'contract_id' => null]);
    $this->assertDatabaseHas('tracks', ['release_id' => $release->id, 'label_id' => $label->id, 'name' => 'Example Track Two', 'isrc' => 'ZZTST2600002', 'track_number' => 2, 'artist_id' => null, 'contract_id' => $trackContract->id]);
});

test('every created release gets an additionals item carrying its identifiers', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('releases.store'), ['name' => 'Example Release', 'catalog_number' => 'EX001', 'upc' => '4000000000017'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('items', 1);
    $this->assertDatabaseHas('items', [
        'label_id' => $label->id,
        'release_id' => Release::query()->sole()->id,
        'name' => '[Additionals]',
        'is_additionals' => true,
        'catalog_number' => 'EX001',
        'upc' => '4000000000017',
    ]);
});

test('empty selections and rates on the release form are stored as null', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('releases.store'), ['name' => 'Example Release', 'artist_id' => '', 'released_on' => '', 'mechanicals_rate' => '', 'ppd_rate' => '', 'reserves_rate' => ''])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('releases', ['name' => 'Example Release', 'artist_id' => null, 'released_on' => null, 'mechanicals_rate' => null, 'ppd_rate' => null, 'reserves_rate' => null]);
});

test('a release and each of its tracks need a name', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('releases.store'), ['name' => '', 'tracks' => [['name' => '', 'isrc' => 'ZZTST2600001']]])
        ->assertSessionHasErrors([
            'name' => 'The name field is required.',
            'tracks.0.name' => 'The track name field is required.',
        ]);

    $this->assertDatabaseCount('releases', 0);
    $this->assertDatabaseCount('tracks', 0);
});

test('a release is rejected when a value is out of range', function (array $input, string $field) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('releases.store'), ['name' => 'Example Release', ...$input])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseCount('releases', 0);
})->with([
    'reserves rate above 100' => [['reserves_rate' => '100.5'], 'reserves_rate'],
    'negative reserves rate' => [['reserves_rate' => '-1'], 'reserves_rate'],
    'negative mechanicals rate' => [['mechanicals_rate' => '-0.01'], 'mechanicals_rate'],
    'negative PPD rate' => [['ppd_rate' => '-0.01'], 'ppd_rate'],
    'release date that is no date' => [['released_on' => 'soon'], 'released_on'],
    'track number zero' => [['tracks' => [['name' => 'Example Track', 'track_number' => 0]]], 'tracks.0.track_number'],
    'track number above 999' => [['tracks' => [['name' => 'Example Track', 'track_number' => 1000]]], 'tracks.0.track_number'],
    'negative track duration' => [['tracks' => [['name' => 'Example Track', 'duration' => -1]]], 'tracks.0.duration'],
    'ISRC longer than 16 characters' => [['tracks' => [['name' => 'Example Track', 'isrc' => 'ZZ-TST-26-00001-X']]], 'tracks.0.isrc'],
]);

test('a release cannot reference records of another label', function (string $field, Closure $input) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('releases.store'), ['name' => 'Example Release', ...$input()])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseMissing('releases', ['name' => 'Example Release']);
})->with([
    'artist' => ['artist_id', fn () => ['artist_id' => Artist::factory()->create()->id]],
    'track artist' => ['tracks.0.artist_id', fn () => ['tracks' => [['name' => 'Example Track', 'artist_id' => Artist::factory()->create()->id]]]],
    'track contract' => ['tracks.0.contract_id', fn () => ['tracks' => [['name' => 'Example Track', 'contract_id' => Contract::factory()->create()->id]]]],
    'track id' => ['tracks.0.id', fn () => ['tracks' => [['name' => 'Example Track', 'id' => Track::factory()->create()->id]]]],
]);

test('the release page totals income and expenses and breaks income down by track and retailer', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'artist_id' => $artist->id]);
    $second = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track Two', 'track_number' => 2, 'contract_id' => $contract->id]);
    $first = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track One', 'track_number' => 1, 'contract_id' => $contract->id]);
    $shared = ['label_id' => $label->id, 'release_id' => $release->id];
    Transaction::factory()->create([...$shared, 'track_id' => $first->id, 'total' => '6', 'quantity' => 3, 'retailer' => 'Example Stream']);
    Transaction::factory()->create([...$shared, 'track_id' => $first->id, 'total' => '4', 'quantity' => 2, 'retailer' => 'Example Stream']);
    Transaction::factory()->create([...$shared, 'total' => '20', 'quantity' => 1, 'retailer' => 'Example Store']);
    Transaction::factory()->create([...$shared, 'type' => 'expense', 'total' => '-15', 'retailer' => 'Example Pressing Plant']);
    Transaction::factory()->create(['label_id' => $label->id, 'total' => '99']);
    $equals = fn (string $expected) => fn (string $actual) => bccomp($actual, $expected, 4) === 0;

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.show', $release->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('releases/show')
            ->where('release.name', 'Example Release')
            ->where('release.artist', 'Example Artist')
            ->where('release.contract', ['id' => $contract->id, 'name' => 'Example Deal'])
            ->where('release.mixed', false)
            ->has('release.tracks', 2)
            ->where('release.tracks.0.name', 'Example Track One')
            ->where('release.tracks.0.income', $equals('10'))
            ->where('release.tracks.0.quantity', $equals('5'))
            ->where('release.tracks.1.id', $second->id)
            ->where('release.tracks.1.income', '0')
            ->has('items.data', 1)
            ->where('items.data.0.is_additionals', true)
            ->where('totals.income', $equals('30'))
            ->where('totals.expense', $equals('-15'))
            ->where('totals.transactions', 4)
            ->where('totals.release_level_income', $equals('20'))
            ->has('report.platforms', 2)
            ->where('report.platforms.0.name', 'Example Store')
            ->where('report.platforms.0.total', 20)
            ->where('report.platforms.1.name', 'Example Stream')
            ->where('report.platforms.1.total', 10)
            ->where('report.platforms.1.other_units', 5)
            ->where('movers.rows.0.id', $first->id)
            ->where('movers.rows.0.total', 10));
});

test('the release page says for each item where it is booked, how often, how much and by which source', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track']);
    $item = Item::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'track_id' => $track->id, 'name' => 'example track', 'is_additionals' => false]);
    $booking = fn (array $attributes): Transaction => Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'item_id' => $item->id, 'type' => 'income', ...$attributes]);
    $booking(['importer_source' => 'example-store', 'converted_total' => '3']);
    $booking(['importer_source' => 'example-store', 'converted_total' => '2']);
    $booking(['importer_source' => 'another-store', 'converted_total' => '1.5']);
    Transaction::factory()->create(['label_id' => $label->id, 'converted_total' => '50']);

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.show', $release->id))
        ->assertInertia(fn (Assert $page) => $page->where('items.data', fn ($items) => collect($items)->contains(fn (array $row): bool => $row['name'] === 'example track'
            && $row['track'] === 'Example Track' && $row['transactions'] === 3 && (float) $row['total'] === 6.5 && $row['sources'] === ['another-store', 'example-store'])
            && collect($items)->where('name', '!=', 'example track')->every(fn (array $row): bool => $row['transactions'] === 0 && $row['sources'] === [])));
});

test('the release form lists the tracks in track order', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id, 'released_on' => '2026-02-06']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track Two', 'track_number' => 2]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track One', 'track_number' => 1]);

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.edit', $release->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('releases/form')
            ->where('release.id', $release->id)
            ->where('release.released_on', '2026-02-06')
            ->where('release.tracks.0.name', 'Example Track One')
            ->where('release.tracks.1.name', 'Example Track Two'));
});

test('updating a release changes existing tracks, adds new ones and removes the missing ones', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'upc' => '4000000000017']);
    $kept = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track One', 'isrc' => 'ZZTST2600001']);
    $removed = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track Two']);

    $this->actingAs(labelAdmin($label))
        ->put(route('releases.update', $release->id), [
            'name' => 'Example Release (Deluxe)',
            'upc' => '4 000000 000024',
            'tracks' => [
                ['id' => $kept->id, 'name' => 'Example Track One (Remaster)', 'isrc' => 'zz-tst-26-00009', 'track_number' => 1],
                ['name' => 'Example Bonus Track', 'isrc' => 'ZZTST2600003', 'track_number' => 2],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('releases.show', $release));

    $this->assertDatabaseHas('releases', ['id' => $release->id, 'name' => 'Example Release (Deluxe)', 'upc' => '4000000000024']);
    $this->assertDatabaseCount('tracks', 2);
    $this->assertDatabaseHas('tracks', ['id' => $kept->id, 'name' => 'Example Track One (Remaster)', 'isrc' => 'ZZTST2600009', 'track_number' => 1]);
    $this->assertDatabaseHas('tracks', ['release_id' => $release->id, 'label_id' => $label->id, 'name' => 'Example Bonus Track', 'isrc' => 'ZZTST2600003', 'track_number' => 2]);
    $this->assertModelMissing($removed);
    $this->assertDatabaseCount('items', 1);
});

test('updating a release without a track list leaves its tracks alone', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track']);

    $this->actingAs(labelAdmin($label))
        ->put(route('releases.update', $release->id), ['name' => 'Renamed Release'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('releases', ['id' => $release->id, 'name' => 'Renamed Release']);
    $this->assertDatabaseHas('tracks', ['id' => $track->id, 'name' => 'Example Track']);
});

test('updating a release with an empty track list removes all its tracks', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $otherRelease = Release::factory()->create(['label_id' => $label->id]);
    Track::factory()->count(2)->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $untouched = Track::factory()->create(['label_id' => $label->id, 'release_id' => $otherRelease->id]);

    $this->actingAs(labelAdmin($label))
        ->put(route('releases.update', $release->id), ['name' => $release->name, 'tracks' => []])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseCount('tracks', 1);
    $this->assertModelExists($untouched);
});

test('deleting a release removes its tracks and items but keeps its transactions unassigned', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $transaction = Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'track_id' => $track->id, 'total' => '10']);

    $this->actingAs(labelAdmin($label))
        ->delete(route('releases.destroy', $release->id))
        ->assertRedirect(route('releases.index'));

    $this->assertModelMissing($release);
    $this->assertModelMissing($track);
    $this->assertDatabaseCount('items', 0);
    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'release_id' => null, 'track_id' => null, 'item_id' => null]);
});

test('a contract is set on the tracks of a release that have none, and the others keep theirs', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $own = Contract::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id]);
    $bare = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $owned = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $own->id]);
    $elsewhere = Track::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->put(route('releases.contract', $release->id), ['contract_id' => $contract->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Contract set on 1 track. 1 track kept its own.');

    expect($bare->refresh()->contract_id)->toBe($contract->id)
        ->and($owned->refresh()->contract_id)->toBe($own->id)
        ->and($elsewhere->refresh()->contract_id)->toBeNull();
});

test('a contract set on the tracks of a release can replace the contracts they have, or clear them', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $own = Contract::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id]);
    $bare = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $owned = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $own->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->put(route('releases.contract', $release->id), ['contract_id' => $contract->id, 'replace' => '1'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Contract set on 2 tracks.');

    expect($bare->refresh()->contract_id)->toBe($contract->id)
        ->and($owned->refresh()->contract_id)->toBe($contract->id);

    $this->actingAs($admin)->put(route('releases.contract', $release->id), ['contract_id' => '', 'replace' => '1'])->assertSessionHasNoErrors();

    expect($bare->refresh()->contract_id)->toBeNull()
        ->and($owned->refresh()->contract_id)->toBeNull();
});

test('the contract of another label cannot be set on the tracks of a release', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $foreign = Contract::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->put(route('releases.contract', $release->id), ['contract_id' => $foreign->id, 'replace' => '1'])
        ->assertSessionHasErrors('contract_id');

    expect($track->refresh()->contract_id)->toBeNull();
});

test('a release of another label cannot be given a contract on its tracks', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $foreign = Release::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))->put(route('releases.contract', $foreign->id), ['contract_id' => $contract->id])->assertNotFound();
});
