<?php

use App\Enums\LabelRole;
use App\Models\Label;
use App\Models\Payee;
use App\Models\User;
use App\Notifications\LabelInvitation;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Give a user access to the label with the given role.
 */
function labelMember(Label $label, LabelRole $role, ?Payee $payee = null, array $attributes = []): User
{
    $user = User::factory()->create(['current_label_id' => $label->id, ...$attributes]);
    $label->users()->attach($user, ['role' => $role->value, 'payee_id' => $payee?->id]);

    return $user;
}

test('the team page lists the members of the label by name', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $admin = labelMember($label, LabelRole::Admin, attributes: ['name' => 'Zoe Example', 'email' => 'zoe@example.com']);
    $member = labelMember($label, LabelRole::Payee, $payee, ['name' => 'Adam Example', 'email' => 'adam@example.com']);
    labelAdmin(Label::factory()->create());

    $this->actingAs($admin)
        ->get(route('team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/team')
            ->where('members', [
                ['id' => $member->id, 'name' => 'Adam Example', 'email' => 'adam@example.com', 'role' => 'payee', 'payee_id' => $payee->id, 'payee' => 'Example Artist'],
                ['id' => $admin->id, 'name' => 'Zoe Example', 'email' => 'zoe@example.com', 'role' => 'admin', 'payee_id' => null, 'payee' => null],
            ])
            ->missing('payeeOptions'));
});

test('an existing user can be added to the label', function () {
    $label = Label::factory()->create();
    $existing = User::factory()->create(['name' => 'Example Person', 'email' => 'person@example.com']);
    Notification::fake();

    $this->actingAs(labelAdmin($label))
        ->from(route('team.index'))
        ->post(route('team.store'), ['email' => 'person@example.com', 'name' => 'Ignored Name', 'role' => 'admin'])
        ->assertRedirect(route('team.index'))
        ->assertInertiaFlash('toast.message', 'Example Person was added to the label.');

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $existing->id, 'role' => 'admin', 'payee_id' => null]);
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseHas('users', ['id' => $existing->id, 'name' => 'Example Person']);
    Notification::assertSentTo($existing, LabelInvitation::class);
    Notification::assertCount(1);
});

test('an unknown email gets an account and an invitation instead of a password reset link', function () {
    $label = Label::factory()->create();
    Notification::fake();
    $this->travelTo('2026-11-18 12:00:00');

    $this->actingAs(labelAdmin($label))
        ->post(route('team.store'), ['email' => 'New.Person@Example.com', 'name' => 'New Person', 'role' => 'admin'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'New Person was added to the label.');

    $user = User::query()->where('email', 'new.person@example.com')->sole();

    expect($user->name)->toBe('New Person')
        ->and($user->email_verified_at?->toDateTimeString())->toBe('2026-11-18 12:00:00');
    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $user->id, 'role' => 'admin', 'payee_id' => null]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'new.person@example.com']);
    Notification::assertSentTo($user, LabelInvitation::class);
    Notification::assertCount(1);
});

test('a new account without a name is named after the email address', function () {
    $label = Label::factory()->create();
    Notification::fake();

    $this->actingAs(labelAdmin($label))
        ->post(route('team.store'), ['email' => 'new.person@example.com', 'name' => '', 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('users', ['email' => 'new.person@example.com', 'name' => 'new.person']);
});

test('a person can be added as a payee linked to a payee of the label', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $existing = User::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('team.store'), ['email' => $existing->email, 'role' => 'payee', 'payee_id' => $payee->id])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $existing->id, 'role' => 'payee', 'payee_id' => $payee->id]);
});

