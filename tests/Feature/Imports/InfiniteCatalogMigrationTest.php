<?php

use App\Models\Label;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Royalties\RoyaltyCalculator;
use App\Support\CurrentLabel;
use Illuminate\Support\Facades\File;

/**
 * Write a minimal account export in the shape of the Infinite Catalog API.
 */
function infiniteCatalogExport(): string
{
    $directory = sys_get_temp_dir().'/ic-export-'.uniqid();
    $url = fn (string $entity, int $id): string => "https://api.infinitecatalog.com/api/v1/{$entity}/{$id}/";
    File::makeDirectory($directory);

    $files = [
        'customer' => ['currency' => 'EUR'],
        'regions' => [['id' => 70, 'alpha_2' => 'ES']],
        'artists' => [['id' => 1, 'name' => 'Example Artist', 'discogs_search_name' => '', 'alias_of' => null]],
        'payees' => [['id' => 5, 'name' => 'Example Artist', 'tags' => []]],
        'ledgers' => [],
        'contracts' => [['id' => 9, 'name' => 'Example Release', 'memo' => '', 'has_child_contracts' => false]],
        'splits' => [['contract' => $url('contracts', 9), 'payee' => $url('payees', 5), 'ledger' => null, 'region' => null, 'split_amount' => '0.500000000']],
        'releases' => [['id' => 3, 'name' => 'Example Release', 'artist' => $url('artists', 1), 'contract' => $url('contracts', 9), 'catalog_number' => 'ex013', 'upc' => '4000000000017']],
        'tracks' => [['id' => 4, 'release' => $url('releases', 3), 'artist' => $url('artists', 1), 'contract' => null, 'name' => 'Example Track', 'isrc' => 'ZZTST2600001', 'iswc' => '', 'track_number' => 1, 'mechanicals_rate' => null]],
        'data-imports' => [['id' => 7, 'display_name' => 'Zebralution 2024Q1', 'name' => '', 'status' => 'imported', 'import_file_path' => 'csv_imports/q1.csv', 'default_timestamp' => null, 'default_paid_timestamp' => null, 'replace_timestamp' => false, 'replace_paid_timestamp' => false, 'tags' => null, 'source_display' => 'Zebralution', 'importer_name' => 'Zebralution', 'created_at' => '2024-05-01T13:00:00-04:00']],
        'items' => [['id' => 6, 'release' => $url('releases', 3), 'track' => $url('tracks', 4), 'contract' => null, 'csv_import' => $url('data-imports', 7), 'formatted_name' => 'Example Track', 'name' => '', 'track_name' => 'Example Track', 'catalog_number' => 'ex013', 'isrc' => 'ZZTST2600001', 'iswc' => '', 'upc' => '4000000000017']],
        'periods' => [['id' => 8, 'name' => '2024Q1', 'start' => '2024-01-01', 'end' => '2024-03-31']],
        'payouts' => [['payee' => $url('payees', 5), 'period' => $url('periods', 8), 'total' => 20.5, 'timestamp' => '2024-12-02T06:26:40-05:00', 'name' => 'Example Artist 2024Q1 Payout']],
    ];

    foreach ($files as $name => $contents) {
        File::put("{$directory}/{$name}.json", json_encode($contents));
    }

    $transaction = fn (array $attributes): string => json_encode([
        'timestamp' => '2024-01-31T18:00:00-05:00', 'paid_timestamp' => '2024-03-31T18:00:00-04:00', 'external_id' => '', 'source' => 'Zebralution',
        'importer_source' => 'Zebralution', 'contract' => null, 'payee' => null, 'item' => 6, 'release' => 3, 'track' => 4, 'data_import' => 7,
        'format' => 'Streaming/Other', 'type' => 'income', 'region' => 70, 'currency' => 'EUR', 'conversion_rate' => null, 'converted_total' => null,
        'quantity' => '10.00', 'price' => null, 'category' => 'Distribution', 'memo' => '', 'provider' => 'Spotify', 'published' => true, 'billable' => true,
        ...$attributes,
    ]);

    File::put("{$directory}/transactions.ndjson", implode("\n", [
        $transaction(['id' => 100, 'total' => '100.000000000000000', 'paid_timestamp' => '2024-02-14T18:00:00-05:00']),
        $transaction(['id' => 101, 'total' => '40.000000000000000']),
        $transaction(['id' => 102, 'type' => 'transfer', 'payee' => 5, 'track' => null, 'currency' => 'GBP', 'total' => '-25.000000000000000', 'conversion_rate' => 1.16082, 'converted_total' => '-29.020567456567819', 'paid_timestamp' => '2024-02-01T00:00:00-05:00']),
        $transaction(['id' => 103, 'type' => 'transfer', 'payee' => 5, 'importer_source' => 'infinite_catalog_payout', 'category' => 'Payout', 'total' => '-20.500000000000000']),
    ])."\n");

    return $directory;
}

test('an infinite catalog export is migrated with its catalog, contracts and payouts', function () {
    $label = Label::factory()->create(['slug' => 'example']);
    $directory = infiniteCatalogExport();

    $this->artisan('royalties:import-infinite-catalog', ['directory' => $directory, '--label' => 'example'])->assertSuccessful();

    app(CurrentLabel::class)->set($label);
    $payee = collect(app(RoyaltyCalculator::class)->forPeriod(Period::query()->sole())['payees'])->sole();

    // The old system's contract of the release is on the tracks that had none.
    expect(Track::query()->sole()->contract->splits()->sole()->split_amount)->toBe('0.500000000')
        ->and(Transaction::query()->count())->toBe(3)
        ->and(Payout::query()->sole()->paid_on->toDateString())->toBe('2024-12-02')
        ->and($payee['totals']['income'])->toBe('100.000000000000000')
        ->and($payee['totals']['payee_share'])->toBe('50.000000000000000')
        ->and(bcadd($payee['totals']['transfers'], '0', 9))->toBe('-29.020567456')
        ->and($payee['totals']['total_paid'])->toBe('20.500000000000000');
});

test('a midnight timestamp is read as the day it was entered for, not the day before', function () {
    Label::factory()->create(['slug' => 'example']);

    $this->artisan('royalties:import-infinite-catalog', ['directory' => infiniteCatalogExport(), '--label' => 'example'])->assertSuccessful();

    $lateMarch = Transaction::query()->where('total', 40)->sole();

    expect($lateMarch->timestamp->toDateString())->toBe('2024-02-01')
        ->and($lateMarch->paid_timestamp->toDateString())->toBe('2024-04-01');
});

test('a label that already holds data is only replaced on request', function () {
    $label = Label::factory()->create(['slug' => 'example']);
    Release::factory()->create(['label_id' => $label->id, 'name' => 'Existing']);
    $directory = infiniteCatalogExport();

    $this->artisan('royalties:import-infinite-catalog', ['directory' => $directory, '--label' => 'example'])->assertFailed();
    $this->assertDatabaseHas('releases', ['name' => 'Existing']);

    $this->artisan('royalties:import-infinite-catalog', ['directory' => $directory, '--label' => 'example', '--fresh' => true])->assertSuccessful();
    $this->assertDatabaseMissing('releases', ['name' => 'Existing']);
});

test('another label is left untouched by a fresh migration', function () {
    Label::factory()->create(['slug' => 'example']);
    $other = Release::factory()->create(['name' => 'Other label release']);

    $this->artisan('royalties:import-infinite-catalog', ['directory' => infiniteCatalogExport(), '--label' => 'example', '--fresh' => true])->assertSuccessful();

    $this->assertDatabaseHas('releases', ['id' => $other->id]);
});
