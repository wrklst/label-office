<?php

use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Territory;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Royalties\ProfitabilityReport;

beforeEach(function () {
    $this->label = currentLabel();
    $this->payee = Payee::factory()->create(['label_id' => $this->label->id]);
    $this->contract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $this->contract->id, 'payee_id' => $this->payee->id, 'split_amount' => '0.5']);
    $this->release = Release::factory()->create(['label_id' => $this->label->id, 'name' => 'Example Release']);
    Track::factory()->create(['label_id' => $this->label->id, 'release_id' => $this->release->id, 'contract_id' => $this->contract->id]);
});

/**
 * Book a transaction on the release under the 50% contract.
 *
 * @param  array<string, mixed>  $attributes
 */
function booked(array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'label_id' => test()->label->id,
        'release_id' => test()->release->id,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $filters
 * @return array<string, mixed>
 */
function profitability(array $filters = []): array
{
    return app(ProfitabilityReport::class)->forLabel(test()->label, ...$filters);
}

/**
 * The row of one release, or of the transactions without a release.
 *
 * @param  array<string, mixed>  $filters
 * @return array<string, mixed>|null
 */
function profitabilityRow(?Release $release = null, array $filters = [], bool $unassigned = false): ?array
{
    $releaseId = $unassigned ? null : ($release ?? test()->release)->id;

    return collect(profitability($filters)['rows'])->first(fn (array $row): bool => $row['release_id'] === $releaseId);
}

test('a release shows what came in, what payees earn and what the label is left with', function () {
    $artist = Artist::factory()->create(['label_id' => $this->label->id, 'name' => 'Example Artist']);
    $this->release->update(['artist_id' => $artist->id, 'catalog_number' => 'EX001']);
    booked(['total' => '200']);
    booked(['type' => 'expense', 'total' => '-40', 'category' => 'Mastering']);

    expect(profitabilityRow())->toBe([
        'release_id' => $this->release->id,
        'release_name' => 'Example Release',
        'catalog_number' => 'EX001',
        'artist_name' => 'Example Artist',
        'artist_id' => $artist->id,
        'income' => '200.000000000000000',
        'expense' => '-40.000000000000000',
        'net' => '160.000000000000000',
        'payee_share' => '80.000000000000000',
        'label_costs' => '0.000000000000000',
        'label_result' => '80.000000000000000',
        'margin' => '0.400000000000000',
        'advances' => '0.000000000000000',
    ]);
});

test('a one-time payment lowers the label result but not the payee share', function () {
    booked(['total' => '100']);
    booked(['type' => 'expense', 'total' => '-30', 'category' => 'Remix', 'recoupable' => false, 'payee_id' => $this->payee->id]);

    $row = profitabilityRow();

    expect($row['expense'])->toBe('0.000000000000000')
        ->and($row['net'])->toBe('100.000000000000000')
        ->and($row['payee_share'])->toBe('50.000000000000000')
        ->and($row['label_costs'])->toBe('-30.000000000000000')
        ->and($row['label_result'])->toBe('20.000000000000000')
        ->and($row['margin'])->toBe('0.200000000000000');
});

test('each transaction is split by its most specific contract', function () {
    $remixContract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $remixContract->id, 'payee_id' => Payee::factory()->create(['label_id' => $this->label->id])->id, 'split_amount' => '0.25']);
    $override = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $override->id, 'payee_id' => $this->payee->id, 'split_amount' => '1']);
    $track = Track::factory()->create(['label_id' => $this->label->id, 'release_id' => $this->release->id, 'contract_id' => $remixContract->id]);

    // The sale of the release is divided between its two tracks: the original and the remix.

    booked(['total' => '100']);
    booked(['total' => '100', 'track_id' => $track->id]);
    booked(['total' => '10', 'track_id' => $track->id, 'contract_id' => $override->id]);

    $row = profitabilityRow();

    expect($row['income'])->toBe('210.000000000000000')
        ->and($row['payee_share'])->toBe('72.500000000000000')
        ->and($row['label_result'])->toBe('137.500000000000000');
});

test('a prorated contract pays the splits of its child contracts by weight', function () {
    $secondContract = Contract::factory()->create(['label_id' => $this->label->id]);
    ContractSplit::factory()->create(['contract_id' => $secondContract->id, 'payee_id' => Payee::factory()->create(['label_id' => $this->label->id])->id, 'split_amount' => '1']);
    $compilation = Contract::factory()->create(['label_id' => $this->label->id]);
    $compilation->children()->attach([$this->contract->id => ['weight' => 3], $secondContract->id => ['weight' => 1]]);
    Track::query()->where('release_id', $this->release->id)->update(['contract_id' => $compilation->id]);

    booked(['total' => '100']);

    $row = profitabilityRow();

    expect($row['payee_share'])->toBe('62.500000000000000')
        ->and($row['label_result'])->toBe('37.500000000000000');
});

