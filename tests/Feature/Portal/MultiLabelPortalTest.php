<?php

use App\Enums\LabelRole;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A label with a payee of its own and a published statement of that payee.
 *
 * @param  array<string, mixed>  $statement
 * @return array{label: Label, payee: Payee, statement: Statement}
 */
function labelWithPublishedStatement(string $name, array $statement = []): array
{
    $label = Label::factory()->create(['name' => $name]);
    $payee = Payee::factory()->create(['label_id' => $label->id]);

    return [
        'label' => $label,
        'payee' => $payee,
        'statement' => Statement::factory()->create([
            'label_id' => $label->id, 'payee_id' => $payee->id, 'name' => "{$name} statement", 'published_at' => now(),
            'data' => ['payee_name' => 'Example Artist', 'currency' => 'EUR'],
            ...$statement,
        ]),
    ];
}

/**
 * @param  array<string, array{label: Label, payee: Payee}>  $memberships
 */
function userOfLabels(array $memberships, ?Label $current = null): User
{
    $user = User::factory()->create(['current_label_id' => $current?->id]);

    foreach ($memberships as $role => $membership) {
        $membership['label']->users()->attach($user, ['role' => str_starts_with($role, 'admin') ? LabelRole::Admin->value : LabelRole::Payee->value, 'payee_id' => str_starts_with($role, 'admin') ? null : $membership['payee']->id]);
    }

    return $user;
}

