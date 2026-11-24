<?php

use App\Models\DataImport;
use App\Models\Label;
use App\Models\Release;
use App\Models\Transaction;
use App\Services\Royalties\RoyaltyCalculator;
use App\Services\Royalties\Tools\ReservesTool;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    toolScenario();
    // Stored as a percentage: a quarter of physical income is held.
    $this->release->update(['reserves_rate' => '25']);
});

/**
 * @return array<string, mixed>
 */
function reservesReport(string $from = '2026-01-01', string $to = '2026-03-31'): array
{
    return app(ReservesTool::class)->report(test()->label, $from, $to);
}

function bookReserves(array $input = [])
{
    return test()->post(route('royalty-tools.reserves.store'), ['from' => '2026-01-01', 'to' => '2026-03-31', 'held_on' => '2026-03-31', 'liquidated_on' => '2026-06-30', ...$input]);
}

test('the reserve is the rate applied to physical income paid in the range, less returns', function () {
    toolSale(['format' => 'LP', 'total' => '300', 'paid_timestamp' => '2026-01-01']);
    toolSale(['format' => 'CD', 'total' => '100', 'paid_timestamp' => '2026-03-31']);
    toolSale(['format' => 'LP', 'total' => '-40', 'quantity' => -2]);
    toolSale(['format' => 'Download Album', 'total' => '1000']);
    toolSale(['format' => 'Subscription Audio Stream', 'total' => '1000']);
    toolSale(['format' => null, 'total' => '1000']);
    toolSale(['format' => 'LP', 'total' => '1000', 'paid_timestamp' => '2025-12-31']);
    toolSale(['format' => 'LP', 'total' => '1000', 'paid_timestamp' => '2026-04-01']);
    toolSale(['format' => 'LP', 'total' => '-1000', 'type' => 'expense']);

    $report = reservesReport();

    // (300 + 100 − 40) × 25% = 90.
    expect($report['rows'])->toBe([[
        'release_id' => $this->release->id, 'release_name' => 'Example Release',
        'base' => '360.000000000000000', 'rate' => '25.000000000000000', 'amount' => '90.000000000000000', 'held' => true,
    ]])
        ->and($report['total'])->toBe('90.000000000000000')
        ->and($report['base'])->toBe('360.000000000000000');
});

test('a release without a reserves rate holds nothing', function () {
    $without = Release::factory()->create(['label_id' => $this->label->id]);
    $zero = Release::factory()->create(['label_id' => $this->label->id, 'reserves_rate' => '0']);
    toolSale(['release_id' => $without->id, 'format' => 'LP', 'total' => '100']);
    toolSale(['release_id' => $zero->id, 'format' => 'LP', 'total' => '100']);

    expect(reservesReport()['rows'])->toBe([]);
});

test('nothing is held when returns outweighed sales in the range', function () {
    toolSale(['format' => 'LP', 'total' => '100']);
    toolSale(['format' => 'LP', 'total' => '-140', 'quantity' => -7]);

    $report = reservesReport();

    expect($report['rows'][0])->toMatchArray(['base' => '-40.000000000000000', 'held' => false])
        ->and($report['total'])->toBe('0.000000000000000');

    bookReserves()->assertSessionHasErrors(['from' => 'There is nothing to book for this range.']);
});

test('physical income without a converted amount is not counted and is reported', function () {
    toolSale(['format' => 'LP', 'total' => '100']);
    toolSale(['format' => 'LP', 'total' => '50', 'converted_total' => null]);

    $report = reservesReport();

    expect($report['total'])->toBe('25.000000000000000')
        ->and($report['unconverted'])->toBe(1);
});

