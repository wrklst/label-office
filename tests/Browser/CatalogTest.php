<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Track;

test('a payee is created in a dialog that closes once it is saved', function () {
    $this->actingAs(labelAdmin(Label::factory()->create()));

    visit('/payees')
        ->assertSee('No payees yet')
        ->click('New payee')
        ->fill('name', 'Example Payee')
        ->fill('tags', 'artist, remixer')
        ->press('Create payee')
        ->assertSee('Payee created.')
        ->assertSeeIn('[data-slot="table"]', 'Example Payee')
        ->assertSeeIn('[data-slot="table"]', 'remixer')
        ->assertMissing('[role="dialog"]')
        ->assertNoJavaScriptErrors();
});

test('a dialog stays open and shows the problem when the server rejects the input', function () {
    $this->actingAs(labelAdmin(Label::factory()->create()));

    visit('/periods')
        ->click('New period')
        ->fill('starts_on', '2026-06-30')
        ->fill('ends_on', '2026-04-01')
        ->press('Create period')
        ->assertSeeIn('[role="dialog"]', 'must be a date after or equal to')
        ->assertPathIs('/periods');
});

test('a contract is built from split rows and shows what the label keeps', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee']);
    $this->actingAs(labelAdmin($label));

    $page = visit('/contracts/create')
        ->assertSee('No splits: the label keeps everything booked on this contract.')
        ->fill('name', 'Example Deal')
        ->click('Add split')
        ->click('button[aria-label="Payee"]')
        ->waitForText('Example Payee')
        ->click('Example Payee')
        ->fill('input[aria-label="Split percent"]', '40')
        ->assertSee('Payees get 40% worldwide, the label keeps 60%.')
        ->press('Create contract')
        ->assertSee('Contract created.')
        ->assertSee('Splits')
        ->assertSee('Example Payee')
        ->assertSee('40%');

    $page->assertNoJavaScriptErrors();

    expect(ContractSplit::query()->sole()->split_amount)->toBe('0.400000000');
});

test('the contract form warns when the splits exceed the whole', function () {
    $label = Label::factory()->create();
    Payee::factory()->create(['label_id' => $label->id]);
    $this->actingAs(labelAdmin($label));

    visit('/contracts/create')
        ->click('Add split')
        ->fill('input[aria-label="Split percent"]', '120')
        ->assertSee('add up to more than 100%');
});

test('choosing a contract in the catalog manager saves it straight away', function () {
    $label = Label::factory()->create();
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Deal']);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track']);
    $this->actingAs(labelAdmin($label));

    visit('/catalog')
        ->assertSee('1 track without a contract')
        ->click('button[aria-label="Contract for Example Release"]')
        ->waitForText('Example Deal')
        ->click('Example Deal')
        ->assertSee('Every track is covered by a contract.')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('releases', ['id' => $release->id, 'contract_id' => $contract->id]);
});

test('typing in a search box narrows the list', function () {
    $label = Label::factory()->create();
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Northern Lights']);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Southern Cross']);
    $this->actingAs(labelAdmin($label));

    visit('/releases')
        ->assertSee('Northern Lights')
        ->fill('input[type="search"]', 'south')
        ->assertDontSee('Northern Lights')
        ->assertSee('Southern Cross')
        ->assertQueryStringHas('search', 'south');
});

test('deleting asks for confirmation and says what is affected', function () {
    $label = Label::factory()->create();
    Ledger::factory()->create(['label_id' => $label->id, 'name' => 'Album advance']);
    $this->actingAs(labelAdmin($label));

    visit('/ledgers')
        ->click('[aria-label="Delete Album advance?"]')
        ->assertSeeIn('[role="dialog"]', 'move to the primary ledger')
        ->click('[role="dialog"] button:has-text("Cancel")')
        ->assertMissing('[role="dialog"]')
        ->assertSeeIn('[data-slot="table"]', 'Album advance')
        ->click('[aria-label="Delete Album advance?"]')
        ->assertSeeIn('[role="dialog"]', 'move to the primary ledger')
        ->click('[role="dialog"] button:has-text("Delete")')
        ->assertSee('Only the primary ledger so far');

    $this->assertDatabaseCount('ledgers', 0);
});

test('switching labels in the sidebar changes the data shown', function () {
    $first = Label::factory()->create(['name' => 'First Label']);
    $second = Label::factory()->create(['name' => 'Second Label']);
    $user = labelAdmin($first);
    $second->users()->attach($user, ['role' => 'admin']);
    Release::factory()->create(['label_id' => $second->id, 'name' => 'Only On Second']);
    $this->actingAs($user);

    visit('/releases')
        ->assertDontSee('Only On Second')
        ->click('[data-slot="sidebar-header"] button')
        ->click('[role="menuitem"]:has-text("Second Label")')
        ->assertPathIs('/dashboard')
        ->navigate('/releases')
        ->assertSee('Only On Second');
});

test('the track dialog on the list loads the lyrics when it opens and saves them with the track', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => 'Example Track', 'lyrics' => 'Example lyrics line']);
    $this->actingAs(labelAdmin($label));

    visit('/tracks')
        ->click('[aria-label="Edit Example Track"]')
        ->assertValue('#lyrics', 'Example lyrics line')
        ->press('Save track')
        ->assertSee('Track saved.')
        ->assertNoJavaScriptErrors();

    expect($track->refresh()->lyrics)->toBe('Example lyrics line');
});

test('an artist page has Edit and Delete in its header, and deleting returns to the list', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $this->actingAs(labelAdmin($label));

    visit("/artists/{$artist->id}")
        ->press('Edit')
        ->fill('name', 'Example Artist Renamed')
        ->press('Save')
        ->assertSee('Artist saved.')
        ->assertSee('Example Artist Renamed')
        ->press('Delete')
        ->click('[role="dialog"] button:has-text("Delete")')
        ->assertPathIs('/artists')
        ->assertNoJavaScriptErrors();
});
