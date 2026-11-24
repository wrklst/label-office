<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\Label;
use App\Models\Release;
use App\Models\Track;
use Inertia\Testing\AssertableInertia as Assert;

test('the catalog manager lists every release with its tracks and their contracts', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $trackContract = Contract::factory()->create(['label_id' => $label->id]);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Uncatalogued', 'catalog_number' => null]);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'catalog_number' => 'EX001', 'artist_id' => $artist->id, 'contract_id' => $contract->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track Two', 'track_number' => 2, 'contract_id' => $trackContract->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track One', 'track_number' => 1, 'isrc' => 'ZZTST2600001', 'artist_id' => $artist->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/manager')
            ->has('releases.data', 2)
            ->where('releases.data.0.name', 'Example Release')
            ->where('releases.data.0.artist', 'Example Artist')
            ->where('releases.data.0.contract_id', $contract->id)
            ->has('releases.data.0.tracks', 2)
            ->where('releases.data.0.tracks.0.name', 'Example Track One')
            ->where('releases.data.0.tracks.0.isrc', 'ZZTST2600001')
            ->where('releases.data.0.tracks.0.artist', 'Example Artist')
            ->where('releases.data.0.tracks.0.contract_id', null)
            ->where('releases.data.0.tracks.1.name', 'Example Track Two')
            ->where('releases.data.0.tracks.1.contract_id', $trackContract->id)
            ->where('releases.data.1.name', 'Uncatalogued')
            ->has('releases.data.1.tracks', 0)
            ->missing('contractOptions'));
});

test('a contract is assigned to a release', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->from(route('catalog.index'))
        ->patch(route('catalog.assign'), ['type' => 'release', 'id' => $release->id, 'contract_id' => $contract->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('catalog.index'));

    $this->assertDatabaseHas('releases', ['id' => $release->id, 'contract_id' => $contract->id]);
    $this->assertDatabaseHas('tracks', ['id' => $track->id, 'contract_id' => null]);
});

test('a contract is assigned to a track', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->from(route('catalog.index'))
        ->patch(route('catalog.assign'), ['type' => 'track', 'id' => $track->id, 'contract_id' => $contract->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('catalog.index'));

    $this->assertDatabaseHas('tracks', ['id' => $track->id, 'contract_id' => $contract->id]);
    $this->assertDatabaseHas('releases', ['id' => $release->id, 'contract_id' => null]);
});

test('assigning no contract clears the current one', function (array $input) {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $contract->id]);

    $this->actingAs(labelAdmin($label))
        ->patch(route('catalog.assign'), ['type' => 'release', 'id' => $release->id, ...$input])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('releases', ['id' => $release->id, 'contract_id' => null]);
})->with([
    'null contract' => [['contract_id' => null]],
    'empty contract' => [['contract_id' => '']],
    'contract left out' => [[]],
]);

test('a contract of another label cannot be assigned in the catalog manager', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $foreign = Contract::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->patch(route('catalog.assign'), ['type' => 'release', 'id' => $release->id, 'contract_id' => $foreign->id])
        ->assertSessionHasErrors('contract_id');

    $this->assertDatabaseHas('releases', ['id' => $release->id, 'contract_id' => null]);
});

test('a release or track of another label cannot be given a contract', function (string $type, string $model, string $table) {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $foreign = $model::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->patch(route('catalog.assign'), ['type' => $type, 'id' => $foreign->id, 'contract_id' => $contract->id])
        ->assertNotFound();

    $this->assertDatabaseHas($table, ['id' => $foreign->id, 'contract_id' => null]);
})->with([
    'release' => ['release', Release::class, 'releases'],
    'track' => ['track', Track::class, 'tracks'],
]);

test('a catalog assignment needs a known type and an id', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->patch(route('catalog.assign'), ['type' => 'item', 'id' => ''])
        ->assertSessionHasErrors(['type', 'id']);
});