test('a territory split only takes a share of income from its countries', function () {
    $territory = Territory::factory()->create(['label_id' => $this->label->id, 'countries' => ['DE']]);
    $this->contract->splits()->update(['territory_id' => $territory->id]);

    booked(['total' => '100', 'region' => 'DE']);
    booked(['total' => '100', 'region' => 'US']);
    booked(['total' => '100', 'region' => null]);

    $row = profitabilityRow();

    expect($row['payee_share'])->toBe('50.000000000000000')
        ->and($row['label_result'])->toBe('250.000000000000000');
});

test('the label keeps everything of a release whose tracks have no contract', function () {
    Track::query()->where('release_id', $this->release->id)->update(['contract_id' => null]);

    booked(['total' => '100']);
    booked(['type' => 'expense', 'total' => '-20']);

    $row = profitabilityRow();

    expect($row['payee_share'])->toBe('0.000000000000000')
        ->and($row['label_result'])->toBe('80.000000000000000')
        ->and($row['margin'])->toBe('0.800000000000000');
});

test('a release that only cost money has a negative result and no margin', function () {
    booked(['type' => 'expense', 'total' => '-40']);
    booked(['type' => 'expense', 'total' => '-30', 'recoupable' => false]);

    $row = profitabilityRow();

    expect($row['payee_share'])->toBe('-20.000000000000000')
        ->and($row['label_result'])->toBe('-50.000000000000000')
        ->and($row['margin'])->toBeNull();
});

test('the date range applies to the sale date or the paid date', function (array $filters, ?string $income) {
    booked(['total' => '100', 'timestamp' => '2026-01-31', 'paid_timestamp' => '2026-02-15']);
    booked(['total' => '40', 'timestamp' => '2026-03-10', 'paid_timestamp' => '2026-04-10']);

    expect(profitabilityRow(filters: $filters)['income'] ?? null)->toBe($income);
})->with([
    'all time' => [[], '140.000000000000000'],
    'sold on the first and last day' => [['from' => '2026-01-31', 'to' => '2026-01-31'], '100.000000000000000'],
    'sold from' => [['from' => '2026-02-01'], '40.000000000000000'],
    'sold until' => [['to' => '2026-03-09'], '100.000000000000000'],
    'nothing sold in the range' => [['from' => '2026-02-01', 'to' => '2026-02-28'], null],
    'paid in the range' => [['from' => '2026-02-01', 'to' => '2026-02-28', 'byPaidDate' => true], '100.000000000000000'],
    'paid from' => [['from' => '2026-04-10', 'byPaidDate' => true], '40.000000000000000'],
]);

test('income without a release and label costs without a release get their own rows after the releases', function () {
    booked(['total' => '10']);
    booked(['total' => '300', 'release_id' => null]);
    booked(['type' => 'expense', 'total' => '-25', 'release_id' => null, 'recoupable' => false]);

    $rows = profitability()['rows'];

    expect(array_column($rows, 'release_name'))->toBe(['Example Release', 'Not assigned to a release', 'Overheads'])
        ->and($rows[1]['release_id'])->toBeNull()
        ->and($rows[1]['income'])->toBe('300.000000000000000')
        ->and($rows[1]['payee_share'])->toBe('0.000000000000000')
        ->and($rows[1]['label_result'])->toBe('300.000000000000000')
        ->and($rows[2]['label_costs'])->toBe('-25.000000000000000')
        ->and($rows[2]['label_result'])->toBe('-25.000000000000000');
});

test('releases are listed by label result, best first, with totals over all rows', function () {
    $loss = Release::factory()->create(['label_id' => $this->label->id, 'name' => 'Example Loss']);
    $hit = Release::factory()->create(['label_id' => $this->label->id, 'name' => 'Example Hit']);
    Release::factory()->create(['label_id' => $this->label->id, 'name' => 'Example Silence']);

    booked(['total' => '100']);
    booked(['total' => '400', 'release_id' => $hit->id]);
    booked(['type' => 'expense', 'total' => '-60', 'release_id' => $loss->id, 'recoupable' => false]);

    $report = profitability();

    expect(array_column($report['rows'], 'release_name'))->toBe(['Example Hit', 'Example Release', 'Example Loss'])
        ->and($report['totals'])->toBe([
            'income' => '500.000000000000000',
            'expense' => '0.000000000000000',
            'net' => '500.000000000000000',
            'payee_share' => '50.000000000000000',
            'label_costs' => '-60.000000000000000',
            'label_result' => '390.000000000000000',
            'margin' => '0.780000000000000',
            'advances' => '0.000000000000000',
        ]);
});