test('an admin can be a payee of the label too', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $existing = User::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->post(route('team.store'), ['email' => $existing->email, 'role' => 'admin', 'payee_id' => $payee->id])
        ->assertSessionHasNoErrors();
    $this->actingAs(labelAdmin($label))
        ->post(route('team.store'), ['email' => 'plain@example.com', 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('label_user', ['user_id' => $existing->id, 'role' => 'admin', 'payee_id' => $payee->id]);
    $this->assertDatabaseHas('label_user', ['user_id' => User::query()->where('email', 'plain@example.com')->value('id'), 'role' => 'admin', 'payee_id' => null]);
});

test('someone who already has access is not added again', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $member = labelMember($label, LabelRole::Payee, $payee);
    Notification::fake();

    $this->actingAs(labelAdmin($label))
        ->from(route('team.index'))
        ->post(route('team.store'), ['email' => $member->email, 'role' => 'admin'])
        ->assertRedirect(route('team.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This person already has access to the label.']);

    $this->assertDatabaseCount('label_user', 2);
    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $member->id, 'role' => 'payee', 'payee_id' => $payee->id]);
    Notification::assertNothingSent();
});

test('adding a member with invalid input is rejected', function (array $input, string $field) {
    $label = Label::factory()->create();
    $foreignPayee = Payee::factory()->create(['label_id' => Label::factory()]);
    $input = array_map(fn (mixed $value): mixed => $value === ':foreign-payee' ? $foreignPayee->id : $value, $input);
    Notification::fake();

    $this->actingAs(labelAdmin($label))
        ->post(route('team.store'), ['email' => 'new.person@example.com', 'role' => 'admin', ...$input])
        ->assertSessionHasErrors($field);

    $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    $this->assertDatabaseCount('label_user', 1);
    Notification::assertNothingSent();
})->with([
    'no email' => [['email' => ''], 'email'],
    'email that is no address' => [['email' => 'new.person'], 'email'],
    'no role' => [['role' => ''], 'role'],
    'unknown role' => [['role' => 'owner'], 'role'],
    'payee role without a payee' => [['role' => 'payee', 'payee_id' => ''], 'payee_id'],
    'payee of another label' => [['role' => 'payee', 'payee_id' => ':foreign-payee'], 'payee_id'],
]);

test('a member can be turned from an admin into a payee', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $member = labelMember($label, LabelRole::Admin);

    $this->actingAs(labelAdmin($label))
        ->from(route('team.index'))
        ->put(route('team.update', $member->id), ['role' => 'payee', 'payee_id' => $payee->id])
        ->assertRedirect(route('team.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Access saved.');

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $member->id, 'role' => 'payee', 'payee_id' => $payee->id]);
});

test('promoting a payee to admin keeps the link to their payee only when it is asked for', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $member = labelMember($label, LabelRole::Payee, $payee);

    $this->actingAs(labelAdmin($label))
        ->put(route('team.update', $member->id), ['role' => 'admin', 'payee_id' => $payee->id])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $member->id, 'role' => 'admin', 'payee_id' => $payee->id]);

    $this->actingAs(labelAdmin($label))
        ->put(route('team.update', $member->id), ['role' => 'admin', 'payee_id' => ''])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $member->id, 'role' => 'admin', 'payee_id' => null]);
});

test('changing access with invalid input is rejected', function (array $input, string $field) {
    $label = Label::factory()->create();
    $foreignPayee = Payee::factory()->create(['label_id' => Label::factory()]);
    $member = labelMember($label, LabelRole::Admin);
    $input = array_map(fn (mixed $value): mixed => $value === ':foreign-payee' ? $foreignPayee->id : $value, $input);

    $this->actingAs(labelAdmin($label))
        ->put(route('team.update', $member->id), $input)
        ->assertSessionHasErrors($field);

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $member->id, 'role' => 'admin', 'payee_id' => null]);
})->with([
    'no role' => [[], 'role'],
    'unknown role' => [['role' => 'owner'], 'role'],
    'payee role without a payee' => [['role' => 'payee'], 'payee_id'],
    'payee of another label' => [['role' => 'payee', 'payee_id' => ':foreign-payee'], 'payee_id'],
]);

test('the last admin cannot be turned into a payee', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);
    labelMember($label, LabelRole::Payee, $payee);

    $this->actingAs($admin)
        ->from(route('team.index'))
        ->put(route('team.update', $admin->id), ['role' => 'payee', 'payee_id' => $payee->id])
        ->assertRedirect(route('team.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A label needs at least one admin.']);

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $admin->id, 'role' => 'admin', 'payee_id' => null]);
});

