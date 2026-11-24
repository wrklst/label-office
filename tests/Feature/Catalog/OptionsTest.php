<?php

use App\Enums\ReleaseFileKind;
use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\Track;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('a dropdown lists thirty records of the label at a time, says whether more follow, and names the chosen one', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    Payee::factory()->count(35)->sequence(fn ($sequence) => ['name' => sprintf('Payee %02d', $sequence->index)])->create(['label_id' => $label->id]);
    $last = Payee::query()->withoutGlobalScope('label')->where('name', 'Payee 34')->sole();
    Payee::factory()->create(['name' => 'Payee 00 of another label']);

    $first = $this->actingAs($admin)->getJson(route('options', ['records' => 'payees', 'selected' => $last->id]))->assertOk();

    expect($first->json('data'))->toHaveCount(30)
        ->and($first->json('data.0.name'))->toBe('Payee 00')
        ->and($first->json('next'))->toBe(2)
        ->and($first->json('selected.name'))->toBe('Payee 34');

    $second = $this->actingAs($admin)->getJson(route('options', ['records' => 'payees', 'page' => 2]))->assertOk();

    expect($second->json('data'))->toHaveCount(5)
        ->and($second->json('next'))->toBeNull();

    $this->actingAs($admin)->getJson(route('options', ['records' => 'payees', 'selected' => Payee::query()->withoutGlobalScope('label')->where('name', 'Payee 00 of another label')->value('id')]))
        ->assertJsonPath('selected', null);
    $this->actingAs($admin)->getJson(route('options', ['records' => 'users']))->assertNotFound();
});

test('a dropdown is searched on the server, by name and by what identifies a record', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Night Drive', 'catalog_number' => 'EX042']);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Morning Walk', 'catalog_number' => 'EX001']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Headlights', 'isrc' => 'DEEX12600042']);

    $names = fn (string $records, string $search) => $this->actingAs($admin)->getJson(route('options', ['records' => $records, 'search' => $search]))->json('data.*.name');

    expect($names('releases', 'drive'))->toBe(['Night Drive'])
        ->and($names('releases', 'ex042'))->toBe(['Night Drive'])
        ->and($names('tracks', 'DEEX126'))->toBe(['Headlights'])
        ->and($names('releases', 'nothing like it'))->toBe([]);
});

test('a release, its tracks and an artist show a picture: the cover, or the artist\'s own', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $bare = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Unseen Artist']);
    $release = Release::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id, 'name' => 'Night Drive', 'catalog_number' => 'EX042']);
    $cover = ReleaseFile::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'kind' => ReleaseFileKind::Cover]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Headlights', 'isrc' => null]);
    $thumb = route('release-files.show', ['release_file' => $cover->id, 'size' => 'thumb']);
    $row = fn (string $records, string $search) => $this->actingAs($admin)->getJson(route('options', ['records' => $records, 'search' => $search]))->json('data.0');

    expect($row('releases', 'Night'))->toBe(['id' => $release->id, 'name' => 'Night Drive', 'hint' => 'EX042 · Example Artist', 'image' => $thumb])
        ->and($row('tracks', 'Head'))->toMatchArray(['hint' => 'Night Drive', 'image' => $thumb])
        ->and($row('artists', 'Example'))->toMatchArray(['image' => $thumb])
        ->and($row('artists', 'Unseen'))->toMatchArray(['image' => null]);

    $this->actingAs($admin)->post(route('artists.image.store', $artist->id), ['file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('file');
    $this->actingAs($admin)->post(route('artists.image.store', $artist->id), ['file' => UploadedFile::fake()->image('artist.png', 200, 200)])->assertOk();

    [$disk, $path] = explode(':', $artist->refresh()->image_path, 2);
    Storage::disk($disk)->assertExists($path);

    expect($row('artists', 'Example')['image'])->toStartWith(route('artists.image.show', $artist->id));
    $this->actingAs($admin)->get($row('artists', 'Example')['image'])->assertOk();
    $this->actingAs(labelAdmin(Label::factory()->create()))->get(route('artists.image.show', $artist->id))->assertNotFound();

    $this->actingAs($admin)->delete(route('artists.image.destroy', $artist->id));

    Storage::disk($disk)->assertMissing($path);
    expect($artist->refresh()->image_path)->toBeNull();
});

test('a list is narrowed to what belongs to the payee or contract chosen beside it, unless that leaves nothing', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Remix deal']);
    Contract::factory()->create(['label_id' => $label->id, 'name' => 'Other deal']);
    $unused = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Unused deal']);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id]);
    $paid = Release::factory()->create(['label_id' => $label->id, 'name' => 'Paid release', 'contract_id' => $contract->id]);
    $other = Release::factory()->create(['label_id' => $label->id, 'name' => 'Other release']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $paid->id, 'name' => 'Paid by its release', 'contract_id' => null]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $other->id, 'name' => 'Remix', 'contract_id' => $contract->id]);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $other->id, 'name' => 'Original', 'contract_id' => null]);

    $names = fn (string $records, array $params) => $this->actingAs($admin)->getJson(route('options', ['records' => $records, ...$params]))->json('data.*.name');

    expect($names('contracts', ['payee_id' => $payee->id]))->toBe(['Remix deal'])
        ->and($names('releases', ['contract_id' => $contract->id]))->toBe(['Other release', 'Paid release'])
        ->and($names('tracks', ['contract_id' => $contract->id]))->toBe(['Paid by its release', 'Remix'])
        ->and($names('tracks', ['contract_id' => $contract->id, 'release_id' => $other->id]))->toBe(['Remix'])
        ->and($names('tracks', ['release_id' => $other->id]))->toBe(['Original', 'Remix'])
        // A contract that pays for nothing yet does not empty the list it is to be set from.
        ->and($names('releases', ['contract_id' => $unused->id]))->toBe(['Other release', 'Paid release'])
        ->and($names('contracts', ['payee_id' => Payee::factory()->create(['label_id' => $label->id])->id]))->toHaveCount(3);
});

test('a record can be left out of its own dropdown, as a contract is not its own child', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $own = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Own Deal']);
    Contract::factory()->create(['label_id' => $label->id, 'name' => 'Other Deal']);

    $names = $this->actingAs($admin)->getJson(route('options', ['records' => 'contracts', 'exclude' => $own->id]))->json('data.*.name');

    expect($names)->toBe(['Other Deal']);
});

test('what is chosen already is not offered again, and when that uses up what a contract pays for the rest is offered', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $paid = Release::factory()->create(['label_id' => $label->id, 'name' => 'Paid', 'contract_id' => $contract->id]);
    $alsoPaid = Release::factory()->create(['label_id' => $label->id, 'name' => 'Also paid', 'contract_id' => $contract->id]);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Unrelated']);
    $names = fn (array $chosen) => $this->actingAs($admin)->getJson(route('options', ['records' => 'releases', 'contract_id' => $contract->id, 'exclude' => implode(',', $chosen)]))->json('data.*.name');

    expect($names([$paid->id]))->toBe(['Also paid'])
        ->and($names([$paid->id, $alsoPaid->id]))->toBe(['Unrelated']);
});
