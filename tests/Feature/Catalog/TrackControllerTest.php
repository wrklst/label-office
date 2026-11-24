<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\Label;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use Inertia\Testing\AssertableInertia as Assert;

test('searching the track list matches the name or the ISRC with or without dashes', function (string $search) {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track', 'isrc' => 'ZZTST2600001']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Sample Song', 'isrc' => 'ZZTST2600002']);

    $this->actingAs(labelAdmin($label))
        ->get(route('tracks.index', ['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('tracks/index')
            ->has('tracks.data', 1)
            ->where('tracks.data.0.name', 'Example Track'));
})->with([
    'part of the name' => 'xample',
    'plain ISRC' => 'ZZTST2600001',
    'ISRC written with dashes' => 'ZZ-TST-26-00001',
]);

test('the track list can be narrowed to one release', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'catalog_number' => 'EX001']);
    $otherRelease = Release::factory()->create(['label_id' => $label->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $otherRelease->id, 'name' => 'Sample Song']);

    $this->actingAs(labelAdmin($label))
        ->get(route('tracks.index', ['release' => $release->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tracks.data', 1)
            ->where('tracks.data.0.name', 'Example Track')
            ->where('tracks.data.0.release', ['id' => $release->id, 'name' => 'Example Release', 'catalog_number' => 'EX001'])
            ->where('filters.release', $release->id));
});

test('the track list shows each track with its own contract only, as a release has none', function () {
    $label = Label::factory()->create();
    $trackContract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Remix Deal']);
    $release = Release::factory()->create(['label_id' => $label->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'A Example Track']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'B Example Remix', 'contract_id' => $trackContract->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('tracks.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tracks.data.0.contract', null)
            ->missing('tracks.data.0.inherited_contract')
            ->where('tracks.data.1.contract', ['id' => $trackContract->id, 'name' => 'Example Remix Deal']));
});

test('a track is updated and its ISRC normalised', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track']);

    $this->actingAs(labelAdmin($label))
        ->from(route('tracks.index'))
        ->put(route('tracks.update', $track->id), [
            'name' => 'Example Track (Edit)',
            'artist_id' => $artist->id,
            'contract_id' => $contract->id,
            'isrc' => 'zz-tst-26-00007',
            'iswc' => 'T-000.000.001-0',
            'track_number' => 4,
            'duration' => 301,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tracks.index'));

    $this->assertDatabaseHas('tracks', [
        'id' => $track->id,
        'release_id' => $release->id,
        'name' => 'Example Track (Edit)',
        'artist_id' => $artist->id,
        'contract_id' => $contract->id,
        'isrc' => 'ZZTST2600007',
        'iswc' => 'T-000.000.001-0',
        'track_number' => 4,
        'duration' => 301,
    ]);
});

test('empty selections on the track form are stored as null', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create([
        'label_id' => $label->id,
        'release_id' => $release->id,
        'artist_id' => Artist::factory()->create(['label_id' => $label->id]),
        'contract_id' => Contract::factory()->create(['label_id' => $label->id]),
        'track_number' => 3,
    ]);

    $this->actingAs(labelAdmin($label))
        ->put(route('tracks.update', $track->id), ['name' => 'Example Track', 'artist_id' => '', 'contract_id' => '', 'track_number' => '', 'duration' => ''])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('tracks', ['id' => $track->id, 'artist_id' => null, 'contract_id' => null, 'track_number' => null, 'duration' => null]);
});

test('a track update is rejected when a value is invalid', function (array $input, string $field) {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track']);

    $this->actingAs(labelAdmin($label))
        ->put(route('tracks.update', $track->id), ['name' => 'Changed Track', ...$input])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseHas('tracks', ['id' => $track->id, 'name' => 'Example Track']);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'track number zero' => [['track_number' => 0], 'track_number'],
    'track number above 999' => [['track_number' => 1000], 'track_number'],
    'negative duration' => [['duration' => -1], 'duration'],
    'negative mechanicals rate' => [['mechanicals_rate' => '-1'], 'mechanicals_rate'],
    'negative PPD rate' => [['ppd_rate' => '-1'], 'ppd_rate'],
    'ISRC longer than 16 characters' => [['isrc' => 'ZZ-TST-26-00001-X'], 'isrc'],
    'AI declaration that is none of the three' => [['ai_recording' => 'some'], 'ai_recording'],
]);

test('a track cannot reference records of another label', function (string $field, string $model) {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $foreign = $model::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->put(route('tracks.update', $track->id), ['name' => $track->name, $field => $foreign->id])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseHas('tracks', ['id' => $track->id, $field => null]);
})->with([
    'artist' => ['artist_id', Artist::class],
    'contract' => ['contract_id', Contract::class],
]);

test('deleting a track keeps its transactions on the release', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $transaction = Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'track_id' => $track->id]);

    $this->actingAs(labelAdmin($label))
        ->from(route('tracks.index'))
        ->delete(route('tracks.destroy', $track->id))
        ->assertRedirect(route('tracks.index'));

    $this->assertModelMissing($track);
    $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'release_id' => $release->id, 'track_id' => null]);
});

test('the track list leaves out lyrics and credits, and the dialog fetches them for one track of this label', function () {
    $label = Label::factory()->create();
    $track = Track::factory()->create(['label_id' => $label->id, 'name' => 'Example Track', 'lyrics' => 'Example lyrics', 'credits' => ['composers' => ['Example Writer']]]);
    $foreign = Track::factory()->create(['label_id' => Label::factory(), 'lyrics' => 'Secret']);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('tracks.index'))->assertInertia(fn (Assert $page) => $page
        ->where('tracks.data.0.name', 'Example Track')->missing('tracks.data.0.lyrics')->missing('tracks.data.0.credits'));

    $this->actingAs($admin)->getJson(route('tracks.fields', $track->id))->assertOk()
        ->assertJson(['lyrics' => 'Example lyrics', 'credits' => ['composers' => ['Example Writer']]]);
    $this->actingAs($admin)->getJson(route('tracks.fields', $foreign->id))->assertNotFound();
});