test('creating transactions books the reserve as held and as liquidated on the two dates', function () {
    toolSale(['format' => 'LP', 'total' => '400']);

    bookReserves(['held_on' => '2026-03-15', 'liquidated_on' => '2026-09-15'])
        ->assertRedirect(route('royalty-tools.reserves.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', '2 transactions created.');

    $import = DataImport::query()->where('importer', 'reserves-tool')->sole();
    $booked = Transaction::query()->where('data_import_id', $import->id)->with('item')->get()->keyBy('format');

    expect($import->name)->toBe('Reserves 2026-01-01 – 2026-03-31')
        ->and($import->options)->toMatchArray(['held_on' => '2026-03-15', 'liquidated_on' => '2026-09-15', 'total' => '100.000000000000000'])
        ->and($booked)->toHaveCount(2);

    expect($booked['Reserves (Held)'])
        ->type->value->toBe('income')
        ->converted_total->toBe('-100.000000000000000')
        ->category->toBe('Reserves')
        ->release_id->toBe($this->release->id)
        ->track_id->toBeNull()
        ->importer_source->toBe('reserves-tool')
        ->memo->toBe('Reserve held: 25% of 400')
        ->and($booked['Reserves (Held)']->paid_timestamp->toDateString())->toBe('2026-03-15')
        ->and($booked['Reserves (Held)']->timestamp->toDateString())->toBe('2026-03-15');

    expect($booked['Reserves (Liquidated)'])
        ->type->value->toBe('income')
        ->converted_total->toBe('100.000000000000000')
        ->category->toBe('Reserves')
        ->and($booked['Reserves (Liquidated)']->paid_timestamp->toDateString())->toBe('2026-09-15')
        ->and($booked['Reserves (Liquidated)']->item_id)->toBe($booked['Reserves (Held)']->item_id)
        ->and($booked['Reserves (Held)']->item)
        ->name->toBe('Example Release [Reserves]')
        ->tool_role->toBe('reserves')
        ->contract_id->toBeNull()
        ->is_additionals->toBeFalse();
});

test('a reserve lowers the payee share in the period it is held and restores it in the period it is liquidated', function () {
    toolTrack('Example Track');
    toolSale(['format' => 'LP', 'total' => '400']);
    toolSale(['format' => 'Download Album', 'total' => '20', 'paid_timestamp' => '2026-05-01']);

    expect(toolPayeeTotals($this->firstQuarter, $this->artist)['payee_share'])->toBe('200.000000000000000');

    bookReserves(['held_on' => '2026-03-31', 'liquidated_on' => '2026-04-01'])->assertSessionHasNoErrors();

    $held = app(RoyaltyCalculator::class)->forPeriod($this->firstQuarter);
    $liquidated = app(RoyaltyCalculator::class)->forPeriod($this->secondQuarter);

    // Held: (400 − 100) × 50% = 150. Liquidated: (20 + 100) × 50% = 60.
    expect(toolPayeeTotals($this->firstQuarter, $this->artist)['payee_share'])->toBe('150.000000000000000')
        ->and($held['totals']['label_share'])->toBe('150.000000000000000')
        ->and(toolPayeeTotals($this->secondQuarter, $this->artist)['payee_share'])->toBe('60.000000000000000')
        ->and($liquidated['totals']['label_share'])->toBe('60.000000000000000')
        ->and(toolPayeeTotals($this->secondQuarter, $this->artist)['previous_balance'])->toBe('150.000000000000000');
});

test('the liquidation date cannot be before the held date', function (array $input, string $field) {
    toolSale(['format' => 'LP', 'total' => '400']);

    bookReserves($input)->assertSessionHasErrors($field);

    $this->assertDatabaseCount('data_imports', 0);
})->with([
    'liquidated before held' => [['held_on' => '2026-03-31', 'liquidated_on' => '2026-03-30'], 'liquidated_on'],
    'no held date' => [['held_on' => ''], 'held_on'],
    'no liquidation date' => [['liquidated_on' => ''], 'liquidated_on'],
    'an end before the start' => [['to' => '2025-12-31'], 'to'],
]);

test('a reserve can be held and liquidated on the same day', function () {
    toolTrack('Example Track');
    toolSale(['format' => 'LP', 'total' => '400']);

    bookReserves(['held_on' => '2026-03-31', 'liquidated_on' => '2026-03-31'])->assertSessionHasNoErrors();

    expect(toolPayeeTotals($this->firstQuarter, $this->artist)['payee_share'])->toBe('200.000000000000000');
});

test('the page shows what is held, liquidated so far and still held for each release', function () {
    toolSale(['format' => 'LP', 'total' => '400']);
    toolSale(['format' => 'LP', 'total' => '200', 'paid_timestamp' => '2026-05-01']);
    bookReserves(['held_on' => '2026-03-31', 'liquidated_on' => '2026-06-30'])->assertSessionHasNoErrors();
    bookReserves(['from' => '2026-04-01', 'to' => '2026-06-30', 'held_on' => '2026-06-30', 'liquidated_on' => '2026-12-31'])->assertSessionHasNoErrors();

    $this->travelTo('2026-06-29');
    expect(app(ReservesTool::class)->balances($this->label))->toBe([[
        'release_id' => $this->release->id, 'release_name' => 'Example Release',
        'held' => '150.000000000000000', 'liquidated' => '0.000000000000000', 'balance' => '150.000000000000000',
    ]]);

    $this->travelTo('2026-06-30');
    $this->get(route('royalty-tools.reserves.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('royalty-tools/reserves')
            ->where('report', null)
            ->where('balances.0.held', '150.000000000000000')
            ->where('balances.0.liquidated', '100.000000000000000')
            ->where('balances.0.balance', '50.000000000000000')
            ->has('runs.data', 2));

    expect(app(ReservesTool::class)->balances($this->label, CarbonImmutable::parse('2026-12-31'))[0]['balance'])->toBe('0.000000000000000');
});

test('booked reserves are not physical income for the next run', function () {
    toolSale(['format' => 'LP', 'total' => '400']);
    bookReserves(['held_on' => '2026-03-31', 'liquidated_on' => '2026-03-31'])->assertSessionHasNoErrors();
    Transaction::query()->where('importer_source', 'reserves-tool')->update(['format' => 'LP']);

    expect(reservesReport()['total'])->toBe('100.000000000000000');
});

test('an overlapping range is flagged and needs a confirmation to be booked again', function () {
    toolSale(['format' => 'LP', 'total' => '400']);
    bookReserves()->assertSessionHasNoErrors();

    $this->get(route('royalty-tools.reserves.index', ['from' => '2026-03-31', 'to' => '2026-04-30']))
        ->assertInertia(fn (Assert $page) => $page->has('overlaps', 1)->where('report.total', '0.000000000000000'));

    bookReserves()->assertSessionHasErrors('confirm_overlap');
    expect(Transaction::query()->where('importer_source', 'reserves-tool')->count())->toBe(2);

    bookReserves(['confirm_overlap' => '1'])->assertSessionHasNoErrors();
    expect(Transaction::query()->where('importer_source', 'reserves-tool')->count())->toBe(4);
});

test('deleting the import of a run removes both lines and the balance', function () {
    toolTrack('Example Track');
    toolSale(['format' => 'LP', 'total' => '400']);
    bookReserves()->assertSessionHasNoErrors();

    $this->delete(route('data-imports.destroy', DataImport::query()->sole()));

    expect(Transaction::query()->where('importer_source', 'reserves-tool')->count())->toBe(0)
        ->and(app(ReservesTool::class)->balances($this->label))->toBe([])
        ->and(toolPayeeTotals($this->firstQuarter, $this->artist)['payee_share'])->toBe('200.000000000000000');
});

test('the tool does not exist for a label that has reserves switched off', function () {
    $this->label->update(['accounts_reserves' => false]);

    $this->get(route('royalty-tools.reserves.index'))->assertNotFound();
    bookReserves()->assertNotFound();
});

test('a payee cannot use the tool', function () {
    $this->actingAs(toolPayeeUser($this->label));

    $this->get(route('royalty-tools.reserves.index'))->assertRedirect(route('portal.index'));
    bookReserves()->assertForbidden();
});

test('reserves of another label stay out of the page', function () {
    toolSale(['format' => 'LP', 'total' => '400']);
    bookReserves()->assertSessionHasNoErrors();

    $other = Label::factory()->create(['accounts_reserves' => true]);

    $this->actingAs(labelAdmin($other))
        ->get(route('royalty-tools.reserves.index', ['from' => '2026-01-01', 'to' => '2026-03-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('report.rows', [])->where('balances', [])->where('runs.data', [])->where('overlaps', []));
});
