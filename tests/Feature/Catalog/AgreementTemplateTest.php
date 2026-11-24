<?php

use App\Models\AgreementTemplate;
use App\Models\Label;
use App\Support\AgreementExamples;
use Inertia\Testing\AssertableInertia as Assert;

test('the templates page lists the label\'s own templates and the examples with their notice', function () {
    $label = Label::factory()->create();
    AgreementTemplate::factory()->create(['label_id' => $label->id, 'name' => 'Our remix deal']);
    AgreementTemplate::factory()->create(['label_id' => Label::factory(), 'name' => 'Foreign']);

    $this->actingAs(labelAdmin($label))
        ->get(route('agreement-templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agreement-templates/index')
            ->has('templates', 1)
            ->where('templates.0.name', 'Our remix deal')
            ->where('examples', fn ($examples) => collect($examples)->pluck('key')->all() === ['release', 'remix-artist', 'remix-label'])
            ->where('notice', AgreementExamples::NOTICE));
});

test('every example names itself, hides its notice from the text and only uses fields that exist', function () {
    foreach (AgreementExamples::all() as $example) {
        expect($example['name'])->not->toBe('')
            ->and($example['body'])->not->toContain('<!--')
            ->and($example['body'])->toContain('{{ label.legal_name }}')
            ->and(AgreementTemplate::unknownFields($example['body']))->toBe([]);
    }

    foreach (glob(resource_path('agreement-examples/*.md')) as $file) {
        expect(file_get_contents($file))->toStartWith("<!--\nEXAMPLE ONLY. NOT LEGAL ADVICE. NO WARRANTY.");
    }
});

test('an example is only copied by someone who takes responsibility for it', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);

    $this->actingAs($admin)
        ->post(route('agreement-templates.store'), ['example' => 'remix-label'])
        ->assertSessionHasErrors('reviewed');

    expect(AgreementTemplate::query()->count())->toBe(0);

    $this->actingAs($admin)
        ->post(route('agreement-templates.store'), ['example' => 'remix-label', 'reviewed' => '1'])
        ->assertSessionHasNoErrors();

    $template = AgreementTemplate::query()->sole();

    expect($template->label_id)->toBe($label->id)
        ->and($template->body)->toBe(AgreementExamples::all()['remix-label']['body']);

    $this->actingAs($admin)
        ->post(route('agreement-templates.store'), ['example' => 'no-such-example', 'reviewed' => '1'])
        ->assertSessionHasErrors('example');
});

test('a template is written, changed and deleted, and refuses a field that does not exist', function () {
    $label = Label::factory()->create();
    $admin = labelAdmin($label);

    $this->actingAs($admin)
        ->post(route('agreement-templates.store'), ['name' => 'Remix', 'body' => 'Pays {{ shares }} % to {{payee.name}}.'])
        ->assertSessionHasErrors(['body' => 'The text uses fields that do not exist: shares.']);

    $this->actingAs($admin)
        ->post(route('agreement-templates.store'), ['name' => 'Remix', 'body' => 'Pays {{ share }} % to {{payee.name}}.'])
        ->assertSessionHasNoErrors();

    $template = AgreementTemplate::query()->sole();

    $this->actingAs($admin)
        ->put(route('agreement-templates.update', $template->id), ['name' => 'Remix deal', 'body' => 'Pays {{ share }} %.'])
        ->assertSessionHasNoErrors();

    expect($template->refresh()->only(['name', 'body']))->toBe(['name' => 'Remix deal', 'body' => 'Pays {{ share }} %.']);

    $this->actingAs($admin)->delete(route('agreement-templates.destroy', $template->id))->assertSessionHasNoErrors();

    expect(AgreementTemplate::query()->count())->toBe(0);
});

test('a template of another label cannot be changed', function () {
    $template = AgreementTemplate::factory()->create(['name' => 'Foreign']);

    $this->actingAs(labelAdmin(Label::factory()->create()))
        ->put(route('agreement-templates.update', $template->id), ['name' => 'Taken', 'body' => 'Text'])
        ->assertNotFound();
});
