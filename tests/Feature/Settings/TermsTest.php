<?php

use App\Models\Agreement;
use App\Models\Label;
use App\Services\Handbook\Handbook;
use Inertia\Testing\AssertableInertia as Assert;

test('the agreement tools stay closed until an admin accepts the terms for the label', function () {
    $label = Label::factory()->create(['terms_accepted' => null]);
    $admin = labelAdmin($label);
    $agreement = Agreement::factory()->create(['label_id' => $label->id]);

    foreach (['agreements.index', 'agreements.create', 'agreement-templates.index'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertRedirect(route('terms.show'));
    }

    $this->actingAs($admin)->get(route('agreements.show', $agreement->id))->assertRedirect(route('terms.show'));
    $this->actingAs($admin)->post(route('agreements.send', $agreement->id))->assertForbidden();
    $this->actingAs($admin)->post(route('agreement-templates.store'), ['name' => 'Remix', 'body' => 'Text'])->assertForbidden();

    $this->actingAs($admin)
        ->get(route('terms.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/terms')
            ->where('accepted', false)
            ->where('acceptance', null)
            ->where('version', Label::TERMS_VERSION)
            ->where('html', fn (string $html) => str_contains($html, 'we are not liable for any damage')));

    $this->actingAs($admin)->post(route('terms.store'))->assertSessionHasErrors('accepted');
    $this->actingAs($admin)->post(route('terms.store'), ['accepted' => '1'])->assertRedirect(route('agreements.index'));

    expect($label->refresh()->terms_accepted)->toMatchArray(['version' => Label::TERMS_VERSION, 'user_id' => $admin->id, 'email' => $admin->email]);

    $this->actingAs($admin)->get(route('agreements.index'))->assertOk();
});

test('a label is asked again when the terms have changed, and one label\'s acceptance does not open another', function () {
    $outdated = Label::factory()->create(['terms_accepted' => ['version' => '2020-01-01', 'user_id' => 1, 'name' => 'Old', 'email' => 'old@example.com', 'at' => '2020-01-01T00:00:00+00:00']]);
    Label::factory()->create();

    $this->actingAs(labelAdmin($outdated))->get(route('agreements.index'))->assertRedirect(route('terms.show'));
    $this->actingAs(labelAdmin($outdated))
        ->get(route('terms.show'))
        ->assertInertia(fn (Assert $page) => $page->where('accepted', false)->where('acceptance.version', '2020-01-01'));
});

test('the terms name the version a label has to accept', function () {
    expect(app(Handbook::class)->find(Label::TERMS_ARTICLE)['body'])->toContain('Version '.Label::TERMS_VERSION);
});
