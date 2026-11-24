<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\DataImport;
use App\Models\Item;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Transaction;
use App\Services\Royalties\Tools\PpdTool;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    toolScenario();
});

/**
 * @param  array<string, mixed>  $filters
 * @return array<string, mixed>
 */
function ppdReport(array $filters = [], string $from = '2026-01-01', string $to = '2026-03-31'): array
{
    return app(PpdTool::class)->report(test()->label->refresh(), $from, $to, $filters);
}

function bookPpd(array $input = [])
{
    return test()->post(route('royalty-tools.ppd.store'), ['from' => '2026-01-01', 'to' => '2026-03-31', 'paid_on' => '2026-03-31', ...$input]);
}

test('quantities are multiplied by the item rate, else the track rate, else the release rate, and summed by release and track', function () {
    $this->release->update(['ppd_rate' => '2']);
    $rated = toolTrack('Rated Track', ['ppd_rate' => '3']);
    $plain = toolTrack('Plain Track');
    $item = Item::factory()->create(['label_id' => $this->label->id, 'release_id' => $this->release->id, 'track_id' => $rated->id, 'name' => 'Example Vinyl', 'ppd_rate' => '5']);

    toolSale(['quantity' => 10]);
    toolSale(['track_id' => $rated->id, 'quantity' => 4]);
    toolSale(['track_id' => $rated->id, 'item_id' => $item->id, 'quantity' => 2]);
    toolSale(['track_id' => $plain->id, 'quantity' => 5]);

    $report = ppdReport();
    $rows = collect($report['rows'])->keyBy(fn (array $row): string => "{$row['track_name']}|{$row['rate_source']}");
    $groups = collect($report['groups'])->keyBy(fn (array $group): string => (string) $group['track_name']);

    expect($rows['|release'])->toMatchArray(['quantity' => '10.000000000000000', 'rate' => '2.000000000000000', 'amount' => '20.000000000000000'])
        ->and($rows['Rated Track|track'])->toMatchArray(['quantity' => '4.000000000000000', 'rate' => '3.000000000000000', 'amount' => '12.000000000000000'])
        ->and($rows['Rated Track|item'])->toMatchArray(['quantity' => '2.000000000000000', 'rate' => '5.000000000000000', 'amount' => '10.000000000000000', 'item_name' => 'Example Vinyl'])
        ->and($rows['Plain Track|release'])->toMatchArray(['quantity' => '5.000000000000000', 'rate' => '2.000000000000000', 'amount' => '10.000000000000000'])
        ->and($groups)->toHaveCount(3)
        ->and($groups['']['amount'])->toBe('20.000000000000000')
        ->and($groups['Rated Track'])->toMatchArray(['quantity' => '6.000000000000000', 'amount' => '22.000000000000000'])
        ->and($groups['Plain Track']['amount'])->toBe('10.000000000000000')
        ->and($report['total'])->toBe('52.000000000000000')
        ->and($report['quantity'])->toBe('21.000000000000000');
});

test('a sale takes part only when its item, track or release has a rate', function () {
    $rated = toolTrack('Rated Track', ['ppd_rate' => '3']);
    $plain = toolTrack('Plain Track');
    toolSale(['track_id' => $rated->id, 'quantity' => 4]);
    toolSale(['track_id' => $plain->id, 'quantity' => 100]);
    toolSale(['quantity' => 100]);

    $report = ppdReport();

    expect($report['rows'])->toHaveCount(1)
        ->and($report['total'])->toBe('12.000000000000000');
});

test('income of every format counts, expenses and sales outside the range do not', function () {
    $this->release->update(['ppd_rate' => '1']);
    toolSale(['format' => 'LP', 'quantity' => 1, 'paid_timestamp' => '2026-01-01']);
    toolSale(['format' => 'Subscription Audio Stream', 'quantity' => 2, 'paid_timestamp' => '2026-03-31']);
    toolSale(['format' => null, 'quantity' => 4]);
    toolSale(['quantity' => 100, 'paid_timestamp' => '2025-12-31']);
    toolSale(['quantity' => 100, 'paid_timestamp' => '2026-04-01']);
    toolSale(['quantity' => 100, 'type' => 'expense', 'total' => '-5']);

    expect(ppdReport()['quantity'])->toBe('7.000000000000000');
});