test('an admin can step down when another admin remains', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);
    labelMember($label, LabelRole::Admin);

    $this->actingAs($admin)
        ->put(route('team.update', $admin->id), ['role' => 'payee', 'payee_id' => $payee->id])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $admin->id, 'role' => 'payee', 'payee_id' => $payee->id]);
});

test('the access of someone outside the label cannot be changed', function () {
    $label = Label::factory()->create();
    $otherLabel = Label::factory()->create();
    $outsider = labelAdmin($otherLabel);

    $this->actingAs(labelAdmin($label))
        ->put(route('team.update', $outsider->id), ['role' => 'admin'])
        ->assertNotFound();

    $this->assertDatabaseMissing('label_user', ['label_id' => $label->id, 'user_id' => $outsider->id]);
    $this->assertDatabaseHas('label_user', ['label_id' => $otherLabel->id, 'user_id' => $outsider->id, 'role' => 'admin']);
});

test('removing a member takes away their access and their selected label', function () {
    $label = Label::factory()->create();
    $member = labelMember($label, LabelRole::Admin);

    $this->actingAs(labelAdmin($label))
        ->from(route('team.index'))
        ->delete(route('team.destroy', $member->id))
        ->assertRedirect(route('team.index'))
        ->assertInertiaFlash('toast.message', 'Access removed.');

    $this->assertDatabaseMissing('label_user', ['label_id' => $label->id, 'user_id' => $member->id]);
    $this->assertDatabaseHas('users', ['id' => $member->id, 'current_label_id' => null]);
});

test('removing a member keeps the other label they are working in', function () {
    $label = Label::factory()->create();
    $otherLabel = Label::factory()->create();
    $member = labelAdmin($otherLabel);
    $label->users()->attach($member, ['role' => LabelRole::Admin->value]);

    $this->actingAs(labelAdmin($label))
        ->delete(route('team.destroy', $member->id))
        ->assertRedirect();

    $this->assertDatabaseMissing('label_user', ['label_id' => $label->id, 'user_id' => $member->id]);
    $this->assertDatabaseHas('label_user', ['label_id' => $otherLabel->id, 'user_id' => $member->id]);
    $this->assertDatabaseHas('users', ['id' => $member->id, 'current_label_id' => $otherLabel->id]);
});

test('the last admin cannot be removed even when payees remain', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    labelMember($label, LabelRole::Payee, Payee::factory()->create(['label_id' => $label->id]));

    $this->actingAs($admin)
        ->from(route('team.index'))
        ->delete(route('team.destroy', $admin->id))
        ->assertRedirect(route('team.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A label needs at least one admin.']);

    $this->assertDatabaseHas('label_user', ['label_id' => $label->id, 'user_id' => $admin->id, 'role' => 'admin']);
    $this->assertDatabaseHas('users', ['id' => $admin->id, 'current_label_id' => $label->id]);
});

test('removing someone outside the label leaves their own access untouched', function () {
    $label = Label::factory()->create();
    $otherLabel = Label::factory()->create();
    $outsider = labelAdmin($otherLabel);

    $this->actingAs(labelAdmin($label))->delete(route('team.destroy', $outsider->id));

    $this->assertDatabaseHas('label_user', ['label_id' => $otherLabel->id, 'user_id' => $outsider->id, 'role' => 'admin']);
    $this->assertDatabaseHas('users', ['id' => $outsider->id, 'current_label_id' => $otherLabel->id]);
});

test('a payee cannot manage the team', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);
    $user = labelMember($label, LabelRole::Payee, Payee::factory()->create(['label_id' => $label->id]));

    $this->actingAs($user)->get(route('team.index'))->assertRedirect(route('portal.index'));
    $this->actingAs($user)->post(route('team.store'), ['email' => 'friend@example.com', 'role' => 'admin'])->assertForbidden();
    $this->actingAs($user)->put(route('team.update', $user->id), ['role' => 'admin'])->assertForbidden();
    $this->actingAs($user)->delete(route('team.destroy', $admin->id))->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'friend@example.com']);
    $this->assertDatabaseHas('label_user', ['user_id' => $user->id, 'role' => 'payee']);
    $this->assertDatabaseHas('label_user', ['user_id' => $admin->id, 'role' => 'admin']);
});