test('a payee of two labels opens and downloads a statement of either without switching', function () {
    $alpha = labelWithPublishedStatement('Example Alpha');
    $beta = labelWithPublishedStatement('Example Beta');
    $user = userOfLabels(['payee-a' => $alpha, 'payee-b' => $beta], $alpha['label']);

    foreach ([$alpha, $beta] as $own) {
        $this->actingAs($user)->get(route('portal.show', $own['statement']->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('statements/show')
                ->where('statement.id', $own['statement']->id)
                ->where('statementLabel.id', $own['label']->id)
                ->where('statementLabel.name', $own['label']->name)
                ->where('portal', true));
        $this->actingAs($user)->get(route('portal.download', $own['statement']->id))->assertOk()->assertDownload();
    }

    expect($user->refresh()->current_label_id)->toBe($alpha['label']->id);
});

test('a statement is not found when it belongs to another payee, is a draft, is archived or is of a label the user is not in', function () {
    $own = labelWithPublishedStatement('Example Alpha');
    $user = userOfLabels(['payee' => $own], $own['label']);
    $sameLabel = fn (array $attributes): Statement => Statement::factory()->create([
        'label_id' => $own['label']->id, 'payee_id' => $own['payee']->id, 'published_at' => now(), ...$attributes,
    ]);
    $hidden = [
        'another payee of the same label' => $sameLabel(['payee_id' => Payee::factory()->create(['label_id' => $own['label']->id])->id]),
        'a draft' => $sameLabel(['published_at' => null]),
        'an archived one' => $sameLabel(['archived' => true]),
        'a third label' => labelWithPublishedStatement('Example Gamma')['statement'],
        'a payee id of the own label under a foreign label' => Statement::factory()->create(['label_id' => Label::factory(), 'payee_id' => $own['payee']->id, 'published_at' => now()]),
    ];

    foreach ($hidden as $reason => $statement) {
        $this->actingAs($user)->get(route('portal.show', $statement->id))->assertNotFound($reason);
        $this->actingAs($user)->get(route('portal.download', $statement->id))->assertNotFound($reason);
    }
});

test('a label the user left loses its statements at once', function () {
    $alpha = labelWithPublishedStatement('Example Alpha');
    $beta = labelWithPublishedStatement('Example Beta');
    $user = userOfLabels(['payee-a' => $alpha, 'payee-b' => $beta], $beta['label']);

    $this->actingAs($user)->get(route('portal.show', $beta['statement']->id))->assertOk();

    $beta['label']->users()->detach($user);

    $this->actingAs($user)->get(route('portal.show', $beta['statement']->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.download', $beta['statement']->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->has('groups', 1)->where('groups.0.label.name', 'Example Alpha'));
    $this->actingAs($user)->get(route('label-branding.show', ['logo', 'label' => $beta['label']->id]))->assertNotFound();
});

test('a payee row without a linked payee gives access to nothing', function () {
    $alpha = labelWithPublishedStatement('Example Alpha');
    $beta = labelWithPublishedStatement('Example Beta');
    $user = userOfLabels(['payee-a' => $alpha], $alpha['label']);
    $beta['label']->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => null]);

    $this->actingAs($user)->get(route('portal.show', $beta['statement']->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.download', $beta['statement']->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->has('groups', 1)->where('groups.0.label.name', 'Example Alpha'));
});

test('what the address says never widens what the user may read', function () {
    $alpha = labelWithPublishedStatement('Example Alpha');
    $beta = labelWithPublishedStatement('Example Beta');
    $user = userOfLabels(['payee-a' => $alpha], $alpha['label']);

    $this->actingAs($user)->get(route('portal.index', ['label' => $beta['label']->id, 'label_id' => $beta['label']->id, 'payee_id' => $beta['payee']->id]))
        ->assertInertia(fn (Assert $page) => $page->has('groups', 1)->where('groups.0.label.id', $alpha['label']->id));
    $this->actingAs($user)->get(route('portal.show', ['statement' => $beta['statement']->id, 'label' => $beta['label']->id, 'payee_id' => $beta['payee']->id]))->assertNotFound();
});

test('someone who is admin of one label and payee of another stays out of the admin pages of the second', function () {
    $mine = Label::factory()->create(['name' => 'Example Own Label']);
    $other = labelWithPublishedStatement('Example Other Label');
    $draft = Statement::factory()->create(['label_id' => $other['label']->id, 'payee_id' => $other['payee']->id]);
    $someoneElses = Statement::factory()->create([
        'label_id' => $other['label']->id, 'payee_id' => Payee::factory()->create(['label_id' => $other['label']->id])->id, 'published_at' => now(),
    ]);
    $theirRelease = Release::factory()->create(['label_id' => $other['label']->id, 'name' => 'Theirs']);
    $ownRelease = Release::factory()->create(['label_id' => $mine->id, 'name' => 'Ours']);
    // The account was left pointing at the label it is only a payee of.
    $user = userOfLabels(['payee' => $other], $other['label']);
    $mine->users()->attach($user, ['role' => LabelRole::Admin->value]);

    $this->actingAs($user)->get(route('releases.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('releases.data', 1)->where('releases.data.0.name', 'Ours'));
    expect($user->refresh()->current_label_id)->toBe($mine->id);

    $this->actingAs($user)->get(route('releases.show', $theirRelease->id))->assertNotFound();
    $this->actingAs($user)->get(route('statements.show', $draft->id))->assertNotFound();
    $this->actingAs($user)->post(route('statements.publish', $draft->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.show', $draft->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.show', $someoneElses->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.download', $someoneElses->id))->assertNotFound();
    $this->actingAs($user)->get(route('portal.show', $other['statement']->id))->assertOk();
    $this->actingAs($user)->get(route('releases.show', $ownRelease->id))->assertOk();
    expect($draft->refresh()->published_at)->toBeNull();
});

test('the admin and payee user sees My statements for the payee labels only, next to the label switcher of the labels they run', function () {
    $mine = Label::factory()->create(['name' => 'Example Own Label']);
    $other = labelWithPublishedStatement('Example Other Label');
    $user = userOfLabels(['payee' => $other], $mine);
    $mine->users()->attach($user, ['role' => LabelRole::Admin->value]);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('labels', [['id' => $mine->id, 'name' => 'Example Own Label']])
        ->where('isPayee', true)
        ->where('label.id', $mine->id));
    $this->actingAs($user)->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->where('view', 'payee')
        ->has('groups', 1)
        ->where('groups.0.label.name', 'Example Other Label'));
});

test('a payee only has no label switcher, and the shared props name no label they are not in', function () {
    $alpha = labelWithPublishedStatement('Example Alpha');
    $beta = labelWithPublishedStatement('Example Beta');
    labelWithPublishedStatement('Example Secret');
    $user = userOfLabels(['payee-a' => $alpha, 'payee-b' => $beta], $alpha['label']);

    $response = $this->actingAs($user)->get(route('portal.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('labels', [])
        ->where('isPayee', true)
        ->where('canCreateLabel', false)
        ->where('auth.user.name', $user->name));

    expect($response->getContent())->not->toContain('Example Secret');
});

test('the logo of a label is served only to those who belong to it', function () {
    $alpha = labelWithPublishedStatement('Example Alpha');
    $beta = labelWithPublishedStatement('Example Beta');
    $secret = labelWithPublishedStatement('Example Secret');
    $this->actingAs(labelAdmin($beta['label']))->post(route('label-branding.store', 'logo'), ['file' => UploadedFile::fake()->image('logo.png')])->assertOk();
    $this->actingAs(labelAdmin($secret['label']))->post(route('label-branding.store', 'logo'), ['file' => UploadedFile::fake()->image('logo.png')])->assertOk();
    $user = userOfLabels(['payee-a' => $alpha, 'payee-b' => $beta], $alpha['label']);

    $this->actingAs($user)->get(route('label-branding.show', ['logo', 'label' => $beta['label']->id]))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs($user)->get(route('label-branding.show', ['logo', 'label' => $secret['label']->id]))->assertNotFound();
    $this->actingAs($user)->get(route('label-branding.show', ['logo', 'label' => 999999]))->assertNotFound();
    $this->actingAs($user)->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->where('groups.1.label.logo_url', fn (?string $url): bool => str_contains((string) $url, "label={$beta['label']->id}")));
});

test('a payee cannot change anything in a label they are only a payee of', function () {
    $alpha = labelWithPublishedStatement('Example Alpha');
    $beta = labelWithPublishedStatement('Example Beta');
    $user = userOfLabels(['payee-a' => $alpha, 'payee-b' => $beta], $beta['label']);

    $this->actingAs($user)->get(route('releases.index'))->assertRedirect(route('portal.index'));
    $this->actingAs($user)->post(route('statements.publish', $beta['statement']->id))->assertForbidden();
    $this->actingAs($user)->post(route('label-branding.store', 'logo'), ['file' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
});

test('someone who runs a label and is a payee elsewhere sees both: their own statements and what the payees of their label see', function () {
    $mine = Label::factory()->create(['name' => 'Example Own Label']);
    $myPayee = Payee::factory()->create(['label_id' => $mine->id, 'name' => 'Example Payee One']);
    $otherPayee = Payee::factory()->create(['label_id' => $mine->id, 'name' => 'Example Payee Two']);
    $published = Statement::factory()->create(['label_id' => $mine->id, 'payee_id' => $myPayee->id, 'published_at' => now()]);
    Statement::factory()->create(['label_id' => $mine->id, 'payee_id' => $otherPayee->id, 'published_at' => now()]);
    Statement::factory()->create(['label_id' => $mine->id, 'payee_id' => $otherPayee->id]);
    $other = labelWithPublishedStatement('Example Other Label');
    labelWithPublishedStatement('Example Secret');
    $user = userOfLabels(['payee' => $other], $mine);
    $mine->users()->attach($user, ['role' => LabelRole::Admin->value]);

    $this->actingAs($user)->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->where('view', 'payee')
        ->has('groups', 1)->where('groups.0.label.name', 'Example Other Label')
        ->has('statements.data', 2)
        ->where('statements.data.0.payee', fn (?string $name): bool => in_array($name, ['Example Payee One', 'Example Payee Two'], true))
        ->missing('payeeOptions'));

    $this->actingAs($user)->get(route('portal.index', ['payee' => $myPayee->id]))->assertInertia(fn (Assert $page) => $page
        ->has('statements.data', 1)->where('statements.data.0.id', $published->id)->where('filters.payee', $myPayee->id));
});

test('a pure admin sees only the preview and a pure payee only their own statements', function () {
    $mine = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $mine->id]);
    Statement::factory()->create(['label_id' => $mine->id, 'payee_id' => $payee->id, 'published_at' => now()]);

    $this->actingAs(labelAdmin($mine))->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->where('view', 'admin')->has('groups', 0)->has('statements.data', 1));

    $alpha = labelWithPublishedStatement('Example Alpha');
    $this->actingAs(userOfLabels(['payee' => $alpha], $alpha['label']))->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->where('view', 'payee')->has('groups', 1)->where('statements', null));
});

test('an admin whose payee role elsewhere is not linked still gets the preview of their label', function () {
    $mine = Label::factory()->create();
    $other = Label::factory()->create();
    $user = labelAdmin($mine);
    $other->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => null]);

    $this->actingAs($user)->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page->where('view', 'admin')->has('groups', 0));
});

test('someone removed from their last label who may not create one is told so, and cannot create', function () {
    $user = User::factory()->create(['may_create_labels' => false]);

    $this->actingAs($user)->get(route('labels.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('labels/none'));
    $this->actingAs($user)->post(route('labels.store'), ['name' => 'Example Records', 'currency' => 'EUR'])->assertForbidden();
});