test('returns reduce the units unless the label ignores negative quantities', function () {
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10]);
    toolSale(['quantity' => -4, 'total' => '-4']);

    expect(ppdReport()['total'])->toBe('12.000000000000000');

    $this->label->update(['royalty_tool_settings' => ['ignore_negative_quantities' => true]]);

    expect(ppdReport()['total'])->toBe('20.000000000000000');
});

test('transactions without a quantity are listed for fixing and not counted', function () {
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10]);
    $missing = toolSale(['quantity' => null, 'memo' => 'Example wholesale invoice', 'paid_timestamp' => '2026-02-01']);
    toolSale(['quantity' => 3, 'converted_total' => null]);

    $report = ppdReport();

    expect($report['total'])->toBe('20.000000000000000')
        ->and($report['without_quantity_count'])->toBe(1)
        ->and($report['without_quantity'])->toBe([['id' => $missing->id, 'paid_on' => '2026-02-01', 'description' => 'Example wholesale invoice', 'release_name' => 'Example Release', 'release_id' => $this->release->id]])
        ->and($report['unconverted'])->toBe(1);
});

test('a rated item that belongs to no release is reported and not counted', function () {
    $item = Item::factory()->create(['label_id' => $this->label->id, 'ppd_rate' => '5']);
    toolSale(['release_id' => null, 'item_id' => $item->id, 'quantity' => 2]);

    $report = ppdReport();

    expect($report['rows'])->toBe([])
        ->and($report['unassigned'])->toBe(1);
});

test('the report can be narrowed to an artist, a release or a format', function () {
    $artist = Artist::factory()->create(['label_id' => $this->label->id]);
    $this->release->update(['ppd_rate' => '1', 'artist_id' => $artist->id]);
    $other = Release::factory()->create(['label_id' => $this->label->id, 'ppd_rate' => '1', 'artist_id' => Artist::factory()->create(['label_id' => $this->label->id])->id]);

    toolSale(['format' => 'LP', 'quantity' => 1]);
    toolSale(['format' => 'CD', 'quantity' => 2]);
    toolSale(['release_id' => $other->id, 'format' => 'LP', 'quantity' => 4]);

    expect(ppdReport()['quantity'])->toBe('7.000000000000000')
        ->and(ppdReport(['artist_id' => $artist->id])['quantity'])->toBe('3.000000000000000')
        ->and(ppdReport(['release_id' => $other->id])['quantity'])->toBe('4.000000000000000')
        ->and(ppdReport(['format' => 'LP'])['quantity'])->toBe('5.000000000000000')
        ->and(ppdReport(['format' => 'LP', 'release_id' => $this->release->id])['quantity'])->toBe('1.000000000000000');
});

test('the page shows the report for the chosen range and filters', function () {
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['format' => 'LP', 'quantity' => 10]);
    toolSale(['format' => 'CD', 'quantity' => 5]);

    $this->get(route('royalty-tools.ppd.index', ['from' => '2026-01-01', 'to' => '2026-03-31', 'format' => 'LP']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('royalty-tools/ppd')
            ->where('filters', ['from' => '2026-01-01', 'to' => '2026-03-31', 'artist_id' => null, 'release_id' => null, 'format' => 'LP'])
            ->where('report.total', '20.000000000000000')
            ->where('report.rows.0.release_name', 'Example Release'));
});

