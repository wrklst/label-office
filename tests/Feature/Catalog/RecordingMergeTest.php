<?php

use App\Models\Agreement;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Item;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Catalog\RecordingMerge;

test('tracks with one ISRC become one track on all their releases, and no transaction changes its contract', function () {
    $label = Label::factory()->create();
    $remixDeal = Contract::factory()->create(['label_id' => $label->id]);
    $singleDeal = Contract::factory()->create(['label_id' => $label->id]);
    $album = Release::factory()->create(['label_id' => $label->id]);
    $single = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $singleDeal->id]);
    $onAlbum = Track::factory()->create(['label_id' => $label->id, 'release_id' => $album->id, 'name' => 'Broken - Remix', 'isrc' => 'ZZTST2600001', 'track_number' => 7, 'contract_id' => $remixDeal->id]);
    $onSingle = Track::factory()->create(['label_id' => $label->id, 'release_id' => $single->id, 'name' => 'Broken (Remix)', 'isrc' => 'ZZTST2600001', 'track_number' => 1, 'contract_id' => null, 'duration' => 251]);
    $other = Track::factory()->create(['label_id' => $label->id, 'release_id' => $album->id, 'isrc' => 'ZZTST2600002']);
    $item = Item::factory()->create(['label_id' => $label->id, 'release_id' => $single->id, 'track_id' => $onSingle->id, 'contract_id' => null]);
    $sale = Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $single->id, 'track_id' => $onSingle->id, 'item_id' => $item->id, 'contract_id' => null]);

    $lines = RecordingMerge::run();

    $track = Track::query()->withoutGlobalScope('label')->where('isrc', 'ZZTST2600001')->sole();

    expect($lines)->toHaveCount(1)
        ->and($track->id)->toBe($onAlbum->id)
        ->and($track->aliases)->toBe(['Broken (Remix)'])
        ->and($track->duration)->toBe(251)
        ->and($sale->refresh()->track_id)->toBe($track->id)
        ->and($sale->release_id)->toBe($single->id)
        // The single paid by its release's contract and still does.
        ->and($item->refresh()->contract_id)->toBe($singleDeal->id)
        ->and($album->tracks()->withoutGlobalScope('label')->get()->firstWhere('id', $track->id)->track_number)->toBe(7)
        ->and($single->tracks()->withoutGlobalScope('label')->sole()->track_number)->toBe(1)
        ->and(Track::query()->withoutGlobalScope('label')->whereKey($other->id)->exists())->toBeTrue();
});

test('a new row with a known ISRC puts that track on the release, and removing the row takes it off again', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $album = Release::factory()->create(['label_id' => $label->id]);
    $single = Release::factory()->create(['label_id' => $label->id, 'name' => 'Single']);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $album->id, 'name' => 'Broken - Remix', 'isrc' => 'ZZTST2600001', 'track_number' => 7]);

    $this->actingAs($admin)->put(route('releases.update', $single), ['name' => 'Single', 'tracks' => [['name' => 'Broken (Remix)', 'isrc' => 'ZZ-TST-26-00001', 'track_number' => 1]]])
        ->assertSessionHasNoErrors();

    expect(Track::query()->count())->toBe(1)
        ->and($track->refresh()->name)->toBe('Broken - Remix')
        ->and($track->aliases)->toBe(['Broken (Remix)'])
        ->and($track->track_number)->toBe(7)
        ->and($single->tracks()->sole()->track_number)->toBe(1);

    $this->actingAs($admin)->put(route('releases.update', $single), ['name' => 'Single', 'tracks' => []])->assertSessionHasNoErrors();

    expect($single->tracks()->count())->toBe(0)
        ->and($album->tracks()->sole()->id)->toBe($track->id);
});

test('a track cannot take the ISRC of another track', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'isrc' => 'ZZTST2600001']);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'isrc' => 'ZZTST2600002']);

    $this->actingAs(labelAdmin($label))->put(route('tracks.update', $track), ['name' => $track->name, 'isrc' => 'ZZTST2600001'])
        ->assertSessionHasErrors('isrc');
});

test('a track that is also on another release stays when its first release is deleted', function () {
    $label = Label::factory()->create();
    $album = Release::factory()->create(['label_id' => $label->id]);
    $single = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $album->id]);
    $track->putOn($single->id, 3);

    $album->delete();

    expect($track->refresh()->release_id)->toBe($single->id)
        ->and($track->track_number)->toBe(3);
});

test('choosing a payee offers the releases their contracts pay for, with those tracks ticked', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id]);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Remixes']);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Unrelated']);
    $remix = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $contract->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);

    $this->actingAs($admin)->getJson(route('options', ['records' => 'releases', 'payee_id' => $payee->id]))
        ->assertJsonPath('data.0.name', 'Remixes')->assertJsonPath('data.0.related', true)->assertJsonPath('data.1.related', false);

    $tracks = $this->actingAs($admin)->getJson(route('agreements.release', ['release' => $release, 'payee_id' => $payee->id]))->json('tracks');

    expect(collect($tracks)->where('chosen', true)->pluck('id')->all())->toBe([$remix->id]);

    // A single whose contract is on the release itself: its track comes ticked too.
    $single = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $contract->id]);
    $only = Track::factory()->create(['label_id' => $label->id, 'release_id' => $single->id, 'contract_id' => null]);

    expect($this->actingAs($admin)->getJson(route('agreements.release', ['release' => $single, 'contract_id' => $contract->id]))->json('tracks.0'))
        ->toMatchArray(['id' => $only->id, 'chosen' => true]);
});

test('an agreement is listed on the pages of its contract, payee, release and track', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id]);
    $ticked = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $notTicked = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $agreement = Agreement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'contract_id' => $contract->id, 'name' => 'Remix deal']);
    $agreement->cover([$release->id], [$ticked->id]);

    foreach ([route('contracts.show', $contract), route('payees.show', $payee), route('releases.show', $release), route('tracks.show', $ticked)] as $page) {
        $this->actingAs($admin)->get($page)->assertInertia(fn ($inertia) => $inertia->where('agreements.0.name', 'Remix deal'));
    }

    $this->actingAs($admin)->get(route('tracks.show', $notTicked))->assertInertia(fn ($inertia) => $inertia->where('agreements', []));
});
