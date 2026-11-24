<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Territory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the label settings page shows the stored settings and territories', function () {
    $label = Label::factory()->create([
        'name' => 'Example Records', 'currency' => 'EUR', 'contact_email' => 'office@example.com',
        'accounts_mechanicals' => true, 'accounts_ppd' => false, 'accounts_reserves' => true,
    ]);
    $second = Territory::factory()->create(['label_id' => $label->id, 'name' => 'Nordics', 'countries' => ['SE', 'NO']]);
    $first = Territory::factory()->create(['label_id' => $label->id, 'name' => 'GSA', 'countries' => ['DE', 'AT', 'CH']]);
    Territory::factory()->create(['label_id' => Label::factory(), 'name' => 'Foreign']);

    $this->actingAs(labelAdmin($label))
        ->get(route('label-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/label')
            ->where('settings', [
                'name' => 'Example Records', 'currency' => 'EUR', 'contact_email' => 'office@example.com',
                'accounts_mechanicals' => true, 'accounts_ppd' => false, 'accounts_reserves' => true,
            ])
            ->where('territories', [
                ['id' => $first->id, 'name' => 'GSA', 'countries' => ['DE', 'AT', 'CH'], 'delete_block' => null],
                ['id' => $second->id, 'name' => 'Nordics', 'countries' => ['SE', 'NO'], 'delete_block' => null],
            ]));
});