test('creating transactions books the total and its offset as income for each release and track', function () {
    $this->release->update(['ppd_rate' => '2']);
    $track = toolTrack('Example Track', ['ppd_rate' => '3']);
    toolSale(['quantity' => 10]);
    toolSale(['track_id' => $track->id, 'quantity' => 4]);

    bookPpd(['paid_on' => '2026-03-20'])
        ->assertRedirect(route('royalty-tools.ppd.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', '4 transactions created.');

    $import = DataImport::query()->where('importer', 'ppd-tool')->sole();
    $forTrack = toolBooked(trackId: $track->id);

    expect($import->name)->toBe('PPD 2026-01-01 – 2026-03-31')
        ->and($import->options['total'])->toBe('32.000000000000000')
        ->and((string) $import->total)->toBe('0.000000000000000')
        ->and(Transaction::query()->where('data_import_id', $import->id)->count())->toBe(4);

    expect($forTrack['ppd-income'])
        ->type->value->toBe('income')
        ->converted_total->toBe('12.000000000000000')
        ->category->toBe('PPD')
        ->importer_source->toBe('ppd-tool')
        ->and($forTrack['ppd-income']->paid_timestamp->toDateString())->toBe('2026-03-20')
        ->and($forTrack['ppd-income']->item->name)->toBe('Example Track [PPD Income]')
        ->and($forTrack['ppd-income']->item->contract_id)->toBeNull();

    expect($forTrack['ppd-offset'])
        ->type->value->toBe('income')
        ->converted_total->toBe('-12.000000000000000')
        ->category->toBe('PPD')
        ->and($forTrack['ppd-offset']->item->name)->toBe('Example Track [PPD Income Offset]');

    $releaseLevel = Transaction::query()->where('data_import_id', $import->id)->whereNull('track_id')->with('item')->get()->keyBy('item.tool_role');

    expect($releaseLevel['ppd-income']->converted_total)->toBe('20.000000000000000')
        ->and($releaseLevel['ppd-income']->item->name)->toBe('Example Release [PPD Income]')
        ->and($releaseLevel['ppd-offset']->converted_total)->toBe('-20.000000000000000')
        ->and($releaseLevel['ppd-offset']->item->name)->toBe('Example Release [PPD Income Offset]');
});

test('the two lines cancel out until a contract is set on the PPD income item', function () {
    toolTrack('Example Track');
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10, 'total' => '100']);

    bookPpd()->assertSessionHasNoErrors();

    expect(toolPayeeTotals($this->firstQuarter, $this->artist)['payee_share'])->toBe('50.000000000000000');
});

test('a contract on the PPD income item pays its split of the PPD total', function () {
    toolTrack('Example Track');
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10, 'total' => '100']);
    bookPpd()->assertSessionHasNoErrors();

    $owed = Payee::factory()->create(['label_id' => $this->label->id, 'name' => 'Example Licensor']);
    $deal = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $deal->id, 'payee_id' => $owed->id, 'split_amount' => '0.15']);
    $nobody = Contract::factory()->create(['label_id' => $this->label->id, 'name' => 'Label bears it']);

    $this->put(route('items.update', toolItem('ppd-income')), ['contract_id' => $deal->id, 'release_id' => $this->release->id])->assertSessionHasNoErrors();
    $this->put(route('items.update', toolItem('ppd-offset')), ['contract_id' => $nobody->id, 'release_id' => $this->release->id])->assertSessionHasNoErrors();

    // PPD total 10 × 2 = 20; 15% of it is 3. The offset sits on a contract
    // without splits, so the label bears it and the artist keeps 50.
    expect(toolPayeeTotals($this->firstQuarter, $owed)['payee_share'])->toBe('3.000000000000000')
        ->and(toolPayeeTotals($this->firstQuarter, $this->artist)['payee_share'])->toBe('50.000000000000000');
});

test('the offset left on the track contract charges its payees their split', function () {
    toolTrack('Example Track');
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10, 'total' => '100']);
    bookPpd()->assertSessionHasNoErrors();

    $owed = Payee::factory()->create(['label_id' => $this->label->id]);
    $deal = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $deal->id, 'payee_id' => $owed->id, 'split_amount' => '0.15']);
    $this->put(route('items.update', toolItem('ppd-income')), ['contract_id' => $deal->id, 'release_id' => $this->release->id])->assertSessionHasNoErrors();

    // (100 − 20) × 50% = 40 for the artist.
    expect(toolPayeeTotals($this->firstQuarter, $this->artist)['payee_share'])->toBe('40.000000000000000');
});

test('what the tool booked is not counted when the tool runs again', function () {
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10]);
    bookPpd()->assertSessionHasNoErrors();
    Transaction::query()->where('importer_source', 'ppd-tool')->update(['quantity' => 99]);

    expect(ppdReport()['total'])->toBe('20.000000000000000');
});

