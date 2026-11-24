<?php

use App\Enums\AirplayOrigin;
use App\Enums\ArtistRole;
use App\Models\Airplay;
use App\Models\AirplayFinding;
use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use Inertia\Testing\AssertableInertia as Assert;

test('a track page shows where the track belongs, what it earned and where it was played', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release']);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'artist_id' => $artist->id, 'name' => 'Example Track']);
    Transaction::factory()->count(2)->create([
        'label_id' => $label->id, 'release_id' => $release->id, 'track_id' => $track->id, 'type' => 'income', 'total' => '2.50', 'converted_total' => '2.50', 'retailer' => 'Example Shop',
    ]);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'track_id' => $track->id, 'type' => 'expense', 'total' => '-9', 'converted_total' => '-9']);
    $airplay = Airplay::factory()->create([
        'label_id' => $label->id, 'track_id' => $track->id, 'station' => 'Example Radio', 'origin' => AirplayOrigin::Statement, 'amount' => '1.50',
        'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
    ]);
    Airplay::factory()->create(['label_id' => $label->id]);
    AirplayFinding::factory()->create(['label_id' => $label->id, 'track_id' => $track->id]);
    AirplayFinding::factory()->create(['label_id' => $label->id, 'track_id' => $track->id, 'status' => AirplayFinding::DISMISSED]);

    $this->actingAs(labelAdmin($label))
        ->get(route('tracks.show', $track->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tracks/show')
            ->where('track.name', 'Example Track')
            ->where('track.credited_artists', [['id' => $artist->id, 'name' => 'Example Artist']])
            ->where('track.release.id', $release->id)
            ->where('income.transactions', 2)
            ->where('income.total', fn (string $total): bool => (float) $total === 5.0)
            ->where('report.platforms.0.name', 'Example Shop')
            ->has('airplays.data', 1)
            ->where('airplays.data.0.id', $airplay->id)
            ->where('airplays.data.0.origin', 'statement')
            ->where('airplays.data.0.period_end', '2025-12-31')
            ->where('openFindings', 1));
});

test('the track of another label has no page', function () {
    $track = Track::factory()->create();

    $this->actingAs(labelAdmin(Label::factory()->create()))->get(route('tracks.show', $track->id))->assertNotFound();
});

test('an artist page lists the releases and tracks credited to the artist', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $release = Release::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id, 'name' => 'Example Release']);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'artist_id' => $artist->id, 'name' => 'Example Track']);
    Airplay::factory()->create(['label_id' => $label->id, 'track_id' => $track->id]);
    Track::factory()->create(['label_id' => $label->id]);
    Release::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('artists.show', $artist->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('artists/show')
            ->where('artist', ['id' => $artist->id, 'name' => 'Example Artist', 'alias_of_id' => null, 'spotify_id' => null, 'discogs_search_name' => null, 'notes' => null, 'image_url' => null, 'alias_of' => null, 'aliases' => []])
            ->has('releases.data', 1)
            ->where('releases.data.0.id', $release->id)
            ->has('tracks.data', 1)
            ->where('tracks.data.0.id', $track->id)
            ->where('tracks.data.0.releases', [['id' => $release->id, 'name' => 'Example Release']])
            ->where('tracks.data.0.airplays_count', 1));
});

test('a recording on several releases is listed once, with all its releases and what it earned on them', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    $album = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release', 'contract_id' => Contract::factory()->create(['label_id' => $label->id])->id]);
    $single = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Single', 'contract_id' => null]);
    $entry = fn (Release $release, array $attributes = []): Track => Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'artist_id' => $artist->id, 'contract_id' => null, ...$attributes]);
    $onAlbum = $entry($album, ['name' => 'Opening', 'isrc' => 'ZZTST2600001']);
    $onSingle = $entry($single, ['name' => 'Opening', 'isrc' => 'ZZTST2600001']);
    $entry($album, ['name' => 'Second Track', 'isrc' => null]);
    $entry($single, ['name' => 'Second Track', 'isrc' => null]);
    Track::factory()->create(['isrc' => 'ZZTST2600001']);
    Transaction::factory()->create(['label_id' => $label->id, 'type' => 'income', 'track_id' => $onAlbum->id, 'converted_total' => '10']);
    Transaction::factory()->create(['label_id' => $label->id, 'type' => 'income', 'track_id' => $onSingle->id, 'converted_total' => '2.5']);
    $this->actingAs(labelAdmin($label));

    $this->get(route('artists.show', $artist->id))->assertInertia(fn (Assert $page) => $page
        ->has('tracks.data', 3)
        ->where('tracks.data.0.id', $onAlbum->id)
        ->where('tracks.data.0.releases', [['id' => $album->id, 'name' => 'Example Release'], ['id' => $single->id, 'name' => 'Example Single']])
        ->where('tracks.data.0.income', fn ($income) => (float) $income === 12.5));

    $this->get(route('tracks.index'))->assertInertia(fn (Assert $page) => $page
        ->has('tracks.data', 3)
        ->where('tracks.data.0.id', $onAlbum->id)
        ->where('tracks.data.0.releases', [['id' => $album->id, 'name' => 'Example Release'], ['id' => $single->id, 'name' => 'Example Single']])
        ->where('tracks.data.0.without_contract_on', ['Example Single'])
        ->where('tracks.data.1.without_contract_on', []));

    $this->get(route('tracks.index', ['release' => $single->id]))->assertInertia(fn (Assert $page) => $page
        ->has('tracks.data', 2)
        ->where('tracks.data.0.id', $onSingle->id)
        ->where('tracks.data.0.releases', [['id' => $single->id, 'name' => 'Example Single']]));
});

