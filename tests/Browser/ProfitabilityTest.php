<?php

use App\Models\Transaction;

test('a one-time payment entered in the form lowers the label result of its release only', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit('/profitability')
        ->assertSeeIn('[data-slot="table-body"]', 'Example Release')
        ->assertSee('50% of income')
        ->assertNoJavaScriptErrors();

    visit('/transactions')
        ->click('New label cost')
        ->assertSee('New expense')
        ->assertNotChecked('#recoupable')
        ->assertSee('the label bears the cost alone')
        ->fill('total', '30')
        ->fill('timestamp', '2026-02-01')
        ->fill('paid_timestamp', '2026-02-01')
        ->select('category', 'Remix')
        ->click('#payee_id')
        ->waitForText('Example Payee')
        ->click('Example Payee')
        ->click('#release_id')
        ->waitForText('Example Release')
        ->click('Example Release')
        ->press('Create expense')
        ->assertSee('Transaction created.')
        ->assertSeeIn('[data-slot="table-body"]', 'label cost')
        ->assertNoJavaScriptErrors();

    $payment = Transaction::query()->withoutGlobalScopes()->where('type', 'expense')->sole();

    expect($payment->recoupable)->toBeFalse()
        ->and($payment->payee_id)->toBe($scenario['payee']->id)
        ->and($payment->release_id)->toBe($scenario['release']->id);

    visit('/profitability')
        ->assertSeeIn('[data-slot="table-body"]', '-€30.00')
        ->assertSeeIn('[data-slot="table-body"]', '€20.00')
        ->assertSee('20% of income')
        ->assertNoJavaScriptErrors();

    visit("/periods/{$scenario['period']->id}")
        ->assertSee('Label keeps €20.00 after €30.00 own costs')
        ->assertNoJavaScriptErrors();
});

test('the profitability filters narrow the report without leaving the page', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit('/profitability')
        ->assertDontSee('Clear filters')
        ->assertSeeIn('[data-slot="table-body"]', 'Example Release')
        ->fill('[aria-label="From"]', '2026-02-01')
        ->assertSee('Clear filters')
        ->assertSee('Nothing was booked in this range')
        ->select('[aria-label="Date used"]', 'paid')
        ->assertSeeIn('[data-slot="table-body"]', 'Example Release')
        ->assertPathIs('/profitability')
        ->assertNoJavaScriptErrors();
});

test('a recurring label cost is added in a dialog and shows up as overheads', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);

    visit('/label-costs')
        ->assertSee('No overheads booked yet')
        ->click('Recurring cost')
        ->fill('name', 'Example Subscription')
        ->fill('amount', '10')
        ->fill('starts_on', now()->subMonths(2)->toDateString())
        ->press('Create recurring cost')
        ->assertSee('3 past payments booked')
        ->assertSee('per month')
        ->assertSee('3×')
        ->assertNoJavaScriptErrors();

    visit('/profitability')->assertSee('Overheads')->assertNoJavaScriptErrors();
});

test('a booked overhead is edited in the dialog it was booked in, with its amount typed positive', function () {
    $scenario = royaltyScenario();
    $this->actingAs($scenario['admin']);
    $cost = Transaction::factory()->labelCost()->create([
        'label_id' => $scenario['label']->id, 'source' => 'Example Hosting', 'memo' => 'Example Hosting', 'total' => '-49.9', 'converted_total' => '-49.9',
        'currency' => 'EUR', 'timestamp' => '2026-03-01', 'paid_timestamp' => '2026-03-01',
    ]);

    visit('/label-costs')
        ->click('[aria-label="Edit cost"]')
        ->assertValue('[role="dialog"] input[name="amount"]', '49.9')
        ->fill('[role="dialog"] input[name="amount"]', '59.9')
        ->press('Save cost')
        ->assertSee('Cost saved.')
        ->assertPathIs('/label-costs')
        ->assertNoJavaScriptErrors();

    expect($cost->refresh()->total)->toBe('-59.900000000000000');
});