test('an overlapping range needs a confirmation to be booked again', function () {
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10]);
    bookPpd()->assertSessionHasNoErrors();

    $this->get(route('royalty-tools.ppd.index', ['from' => '2026-02-01', 'to' => '2026-02-28']))
        ->assertInertia(fn (Assert $page) => $page->has('overlaps', 1)->where('runs.data.0.total', '20.000000000000000'));

    bookPpd(['from' => '2026-02-01', 'to' => '2026-02-28'])->assertSessionHasErrors('confirm_overlap');
    expect(DataImport::query()->count())->toBe(1);

    bookPpd(['from' => '2026-02-01', 'to' => '2026-02-28', 'confirm_overlap' => true])->assertSessionHasNoErrors();
    expect(DataImport::query()->count())->toBe(2);
});

test('a mechanicals run over the same range is not an overlap for PPD', function () {
    DataImport::factory()->create(['label_id' => $this->label->id, 'importer' => 'mechanicals-tool', 'options' => ['from' => '2026-01-01', 'to' => '2026-03-31']]);

    $this->get(route('royalty-tools.ppd.index', ['from' => '2026-01-01', 'to' => '2026-03-31']))
        ->assertInertia(fn (Assert $page) => $page->where('overlaps', [])->where('runs.data', []));
});

test('deleting the import of a run removes its transactions', function () {
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10]);
    bookPpd()->assertSessionHasNoErrors();

    $this->delete(route('data-imports.destroy', DataImport::query()->sole()));

    expect(Transaction::query()->where('importer_source', 'ppd-tool')->count())->toBe(0);
    $this->get(route('royalty-tools.ppd.index'))->assertInertia(fn (Assert $page) => $page->where('runs.data', []));
});

test('a run is refused when its input is invalid', function (array $input, string $field) {
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10]);

    bookPpd($input)->assertSessionHasErrors($field);

    $this->assertDatabaseCount('data_imports', 0);
})->with([
    'no end' => [['to' => ''], 'to'],
    'an end before the start' => [['to' => '2025-12-31'], 'to'],
    'no paid date' => [['paid_on' => ''], 'paid_on'],
    'a release of another label' => [fn () => ['release_id' => Release::factory()->create()->id], 'release_id'],
    'an artist of another label' => [fn () => ['artist_id' => Artist::factory()->create()->id], 'artist_id'],
]);

test('the tool does not exist for a label that has PPD switched off', function () {
    $this->label->update(['accounts_ppd' => false]);

    $this->get(route('royalty-tools.ppd.index'))->assertNotFound();
    bookPpd()->assertNotFound();
    $this->get(route('royalty-tools.rates.edit', ['ppd', $this->release]))->assertNotFound();
});

test('a payee cannot use the tool', function () {
    $this->actingAs(toolPayeeUser($this->label));

    $this->get(route('royalty-tools.ppd.index'))->assertRedirect(route('portal.index'));
    bookPpd()->assertForbidden();
});

test('sales and runs of another label stay out of the report', function () {
    $foreign = Release::factory()->create(['ppd_rate' => '2']);
    Transaction::factory()->create(['label_id' => $foreign->label_id, 'release_id' => $foreign->id, 'quantity' => 50, 'paid_timestamp' => '2026-02-15']);
    DataImport::factory()->create(['label_id' => $foreign->label_id, 'importer' => 'ppd-tool', 'options' => ['from' => '2026-01-01', 'to' => '2026-03-31']]);

    $this->get(route('royalty-tools.ppd.index', ['from' => '2026-01-01', 'to' => '2026-03-31']))
        ->assertInertia(fn (Assert $page) => $page->where('report.rows', [])->where('runs.data', [])->where('overlaps', []));

    $other = Label::factory()->create(['accounts_ppd' => true]);
    $this->release->update(['ppd_rate' => '2']);
    toolSale(['quantity' => 10]);

    $this->actingAs(labelAdmin($other))
        ->get(route('royalty-tools.ppd.index', ['from' => '2026-01-01', 'to' => '2026-03-31']))
        ->assertInertia(fn (Assert $page) => $page->where('report.rows', []));
});