test('a release of another artist that carries a track of the artist is listed with what that track earned, and counted', function () {
    $label = Label::factory()->create();
    $remixer = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Sample Ensemble']);
    $album = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release']);
    $remix = Track::factory()->create(['label_id' => $label->id, 'release_id' => $album->id]);
    $remix->artists()->attach($remixer->id, ['role' => 'remixer', 'position' => 0]);
    $other = Track::factory()->create(['label_id' => $label->id, 'release_id' => $album->id]);
    $booking = fn (array $attributes): Transaction => Transaction::factory()->create(['label_id' => $label->id, 'type' => 'income', 'release_id' => $album->id, ...$attributes]);
    $booking(['track_id' => $remix->id, 'converted_total' => '4']);
    $booking(['track_id' => $other->id, 'converted_total' => '100']);
    $this->actingAs(labelAdmin($label));

    $this->get(route('artists.show', $remixer->id))->assertInertia(fn (Assert $page) => $page
        ->has('releases.data', 1)
        ->where('releases.data.0.id', $album->id)
        ->where('releases.data.0.income', fn ($income) => (float) $income === 4.0)
        ->where('totals.income', fn ($income) => (float) $income === 4.0));

    $this->get(route('artists.index', ['search' => 'Sample']))->assertInertia(fn (Assert $page) => $page
        ->where('artists.data.0.releases_count', 1)
        ->where('artists.data.0.tracks_count', 1));
});

test('an artist page lists the contracts of the artist\'s releases and tracks with their payees', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee']);
    $own = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Remixer deal']);
    $inherited = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist 50/50']);
    Contract::factory()->create(['label_id' => $label->id, 'name' => 'Unrelated']);
    ContractSplit::factory()->create(['contract_id' => $own->id, 'payee_id' => $payee->id, 'split_amount' => '0.5']);
    $release = Release::factory()->create(['label_id' => $label->id, 'contract_id' => $inherited->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $own->id])
        ->artists()->attach($artist->id, ['role' => 'remixer', 'position' => 0]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'artist_id' => $artist->id, 'contract_id' => null]);

    $this->actingAs(labelAdmin($label))
        ->get(route('artists.show', $artist->id))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contracts', 2)
            ->where('contracts.0.name', 'Example Artist 50/50')
            ->where('contracts.0.splits', [])
            ->where('contracts.1.name', 'Remixer deal')
            ->where('contracts.1.splits.0.payee', ['id' => $payee->id, 'name' => 'Example Payee'])
            ->where('contracts.1.splits.0.split_amount', fn ($share) => (float) $share === 0.5));
});

test('an artist page totals what the artist\'s releases and tracks earned and cost, counting each transaction once', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $release = Release::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id, 'name' => 'Example Release']);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'artist_id' => $artist->id, 'name' => 'Example Track']);
    $compilation = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Compilation']);
    $onCompilation = Track::factory()->create(['label_id' => $label->id, 'release_id' => $compilation->id, 'artist_id' => $artist->id, 'name' => 'Compilation Track']);
    $booking = fn (array $attributes): Transaction => Transaction::factory()->create(['label_id' => $label->id, 'type' => 'income', 'quantity' => 10, ...$attributes]);
    // On the artist's own release and track: counted once, although it matches both.
    $booking(['release_id' => $release->id, 'track_id' => $track->id, 'total' => '40', 'converted_total' => '40']);
    $booking(['release_id' => $release->id, 'track_id' => null, 'total' => '15', 'converted_total' => '15']);
    $booking(['release_id' => $compilation->id, 'track_id' => $onCompilation->id, 'total' => '5', 'converted_total' => '5']);
    $booking(['release_id' => $release->id, 'track_id' => null, 'type' => 'expense', 'total' => '-20', 'converted_total' => '-20', 'quantity' => null]);
    // Someone else's track on the compilation, and another label's income.
    $booking(['release_id' => $compilation->id, 'track_id' => Track::factory()->create(['label_id' => $label->id, 'release_id' => $compilation->id])->id, 'total' => '99', 'converted_total' => '99']);
    Transaction::factory()->create(['type' => 'income', 'total' => '77', 'converted_total' => '77']);
    Airplay::factory()->create(['label_id' => $label->id, 'track_id' => $track->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('artists.show', $artist->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.income', fn (string $total): bool => (float) $total === 60.0)
            ->where('totals.expense', fn (string $total): bool => (float) $total === -20.0)
            ->where('totals.net', fn (string $total): bool => (float) $total === 40.0)
            ->where('totals.units', fn (string $units): bool => (float) $units === 30.0)
            ->where('totals.transactions', 4)
            ->where('totals.airplays', 1)
            ->where('releases.data', fn ($releases) => (float) collect($releases)->firstWhere('id', $release->id)['income'] === 55.0
                && (float) collect($releases)->firstWhere('id', $compilation->id)['income'] === 5.0)
            ->where('tracks.data', fn ($tracks) => (float) collect($tracks)->firstWhere('id', $track->id)['income'] === 40.0
                && (float) collect($tracks)->firstWhere('id', $onCompilation->id)['income'] === 5.0));
});

