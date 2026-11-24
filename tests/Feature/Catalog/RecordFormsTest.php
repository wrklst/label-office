<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Territory;
use App\Models\Track;
use App\Models\Transaction;
use Inertia\Testing\AssertableInertia as Assert;

test('the track page, the tracks list and the release page send what the track dialog edits, the list without its long texts', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    $guest = Artist::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'artist_id' => $artist->id, 'bpm' => '120.50', 'credits' => ['composers' => ['Example Composer']]]);
    $track->artists()->attach($guest->id, ['role' => 'featuring', 'position' => 0]);
    $admin = labelAdmin($label);

    $listed = fn (Assert $row) => $row
        ->where('id', $track->id)
        ->where('bpm', '120.5')
        ->where('artists', [['artist_id' => $guest->id, 'role' => 'featuring']])
        ->has('disc_number')->has('musical_key')->has('preview_start')->has('ai_recording')->has('p_line')->etc();
    $editable = fn (Assert $row) => $listed($row->where('credits', ['composers' => ['Example Composer']])->has('lyrics'));

    $this->actingAs($admin)->get(route('tracks.show', $track->id))
        ->assertInertia(fn (Assert $page) => $page->has('track', $editable)->missing('artistOptions')->missing('contractOptions'));
    $this->actingAs($admin)->get(route('tracks.index'))
        ->assertInertia(fn (Assert $page) => $page->has('tracks.data.0', $listed)->missing('tracks.data.0.credits')->missing('artistOptions')->missing('releaseOptions'));
    $this->actingAs($admin)->get(route('releases.show', $release->id))
        ->assertInertia(fn (Assert $page) => $page->has('release.tracks.0', $editable));
});

test('the track dialog and the track rows of the release form are validated by the same rules', function (array $invalid, string $field) {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $foreign = Artist::factory()->create(['label_id' => Label::factory()]);
    $admin = labelAdmin($label);
    $invalid = array_map(fn (mixed $value): mixed => $value === 'foreign' ? $foreign->id : $value, $invalid);

    $this->actingAs($admin)->put(route('tracks.update', $track->id), ['name' => 'Example Track', ...$invalid])
        ->assertSessionHasErrors($field);
    $this->actingAs($admin)->put(route('releases.update', $release->id), ['name' => $release->name, 'tracks' => [['id' => $track->id, 'name' => 'Example Track', ...$invalid]]])
        ->assertSessionHasErrors("tracks.0.{$field}");
})->with([
    'no title' => [['name' => ''], 'name'],
    'an ISRC that is none' => [['isrc' => 'not-an-isrc'], 'isrc'],
    'a track number of zero' => [['track_number' => 0], 'track_number'],
    'a negative length' => [['duration' => -1], 'duration'],
    'an artist of another label' => [['artist_id' => 'foreign'], 'artist_id'],
    'a version that is too long' => [['version' => str_repeat('x', 256)], 'version'],
]);

test('a release page and its form return to the page they were opened from', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('releases.edit', ['release' => $release->id, 'return_to' => '/releases?search=ex']))
        ->assertInertia(fn (Assert $page) => $page->where('returnTo', '/releases?search=ex'));
    $this->actingAs($admin)->get(route('releases.create', ['return_to' => 'https://evil.example']))
        ->assertInertia(fn (Assert $page) => $page->where('returnTo', null));

    $this->actingAs($admin)->put(route('releases.update', $release->id), ['name' => 'Example Release', 'return_to' => '/releases?search=ex'])
        ->assertRedirect('/releases?search=ex');
    $this->actingAs($admin)->put(route('releases.update', $release->id), ['name' => 'Example Release', 'return_to' => '//evil.example'])
        ->assertRedirect(route('releases.show', $release->id));
    $this->actingAs($admin)->post(route('releases.store'), ['name' => 'Another Release'])
        ->assertRedirect(route('releases.show', Release::query()->where('name', 'Another Release')->sole()->id));
});

test('a delete the server would refuse is sent with its reason', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $free = Payee::factory()->create(['label_id' => $label->id]);
    $busy = Payee::factory()->create(['label_id' => $label->id]);
    Transaction::factory()->create(['label_id' => $label->id, 'type' => 'transfer', 'payee_id' => $busy->id, 'total' => '-5']);

    $this->actingAs($admin)->get(route('payees.show', $free->id))->assertInertia(fn (Assert $page) => $page->where('payee.delete_block', null));
    $this->actingAs($admin)->get(route('payees.show', $busy->id))
        ->assertInertia(fn (Assert $page) => $page->where('payee.delete_block', 'This payee has transfers, payouts or statements and cannot be deleted.'));
    $this->actingAs($admin)->delete(route('payees.destroy', $busy->id));
    $this->assertModelExists($busy);

    $open = Period::factory()->create(['label_id' => $label->id]);
    $paid = Period::factory()->create(['label_id' => $label->id]);
    Payout::factory()->create(['label_id' => $label->id, 'period_id' => $paid->id, 'payee_id' => $free->id]);
    $published = Period::factory()->create(['label_id' => $label->id]);
    Statement::factory()->create(['label_id' => $label->id, 'period_id' => $published->id, 'published_at' => now()]);

    $this->actingAs($admin)->get(route('periods.show', $open->id))->assertInertia(fn (Assert $page) => $page->where('period.delete_block', null));
    foreach ([$paid, $published] as $period) {
        $this->actingAs($admin)->get(route('periods.show', $period->id))
            ->assertInertia(fn (Assert $page) => $page->where('period.delete_block', 'This period has payouts or published statements and cannot be deleted.'));
    }

    $unused = Territory::factory()->create(['label_id' => $label->id, 'name' => 'Unused']);
    $used = Territory::factory()->create(['label_id' => $label->id, 'name' => 'Used']);
    ContractSplit::factory()->create(['contract_id' => Contract::factory()->create(['label_id' => $label->id]), 'payee_id' => $free->id, 'territory_id' => $used->id]);

    $this->actingAs($admin)->get(route('label-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('territories.0.delete_block', null)
            ->where('territories.1.delete_block', 'This territory is used by contract splits. Change those splits first.'));
});
