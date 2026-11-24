<?php

use App\Models\Label;
use App\Models\Statement;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;

test('a statement file is uploaded, recognised and summarised', function () {
    Storage::fake('local');
    $this->actingAs(labelAdmin(Label::factory()->create()));

    visit('/data-imports/create')
        ->assertChecked('importer', 'auto')
        ->attach('#file input[type="file"]', base_path('tests/Fixtures/zebralution_2026q3_sample.csv'))
        ->assertSee('zebralution_2026q3_sample.csv')
        ->fill('default_paid_timestamp', '2026-10-15')
        ->press('Import')
        ->assertSee('67 transactions imported.')
        ->assertSee('Zebralution (track statement, 2024 Q1 onwards)')
        ->assertSee('new releases / tracks')
        ->assertSee('How the income developed')
        ->assertPresent('[data-platform="spotify"]')
        ->assertNoJavaScriptErrors();
});

test('the upload form explains a missing paid date instead of importing', function () {
    Storage::fake('local');
    $this->actingAs(labelAdmin(Label::factory()->create()));

    visit('/data-imports/create')
        ->attach('#file input[type="file"]', base_path('tests/Fixtures/zebralution_2026q3_sample.csv'))
        ->assertSee('zebralution_2026q3_sample.csv')
        ->press('Import')
        ->assertSee('Zebralution statements have no payment date')
        ->assertPathIs('/data-imports/create');

    $this->assertDatabaseCount('transactions', 0);
});

test('choosing a source marks the paid date as required', function () {
    $this->actingAs(labelAdmin(Label::factory()->create()));

    visit('/data-imports/create')
        ->assertAttributeMissing('#default_paid_timestamp', 'required')
        ->radio('importer', 'zebralution')
        ->assertAttribute('#default_paid_timestamp', 'required', '')
        ->assertSee('This source does not say when you were paid');
});

test('a period is settled from statement to payout in the browser', function () {
    $scenario = royaltyScenario();
    $scenario['period']->delete();
    $this->actingAs($scenario['admin']);

    $page = visit('/periods')
        ->click('New period')
        ->fill('name', '2026 Q1')
        ->fill('starts_on', '2026-01-01')
        ->fill('ends_on', '2026-03-31')
        ->press('Create period')
        ->assertSee('Everything is assigned to a release, covered by a contract and converted.')
        ->assertSee('0 of 1 payees have a statement')
        ->assertSee('€50.00');

    $page->press('Generate all statements')
        ->assertSee('1 statement generated.')
        ->assertSeeIn('[data-slot="table-body"]', 'Draft');

    $page->click('[aria-label="Book payout to Example Payee"]')
        ->assertValue('[role="dialog"] input[name="total"]', '50.00')
        ->fill('[role="dialog"] input[name="total"]', '20')
        ->fill('memo', 'Bank transfer')
        ->press('Book payout')
        ->assertSee('Payout booked.')
        ->assertSeeIn('[data-slot="table-footer"]', '€30.00');

    $page->click('[data-slot="table-body"] a:has-text("Draft")')
        ->assertSee('Draft – not visible to the payee')
        ->press('Publish to payee')
        ->assertSee('Published to payee')
        ->assertNoJavaScriptErrors();

    expect(Statement::query()->withoutGlobalScopes()->sole()->published_at)->not->toBeNull();
    $this->assertDatabaseHas('payouts', ['payee_id' => $scenario['payee']->id, 'memo' => 'Bank transfer']);
});

test('a payout above the payable amount is refused in the dialog', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit("/periods/{$scenario['period']->id}")
        ->click('[aria-label="Book payout to Example Payee"]')
        ->fill('[role="dialog"] input[name="total"]', '80')
        ->press('Book payout')
        ->assertSeeIn('[role="dialog"]', 'exceeds the 50.00 payable');

    $this->assertDatabaseCount('payouts', 0);
});

test('the transaction form adapts to the type and currency', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit('/transactions/create')
        ->assertSee('New income')
        ->assertMissing('#payee_id')
        ->assertMissing('#conversion_rate')
        ->select('type', 'transfer')
        ->assertSee('New transfer')
        ->assertVisible('#payee_id')
        ->assertMissing('#track_id')
        ->fill('currency', 'usd')
        ->assertVisible('#conversion_rate')
        ->assertSee('Rate to EUR');
});

test('an expense entered in the form lowers what the payee is owed', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit('/transactions/create?type=expense')
        ->assertSee('Enter the cost as a positive amount')
        ->assertVisible('#refund')
        ->fill('total', '40')
        ->fill('timestamp', '2026-02-01')
        ->fill('paid_timestamp', '2026-02-01')
        ->select('category', 'Mastering')
        ->click('#release_id')
        ->waitForText('Example Release')
        ->click('Example Release')
        ->click('#track_id')
        ->waitForText('Example Track')
        ->click('Example Track')
        ->press('Create expense')
        ->assertSee('Transaction created.')
        ->assertPathIs('/transactions');

    expect(Transaction::query()->withoutGlobalScopes()->where('type', 'expense')->sole()->track_id)->toBe($scenario['track']->id);

    visit("/periods/{$scenario['period']->id}")->assertSee('€30.00');
});

test('the transaction filters narrow the list and the totals', function () {
    $scenario = royaltyScenario();
    Transaction::factory()->create(['label_id' => $scenario['label']->id, 'type' => 'expense', 'total' => '-40', 'category' => 'Mastering', 'release_id' => $scenario['release']->id]);
    $this->actingAs($scenario['admin']);

    visit('/transactions')
        ->assertSee('Matching 2')
        ->select('select[aria-label="Type"]', 'expense')
        ->assertSee('Matching 1')
        ->assertSee('Mastering')
        ->assertDontSee('Example Store')
        // The choice lists are not fetched again with a filter; the ones the page has stay.
        ->click('#filter-release')
        ->waitForText('EX001')
        ->click('[role="listbox"] [role="option"]:nth-child(2)')
        ->assertSee('Matching 1')
        ->click('Clear filters')
        ->assertSee('Matching 2');
});

test('saving an expense started from a release returns to that release', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit("/releases/{$scenario['release']->id}")
        ->click('New expense')
        ->assertSee('Enter the cost as a positive amount')
        ->fill('total', '250')
        ->select('category', 'Mastering')
        ->press('Create expense')
        ->assertSee('Transaction created.')
        ->assertPathIs("/releases/{$scenario['release']->id}")
        ->assertNoJavaScriptErrors();

    $expense = Transaction::query()->withoutGlobalScopes()->where('type', 'expense')->sole();

    expect($expense->total)->toBe('-250.000000000000000')
        ->and($expense->release_id)->toBe($scenario['release']->id);

    visit("/transactions/{$expense->id}/edit")
        ->assertValue('#total', '250')
        ->assertNoJavaScriptErrors();
});