test('the artist of another label has no page', function () {
    $artist = Artist::factory()->create();

    $this->actingAs(labelAdmin(Label::factory()->create()))->get(route('artists.show', $artist->id))->assertNotFound();
});

test('the airplay page and the transaction list can be limited to one track', function () {
    $label = Label::factory()->create();
    $track = Track::factory()->create(['label_id' => $label->id, 'name' => 'Example Track']);
    $airplay = Airplay::factory()->create(['label_id' => $label->id, 'track_id' => $track->id]);
    Airplay::factory()->create(['label_id' => $label->id]);
    $transaction = Transaction::factory()->create(['label_id' => $label->id, 'track_id' => $track->id]);
    Transaction::factory()->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('airplays.index', ['track' => $track->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('airplays.data', 1)
            ->where('airplays.data.0.id', $airplay->id)
            ->where('filters.track', $track->id));

    $this->actingAs($admin)->get(route('transactions.index', ['track' => $track->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.id', $transaction->id));
});

test('a track on several releases shows every release and combines airplay and income of the recording', function () {
    $label = Label::factory()->create();
    $first = Track::factory()->create(['label_id' => $label->id, 'release_id' => Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release']), 'isrc' => 'ZZTST2600001']);
    $second = Track::factory()->create(['label_id' => $label->id, 'release_id' => Release::factory()->create(['label_id' => $label->id, 'name' => 'Sample Compilation']), 'isrc' => 'ZZTST2600001']);
    Track::factory()->create(['isrc' => 'ZZTST2600001']);
    Transaction::factory()->create(['label_id' => $label->id, 'track_id' => $first->id, 'type' => 'income', 'total' => '2', 'converted_total' => '2']);
    Transaction::factory()->create(['label_id' => $label->id, 'track_id' => $second->id, 'type' => 'income', 'total' => '3', 'converted_total' => '3']);
    $airplay = Airplay::factory()->create(['label_id' => $label->id, 'track_id' => $second->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)
        ->get(route('tracks.show', $first->id))
        ->assertInertia(fn (Assert $page) => $page
            ->has('releases', 2)
            ->where('releases.0.name', 'Example Release')
            ->where('releases.1.name', 'Sample Compilation')
            ->where('releases.1.track_id', $second->id)
            ->where('income.transactions', 2)
            ->where('income.total', fn (string $total): bool => (float) $total === 5.0)
            ->where('airplays.data.0.id', $airplay->id));

    $this->actingAs($admin)->get(route('airplays.index', ['track' => $first->id]))
        ->assertInertia(fn (Assert $page) => $page->has('airplays.data', 1)->where('airplays.data.0.id', $airplay->id));
});

test('the track page names the main artist first and then the further credits', function () {
    $label = Label::factory()->create();
    $main = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $guest = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Guest Artist']);
    $track = Track::factory()->create(['label_id' => $label->id, 'artist_id' => $main->id]);
    $track->artists()->attach($guest, ['role' => ArtistRole::Featuring->value]);

    $this->actingAs(labelAdmin($label))
        ->get(route('tracks.show', $track->id))
        ->assertInertia(fn (Assert $page) => $page->where('track.credited_artists', [
            ['id' => $main->id, 'name' => 'Example Artist'],
            ['id' => $guest->id, 'name' => 'Guest Artist'],
        ]));
});

test('the releases count of the artists list opens a releases list with exactly that many releases', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    $own = Release::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id]);
    $viaTrack = Release::factory()->create(['label_id' => $label->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $viaTrack->id, 'artist_id' => $artist->id]);
    Release::factory()->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('artists.index'))->assertInertia(fn (Assert $page) => $page->where('artists.data.0.releases_count', 2));
    $this->actingAs($admin)->get(route('releases.index', ['artist' => $artist->id]))->assertInertia(fn (Assert $page) => $page
        ->where('releases.total', 2)->where('releases.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$own->id, $viaTrack->id])->sort()->values()->all()));
});