test('advances given are listed but are not part of the label result', function () {
    booked(['total' => '100']);
    booked(['type' => 'transfer', 'total' => '-500', 'payee_id' => $this->payee->id, 'category' => 'Advance']);
    booked(['type' => 'transfer', 'total' => '-50', 'payee_id' => $this->payee->id, 'category' => 'Advance']);
    booked(['type' => 'transfer', 'total' => '120', 'payee_id' => $this->payee->id, 'category' => 'Artist Payback to Label']);
    booked(['type' => 'transfer', 'total' => '-70', 'payee_id' => $this->payee->id, 'release_id' => null]);
    booked(['type' => 'transfer', 'total' => '-9', 'payee_id' => $this->payee->id, 'timestamp' => '2027-01-01', 'paid_timestamp' => '2027-01-01']);

    $report = profitability(['to' => '2026-12-31']);

    expect($report['rows'][0]['advances'])->toBe('-550.000000000000000')
        ->and($report['rows'][0]['label_result'])->toBe('50.000000000000000')
        ->and($report['rows'][1]['release_name'])->toBe('Not assigned to a release')
        ->and($report['rows'][1]['advances'])->toBe('-70.000000000000000')
        ->and($report['rows'][1]['label_result'])->toBe('0.000000000000000')
        ->and($report['totals']['advances'])->toBe('-620.000000000000000')
        ->and($report['totals']['label_result'])->toBe('50.000000000000000');
});

test('transactions without a converted amount are left out and counted', function () {
    booked(['total' => '100']);
    booked(['total' => '80', 'currency' => 'USD', 'conversion_rate' => null, 'converted_total' => null]);
    booked(['type' => 'expense', 'total' => '-10', 'currency' => 'USD', 'conversion_rate' => null, 'converted_total' => null, 'timestamp' => '2027-01-01']);

    $report = profitability(['to' => '2026-12-31']);

    expect($report['unconverted'])->toBe(1)
        ->and($report['rows'][0]['income'])->toBe('100.000000000000000')
        ->and(profitability()['unconverted'])->toBe(2);
});

test('the report can be limited to the releases of one artist', function () {
    $artist = Artist::factory()->create(['label_id' => $this->label->id]);
    $theirs = Release::factory()->create(['label_id' => $this->label->id, 'artist_id' => $artist->id, 'name' => 'Example Album']);

    booked(['total' => '100']);
    booked(['total' => '40', 'release_id' => $theirs->id]);
    booked(['total' => '5', 'release_id' => null]);
    booked(['total' => '80', 'release_id' => null, 'currency' => 'USD', 'conversion_rate' => null, 'converted_total' => null]);

    $report = profitability(['artistId' => $artist->id]);

    expect(array_column($report['rows'], 'release_name'))->toBe(['Example Album'])
        ->and($report['totals']['income'])->toBe('40.000000000000000')
        ->and($report['unconverted'])->toBe(0);
});

test('the report only covers the given label', function () {
    $other = Label::factory()->create(['currency' => 'USD']);
    $otherContract = Contract::factory()->create(['label_id' => $other->id]);
    ContractSplit::factory()->create(['contract_id' => $otherContract->id, 'payee_id' => Payee::factory()->create(['label_id' => $other->id])->id, 'split_amount' => '1']);
    $otherRelease = Release::factory()->create(['label_id' => $other->id, 'name' => 'Foreign Release']);
    Track::factory()->create(['label_id' => $other->id, 'release_id' => $otherRelease->id, 'contract_id' => $otherContract->id]);
    Transaction::factory()->create(['label_id' => $other->id, 'release_id' => $otherRelease->id, 'total' => '999']);
    Transaction::factory()->create(['label_id' => $other->id, 'total' => '5', 'currency' => 'GBP', 'conversion_rate' => null, 'converted_total' => null]);
    booked(['total' => '100']);

    $report = profitability();
    $foreign = app(ProfitabilityReport::class)->forLabel($other);

    expect($report['currency'])->toBe($this->label->currency)
        ->and(array_column($report['rows'], 'release_name'))->toBe(['Example Release'])
        ->and($report['totals']['income'])->toBe('100.000000000000000')
        ->and($report['unconverted'])->toBe(0)
        ->and($foreign['currency'])->toBe('USD')
        ->and(array_column($foreign['rows'], 'release_name'))->toBe(['Foreign Release'])
        ->and($foreign['totals']['label_result'])->toBe('0.000000000000000')
        ->and($foreign['unconverted'])->toBe(1);
});

test('a label without transactions has an empty report', function () {
    $report = profitability();

    expect($report['rows'])->toBe([])
        ->and($report['totals']['label_result'])->toBe('0.000000000000000')
        ->and($report['totals']['margin'])->toBeNull()
        ->and($report['unconverted'])->toBe(0);
});
