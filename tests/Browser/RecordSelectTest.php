<?php

use App\Models\Agreement;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Support\Facades\Storage;

test('a dropdown of records is searched, loads more as it is scrolled, and sends what is picked', function () {
    $label = Label::factory()->create();
    Payee::factory()->count(40)->sequence(fn ($sequence) => ['name' => sprintf('Payee %02d', $sequence->index)])->create(['label_id' => $label->id]);
    $wanted = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Zed Remixer']);
    $agreement = Agreement::factory()->create(['label_id' => $label->id, 'payee_id' => null, 'name' => 'Old deal', 'agreement_template_id' => null]);
    $agreement->forceFill(['status' => Agreement::SIGNED, 'signed_copy_path' => 'local:agreements/1/x.pdf', 'signed_copy_name' => 'x.pdf'])->save();
    Storage::disk('local')->put('agreements/1/x.pdf', '%PDF-1.4');
    $this->actingAs(labelAdmin($label));

    $page = visit("/agreements/{$agreement->id}")
        ->assertSee('What this agreement belongs to')
        ->click('#match-payee')
        ->waitForText('Payee 00')
        ->assertSee('Payee 29')
        // Only the first thirty are there until the list is scrolled to its end.
        ->assertDontSee('Payee 35');

    $page->script("document.querySelector('[role=listbox]').scrollTo(0, 100000)");

    $page->waitForText('Payee 35')
        ->type('input[aria-label="Search"]', 'zed')
        ->waitForText('Zed Remixer')
        ->assertDontSee('Payee 00')
        ->click('Zed Remixer')
        ->assertSeeIn('#match-payee', 'Zed Remixer')
        ->press('Save')
        ->waitForText('Agreement matched.')
        ->assertNoJavaScriptErrors();

    expect($agreement->refresh()->payee_id)->toBe($wanted->id);
});

test('an agreement is given a release and only one of its tracks', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Remix EP']);
    $wanted = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'First Remix']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Second Remix']);
    $agreement = Agreement::factory()->create(['label_id' => $label->id, 'name' => 'Remix deal']);
    $this->actingAs(labelAdmin($label));

    visit("/agreements/{$agreement->id}/edit")
        ->click('#add-release')
        ->waitForText('Remix EP')
        ->click('Remix EP')
        ->waitForText('Second Remix')
        ->check('First Remix')
        ->press('Save')
        ->waitForText('Agreement saved.')
        ->assertSee('Linked to')
        ->assertSee('First Remix')
        ->assertDontSee('Second Remix')
        ->assertNoJavaScriptErrors();

    expect($agreement->releases()->pluck('releases.id')->all())->toBe([$release->id])
        ->and($agreement->tracks()->pluck('tracks.id')->all())->toBe([$wanted->id]);
});
