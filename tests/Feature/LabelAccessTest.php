<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Track;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function payeeUser(Label $label, Payee $payee): User
{
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    return $user;
}

test('creating a label makes the user its admin and selects it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('labels.store'), ['name' => 'Example Records', 'currency' => 'eur'])
        ->assertRedirect(route('dashboard'));

    $label = Label::query()->where('name', 'Example Records')->sole();

    expect($label->currency)->toBe('EUR')
        ->and($user->refresh()->current_label_id)->toBe($label->id)
        ->and($label->users()->sole()->pivot->role)->toBe('admin');
});

test('lists only show records of the current label', function () {
    $label = Label::factory()->create();
    $other = Label::factory()->create();
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Ours']);
    Release::factory()->create(['label_id' => $other->id, 'name' => 'Theirs']);

    $this->actingAs(labelAdmin($label))
        ->get(route('releases.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('releases/index')
            ->has('releases.data', 1)
            ->where('releases.data.0.name', 'Ours'));
});

test('a record of another label cannot be opened or changed', function () {
    $label = Label::factory()->create();
    $foreign = Release::factory()->create(['label_id' => Label::factory(), 'name' => 'Theirs']);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('releases.show', $foreign->id))->assertNotFound();
    $this->actingAs($admin)->put(route('releases.update', $foreign->id), ['name' => 'Hijacked'])->assertNotFound();
    $this->actingAs($admin)->delete(route('releases.destroy', $foreign->id))->assertNotFound();

    $this->assertDatabaseHas('releases', ['id' => $foreign->id, 'name' => 'Theirs']);
});

test('a contract of another label cannot be set on the tracks of a release', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $foreign = Contract::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->put(route('releases.update', $release->id), ['name' => $release->name, 'tracks' => [['id' => $track->id, 'name' => $track->name, 'contract_id' => $foreign->id]]])
        ->assertSessionHasErrors('tracks.0.contract_id');

    $this->assertDatabaseHas('tracks', ['id' => $track->id, 'contract_id' => null]);
});

test('a user cannot switch to a label they do not belong to', function () {
    $user = labelAdmin(Label::factory()->create());
    $foreign = Label::factory()->create();

    $this->actingAs($user)->post(route('labels.switch', $foreign))->assertForbidden();

    expect($user->refresh()->current_label_id)->not->toBe($foreign->id);
});

test('switching labels changes which records are shown', function () {
    $first = Label::factory()->create();
    $second = Label::factory()->create();
    $user = labelAdmin($first);
    $second->users()->attach($user, ['role' => LabelRole::Admin->value]);
    Release::factory()->create(['label_id' => $second->id, 'name' => 'Second label release']);

    $this->actingAs($user)->post(route('labels.switch', $second))->assertRedirect(route('dashboard'));

    $this->get(route('releases.index'))
        ->assertInertia(fn (Assert $page) => $page->where('releases.data.0.name', 'Second label release'));
});

test('a payee is kept out of the label administration', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $user = payeeUser($label, $payee);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('portal.index'));
    $this->actingAs($user)->get(route('payees.index'))->assertRedirect(route('portal.index'));
    $this->actingAs($user)->post(route('payees.store'), ['name' => 'Sneaky'])->assertForbidden();

    $this->assertDatabaseMissing('payees', ['name' => 'Sneaky']);
});

test('a payee sees only their own published statements', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $own = Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'published_at' => now()]);
    $draft = Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id]);
    $someoneElses = Statement::factory()->create(['label_id' => $label->id, 'payee_id' => Payee::factory()->create(['label_id' => $label->id]), 'published_at' => now()]);
    $user = payeeUser($label, $payee);

    $this->actingAs($user)->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/index')
            ->has('groups.0.statements.data', 1)
            ->where('groups.0.statements.data.0.id', $own->id));

    $this->actingAs($user)->get(route('portal.show', $draft->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.show', $someoneElses->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.download', $someoneElses->id))->assertNotFound();
    $this->actingAs($user)->get(route('statements.show', $own->id))->assertRedirect(route('portal.index'));
});

test('the last admin of a label cannot be removed', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);

    $this->actingAs($admin)->delete(route('team.destroy', $admin->id));

    expect($label->users()->count())->toBe(1);
});