test('the label settings can be saved', function () {
    $label = Label::factory()->create(['name' => 'Old name', 'currency' => 'EUR']);
    $other = Label::factory()->create(['name' => 'Other label', 'currency' => 'EUR']);

    $this->actingAs(labelAdmin($label))
        ->from(route('label-settings.edit'))
        ->put(route('label-settings.update'), [
            'name' => 'Example Records', 'currency' => 'usd', 'contact_email' => 'office@example.com',
            'accounts_mechanicals' => true, 'accounts_ppd' => true, 'accounts_reserves' => false,
        ])
        ->assertRedirect(route('label-settings.edit'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Label settings saved.');

    $label->refresh();

    expect($label->name)->toBe('Example Records')
        ->and($label->currency)->toBe('USD')
        ->and($label->contact_email)->toBe('office@example.com')
        ->and($label->accounts_mechanicals)->toBeTrue()
        ->and($label->accounts_ppd)->toBeTrue()
        ->and($label->accounts_reserves)->toBeFalse();
    $this->assertDatabaseHas('labels', ['id' => $other->id, 'name' => 'Other label', 'currency' => 'EUR']);
});

test('the label settings leave what lives on the connections page alone', function () {
    $label = Label::factory()->create([
        'name' => 'Example Records', 'currency' => 'EUR', 'airplay_markets' => ['FR'],
        'importer_settings' => ['bandcamp' => ['disable_fee' => true, 'exclude_physical' => true]],
        'delivery_settings' => ['distributors' => ['zebralution' => false], 'zebralution' => ['number' => '1100a1', 'origin_country' => 'DE', 'price_group' => 30, 'work_rights' => 'copyright expired']],
    ]);
    $before = [$label->importer_settings, $label->delivery_settings, $label->airplay_markets];

    $this->actingAs(labelAdmin($label))
        ->put(route('label-settings.update'), [
            'name' => 'Example Records', 'currency' => 'EUR',
            // What an old form or a forged request could still send.
            'bandcamp_disable_fee' => false, 'airplay_markets' => 'de', 'distributors' => ['zebralution' => '1'], 'zebralution_number' => 'other',
        ])
        ->assertSessionHasNoErrors();

    $label->refresh();

    expect([$label->importer_settings, $label->delivery_settings, $label->airplay_markets])->toBe($before);
});

test('the label settings page no longer carries the services', function () {
    $this->actingAs(labelAdmin(Label::factory()->create()))->get(route('label-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->missing('distributors')->missing('zebralutionWorkRights')->missing('settings.airplay_markets')->missing('settings.bandcamp_disable_fee'));
});

test('invalid label settings are rejected', function (string $field, mixed $value) {
    $label = Label::factory()->create(['name' => 'Example Records', 'currency' => 'EUR']);

    $this->actingAs(labelAdmin($label))
        ->put(route('label-settings.update'), ['name' => 'Changed', 'currency' => 'USD', $field => $value])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseHas('labels', ['id' => $label->id, 'name' => 'Example Records', 'currency' => 'EUR']);
})->with([
    'empty name' => ['name', ''],
    'name above 255 characters' => ['name', str_repeat('a', 256)],
    'empty currency' => ['currency', ''],
    'currency with four letters' => ['currency', 'EURO'],
    'currency with digits' => ['currency', 'E1R'],
    'contact address that is no email' => ['contact_email', 'office'],
    'flag that is no boolean' => ['accounts_ppd', 'maybe'],
]);

test('the slug of a label cannot be changed through the settings', function () {
    $label = Label::factory()->create(['slug' => 'example-records']);

    $this->actingAs(labelAdmin($label))
        ->put(route('label-settings.update'), ['name' => 'Example Records', 'currency' => 'EUR', 'slug' => 'hijacked'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('labels', ['id' => $label->id, 'slug' => 'example-records']);
});

test('a territory is stored with its country codes split, upper-cased and deduplicated', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->from(route('label-settings.edit'))
        ->post(route('territories.store'), ['name' => 'GSA', 'countries' => 'de, at ch;DE'])
        ->assertRedirect(route('label-settings.edit'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Territory created.');

    $territory = Territory::query()->withoutGlobalScopes()->sole();

    expect($territory->label_id)->toBe($label->id)
        ->and($territory->name)->toBe('GSA')
        ->and($territory->countries)->toBe(['DE', 'AT', 'CH']);
});

test('a territory with invalid input is rejected', function (array $input, string $error) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('territories.store'), ['name' => 'GSA', 'countries' => 'de, at', ...$input])
        ->assertSessionHasErrors($error);

    $this->assertDatabaseCount('territories', 0);
})->with([
    'code with three letters' => [['countries' => 'deu'], 'countries.0'],
    'code with a digit' => [['countries' => 'de, a1'], 'countries.1'],
    'no codes' => [['countries' => ''], 'countries'],
    'only separators' => [['countries' => ' , ; '], 'countries'],
    'no name' => [['name' => ''], 'name'],
]);

test('a territory can be deleted', function () {
    $label = Label::factory()->create();
    $territory = Territory::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->from(route('label-settings.edit'))
        ->delete(route('territories.destroy', $territory->id))
        ->assertRedirect(route('label-settings.edit'))
        ->assertInertiaFlash('toast.message', 'Territory deleted.');

    $this->assertModelMissing($territory);
});

test('a territory that splits refer to is kept', function () {
    $label = Label::factory()->create();
    $territory = Territory::factory()->create(['label_id' => $label->id]);
    $split = ContractSplit::factory()->create([
        'contract_id' => Contract::factory()->create(['label_id' => $label->id]),
        'payee_id' => Payee::factory()->create(['label_id' => $label->id]),
        'territory_id' => $territory->id,
    ]);

    $this->actingAs(labelAdmin($label))
        ->from(route('label-settings.edit'))
        ->delete(route('territories.destroy', $territory->id))
        ->assertRedirect(route('label-settings.edit'))
        ->assertInertiaFlash('toast.message', 'This territory is used by contract splits. Change those splits first.');

    $this->assertModelExists($territory);
    expect($split->refresh()->territory_id)->toBe($territory->id);
});

test('a territory of another label cannot be deleted', function () {
    $label = Label::factory()->create();
    $foreign = Territory::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))->delete(route('territories.destroy', $foreign->id))->assertNotFound();

    $this->assertModelExists($foreign);
});

test('a payee cannot reach the label settings', function () {
    $label = Label::factory()->create(['name' => 'Example Records']);
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $territory = Territory::factory()->create(['label_id' => $label->id]);
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    $this->actingAs($user)->get(route('label-settings.edit'))->assertRedirect(route('portal.index'));
    $this->actingAs($user)->put(route('label-settings.update'), ['name' => 'Hijacked', 'currency' => 'USD'])->assertForbidden();
    $this->actingAs($user)->post(route('territories.store'), ['name' => 'Sneaky', 'countries' => 'de'])->assertForbidden();
    $this->actingAs($user)->delete(route('territories.destroy', $territory->id))->assertForbidden();

    $this->assertDatabaseHas('labels', ['id' => $label->id, 'name' => 'Example Records']);
    $this->assertDatabaseCount('territories', 1);
});

test('the legal details and the people who sign for the label can be saved', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $partner = labelAdmin($label);
    $silent = labelAdmin($label);

    $this->actingAs($admin)
        ->put(route('label-settings.legal'), [
            'legal_name' => 'Example Records GbR', 'legal_form' => 'gbr', 'legal_address' => "Example Street 1\n10000 Berlin", 'legal_register' => '', 'signing_rule' => 'any',
            'signatories' => [$admin->id, $partner->id],
            // An exemption only counts for someone who signs.
            'self_dealing_exempt' => [$admin->id, $silent->id],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Legal details saved.');

    $label->refresh();

    expect($label->only(['legal_name', 'legal_form', 'legal_register', 'signing_rule']))->toBe(['legal_name' => 'Example Records GbR', 'legal_form' => 'gbr', 'legal_register' => null, 'signing_rule' => 'any'])
        ->and($label->signatories()->pluck('users.id')->sort()->values()->all())->toBe([$admin->id, $partner->id])
        ->and($label->users()->wherePivot('self_dealing_exempt', true)->pluck('users.id')->all())->toBe([$admin->id]);

    $this->actingAs($admin)->put(route('label-settings.legal'), ['signing_rule' => 'all', 'signatories' => [$admin->id]])->assertSessionHasNoErrors();

    expect($label->signatories()->pluck('users.id')->all())->toBe([$admin->id]);

    $this->actingAs($admin)
        ->get(route('label-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.legal_form', 'gbr')
            ->has('admins', 3)
            ->where('admins', fn ($admins) => collect($admins)->where('signs_for_label', true)->pluck('id')->all() === [$admin->id]));
});

test('only an admin of the label can be made to sign for it', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $payeeUser = User::factory()->create();
    $label->users()->attach($payeeUser, ['role' => LabelRole::Payee->value, 'payee_id' => Payee::factory()->create(['label_id' => $label->id])->id]);
    $foreignAdmin = labelAdmin(Label::factory()->create());

    foreach ([$payeeUser, $foreignAdmin] as $user) {
        $this->actingAs($admin)
            ->put(route('label-settings.legal'), ['signing_rule' => 'all', 'signatories' => [$user->id]])
            ->assertSessionHasErrors('signatories.0');
    }

    expect($label->signatories()->count())->toBe(0);
});
