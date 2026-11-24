<?php

use App\Enums\ImportStatus;
use App\Models\Item;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Imports\ImportRunner;

test('a zebralution statement is imported with exact licensor amounts', function () {
    $label = currentLabel();
    $import = pendingImport($label, 'zebralution', 'zebralution_2026q3_sample.csv', ['default_paid_timestamp' => '2026-10-15']);

    $import = app(ImportRunner::class)->run($import, $label);

    expect($import->status)->toBe(ImportStatus::Imported)
        ->and($import->transactions_count)->toBe(67)
        ->and($import->errors_count)->toBe(0)
        ->and(bcadd($import->total, '0', 12))->toBe('0.449222148800')
        ->and(Release::count())->toBe(13);

    $first = Transaction::query()->orderBy('id')->first();

    expect($first->total)->toBe('0.001201168000000')
        ->and($first->timestamp->toDateString())->toBe('2026-02-28')
        ->and($first->paid_timestamp->toDateString())->toBe('2026-10-15')
        ->and($first->retailer)->toBe('Anghami')
        ->and($first->format)->toBe('Ad-Supported Audio Stream')
        ->and($first->usage_type)->toBe('Anghami Streaming Ad Supported')
        ->and($first->region)->toBe('LB')
        ->and($first->category)->toBe('Distribution')
        ->and($first->release->catalog_number)->toBe('EX001ep')
        ->and($first->track->isrc)->toBe('ZZTST2600001');
});

test('lines without a product are matched to the track by isrc', function () {
    $label = currentLabel();
    $import = pendingImport($label, 'zebralution', 'zebralution_2026q3_sample.csv', ['default_paid_timestamp' => '2026-10-15']);

    app(ImportRunner::class)->run($import, $label);

    expect(Transaction::query()->whereNull('release_id')->count())->toBe(0)
        ->and(Item::query()->whereNull('release_id')->count())->toBe(0);
});

test('the same isrc on two releases is one track on both releases', function () {
    $label = currentLabel();
    $import = pendingImport($label, 'zebralution', 'zebralution_2026q3_sample.csv', ['default_paid_timestamp' => '2026-10-15']);

    app(ImportRunner::class)->run($import, $label);

    expect(Track::query()->where('isrc', 'ZZTST2600018')->sole()->releases()->count())->toBe(2);
});

test('refund lines and unknown countries are kept as they are', function () {
    $label = currentLabel();
    $import = pendingImport($label, 'zebralution', 'zebralution_2026q3_sample.csv', ['default_paid_timestamp' => '2026-10-15']);

    app(ImportRunner::class)->run($import, $label);

    expect(Transaction::query()->where('total', '<', 0)->count())->toBeGreaterThan(0)
        ->and(Transaction::query()->whereNull('region')->count())->toBe(3)
        ->and(Transaction::query()->where('region', 'XK')->count())->toBe(1);
});

test('importing the same statement twice does not double the income', function () {
    $label = currentLabel();
    $runner = app(ImportRunner::class);

    $runner->run(pendingImport($label, 'zebralution', 'zebralution_2026q3_sample.csv', ['default_paid_timestamp' => '2026-10-15']), $label);
    $second = $runner->run(pendingImport($label, 'zebralution', 'zebralution_2026q3_sample.csv', ['default_paid_timestamp' => '2026-10-15']), $label);

    expect($second->duplicates_count)->toBe(67)
        ->and($second->transactions_count)->toBe(0)
        ->and(Transaction::count())->toBe(67);
});

test('a file in the wrong format fails without creating transactions', function () {
    $label = currentLabel();
    $import = pendingImport($label, 'zebralution', 'bandcamp_raw_sample.csv', ['default_paid_timestamp' => '2026-10-15']);

    $import = app(ImportRunner::class)->run($import, $label);

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->errors[0]['message'])->toContain('not a Zebralution')
        ->and(Transaction::count())->toBe(0);
});

test('deleting an import removes its transactions', function () {
    $label = currentLabel();
    $runner = app(ImportRunner::class);
    $import = $runner->run(pendingImport($label, 'zebralution', 'zebralution_2026q3_sample.csv', ['default_paid_timestamp' => '2026-10-15']), $label);

    $runner->delete($import);

    expect(Transaction::count())->toBe(0)
        ->and($import->refresh()->status)->toBe(ImportStatus::Deleted);
});
