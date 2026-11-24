<?php

use App\Models\DataImport;
use App\Models\Transaction;

/**
 * The shared scenario with the three tools switched on.
 *
 * @return array<string, mixed>
 */
function royaltyToolsScenario(): array
{
    $scenario = royaltyScenario();
    $scenario['label']->update(['accounts_mechanicals' => true, 'accounts_ppd' => true, 'accounts_reserves' => true]);

    return $scenario;
}

function toolSaleFor(array $scenario, array $attributes): Transaction
{
    return Transaction::factory()->create([
        'label_id' => $scenario['label']->id, 'release_id' => $scenario['release']->id, 'paid_timestamp' => '2026-02-15', ...$attributes,
    ]);
}

test('mechanicals are reported for a range, booked, and an overlapping run needs a confirmation', function () {
    $scenario = royaltyToolsScenario();
    $scenario['release']->update(['accounts_mechanicals' => true]);
    $scenario['track']->update(['mechanicals_rate' => '0.5']);
    toolSaleFor($scenario, ['track_id' => $scenario['track']->id, 'format' => 'Download Track', 'quantity' => 10]);
    $this->actingAs($scenario['admin']);

    $page = visit('/royalty-tools/mechanicals')
        ->assertSee('Nothing has been booked with this tool yet.')
        ->fill('#from', '2026-01-01')
        ->fill('#to', '2026-03-31')
        ->click('Add manufacturing run')
        ->click('button[aria-label="Manufactured release"]')
        ->waitForText('Example Release')
        ->click('Example Release')
        ->fill('[aria-label="Copies manufactured"]', '100')
        ->press('Show report')
        ->assertSee('Calculated mechanicals')
        ->assertSeeIn('[data-slot="table-body"]', 'Example Track')
        ->assertSeeIn('[data-slot="table-body"]', '€55.00')
        ->click('[data-test="create-transactions"]')
        ->fill('#paid_on', '2026-03-31')
        ->press('[role="dialog"] button:has-text("Book")')
        ->assertSee('2 transactions created.')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->withoutGlobalScopes()->where('importer_source', 'mechanicals-tool')->orderBy('id')->pluck('converted_total')->all())
        ->toBe(['-55.000000000000000', '55.000000000000000']);

    $page->fill('#from', '2026-02-01')
        ->fill('#to', '2026-02-28')
        ->press('Show report')
        ->assertSee('This range overlaps an earlier run')
        ->click('[data-test="create-transactions"]')
        ->press('[role="dialog"] button:has-text("Book")')
        ->assertSee('An earlier run covers part of this range')
        ->check('#confirm_overlap')
        ->press('[role="dialog"] button:has-text("Book")')
        ->assertSee('2 transactions created.')
        ->assertNoJavaScriptErrors();

    expect(DataImport::query()->withoutGlobalScopes()->where('importer', 'mechanicals-tool')->count())->toBe(2);
});

test('a PPD rate is set on the rates page and the report is booked', function () {
    $scenario = royaltyToolsScenario();
    $this->actingAs($scenario['admin']);

    visit("/royalty-tools/ppd/rates/{$scenario['release']->id}")
        ->assertSee('PPD rates: Example Release')
        ->fill('#rate', '2')
        ->fill('[aria-label="Rate for Example Track"]', '3')
        ->press('Save rates')
        ->assertSee('Rates saved.')
        ->assertNoJavaScriptErrors();

    // The scenario sale: 250 units on the track, now at 3 per unit.
    visit('/royalty-tools/ppd')
        ->fill('#from', '2026-01-01')
        ->fill('#to', '2026-03-31')
        ->press('Show report')
        ->assertSee('Calculated PPD')
        ->assertSeeIn('[data-slot="table-body"]', '€750.00')
        ->click('[data-test="create-transactions"]')
        ->fill('#paid_on', '2026-03-31')
        ->press('[role="dialog"] button:has-text("Book")')
        ->assertSee('2 transactions created.')
        ->assertSee('Earlier runs')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->withoutGlobalScopes()->where('importer_source', 'ppd-tool')->orderBy('id')->pluck('converted_total')->all())
        ->toBe(['750.000000000000000', '-750.000000000000000']);
});

test('reserves are held and liquidated on the dates entered', function () {
    $scenario = royaltyToolsScenario();
    $scenario['release']->update(['reserves_rate' => '25']);
    toolSaleFor($scenario, ['format' => 'LP', 'total' => '400', 'quantity' => 20]);
    $this->actingAs($scenario['admin']);

    visit('/royalty-tools/reserves')
        ->assertSee('No reserves have been booked yet.')
        ->fill('#from', '2026-01-01')
        ->fill('#to', '2026-03-31')
        ->press('Show report')
        ->assertSee('Calculated reserves')
        ->assertSeeIn('[data-slot="table-body"]', '€100.00')
        ->click('[data-test="create-transactions"]')
        ->fill('#held_on', '2026-03-31')
        ->fill('#liquidated_on', '2026-09-30')
        ->press('[role="dialog"] button:has-text("Book")')
        ->assertSee('2 transactions created.')
        ->assertSee('Still held')
        ->assertNoJavaScriptErrors();

    $booked = Transaction::query()->withoutGlobalScopes()->where('importer_source', 'reserves-tool')->orderBy('id')->get();

    expect($booked->pluck('converted_total')->all())->toBe(['-100.000000000000000', '100.000000000000000'])
        ->and($booked->map(fn (Transaction $transaction): string => $transaction->paid_timestamp->toDateString())->all())->toBe(['2026-03-31', '2026-09-30']);
});

test('switching a tool on reveals its settings, its menu entry and its rate field on the release form', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit("/releases/{$scenario['release']->id}/edit")
        ->assertDontSee('Mechanicals, PPD and reserves')
        ->assertNoJavaScriptErrors();

    visit('/settings/label')
        ->assertMissing('#mechanicals_usd_rate')
        ->assertMissing('a[href$="/royalty-tools/mechanicals"]')
        ->check('[name="accounts_mechanicals"][type="checkbox"]')
        ->fill('#mechanicals_usd_rate', '0.9')
        ->press('Save')
        ->assertSee('Label settings saved.')
        ->assertPresent('a[href$="/royalty-tools/mechanicals"]')
        ->assertMissing('a[href$="/royalty-tools/ppd"]')
        ->assertNoJavaScriptErrors();

    visit("/releases/{$scenario['release']->id}/edit")
        ->assertSee('Mechanicals, PPD and reserves')
        ->assertPresent('#mechanicals_rate')
        ->assertMissing('#ppd_rate')
        ->assertMissing('#reserves_rate')
        ->assertNoJavaScriptErrors();

    expect($scenario['label']->refresh()->royalty_tool_settings['usd_rate'])->toBe('0.9');
});
