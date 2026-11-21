<?php

use App\Models\Artist;
use App\Models\DataImport;
use App\Models\Label;
use App\Models\Period;
use App\Models\PlayCount;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Reports\IncomeReport;
use Inertia\Testing\AssertableInertia as Assert;

test('a platform is one whatever statements call it, with what a stream and a download paid', function () {
    $label = Label::factory()->create();
    $shared = ['label_id' => $label->id];
    Transaction::factory()->create([...$shared, 'retailer' => 'Apple', 'format' => 'Subscription Audio Stream', 'quantity' => 100, 'total' => '0.50', 'timestamp' => '2026-01-31']);
    Transaction::factory()->create([...$shared, 'retailer' => 'Apple/iTunes', 'format' => 'Download Track', 'quantity' => 2, 'total' => '1.60', 'timestamp' => '2026-03-31']);
    Transaction::factory()->create([...$shared, 'retailer' => 'Example Shop', 'format' => 'LP', 'quantity' => 1, 'total' => '20', 'timestamp' => '2026-01-10', 'region' => 'DE']);
    Transaction::factory()->create([...$shared, 'type' => 'expense', 'retailer' => 'Apple', 'total' => '-5']);
    Transaction::factory()->create([...$shared, 'retailer' => 'Apple', 'total' => '7', 'converted_total' => null]);

    $report = IncomeReport::of(Transaction::query()->where('label_id', $label->id));

    expect($report['months'])->toBe(['2026-01', '2026-02', '2026-03'])
        ->and($report['total'])->toBe(22.1)
        ->and(array_column($report['platforms'], 'key'))->toBe(['x-example-shop', 'apple_music'])
        ->and($report['platforms'][0])->toMatchArray(['name' => 'Example Shop', 'total' => 20.0, 'streams' => 0.0, 'other_units' => 1.0])
        ->and($report['platforms'][1])->toMatchArray([
            'name' => 'Apple Music', 'total' => 2.1, 'streams' => 100.0, 'stream_total' => 0.5, 'downloads' => 2.0, 'download_total' => 1.6, 'plays' => null,
        ])
        ->and($report['platforms'][1]['series'])->toBe(['total' => [0.5, 0.0, 1.6], 'streams' => [100.0, 0.0, 0.0], 'stream_total' => [0.5, 0.0, 0.0]])
        ->and(array_column($report['kinds'], 'total', 'key'))->toBe(['streams' => 0.5, 'downloads' => 1.6, 'physical' => 20.0])
        ->and($report['regions'][0])->toBe(['label' => 'DE', 'total' => 20.0, 'units' => 1.0]);
});

test('a platform keeps its place, and so its colour, in every selection', function () {
    $label = Label::factory()->create();
    Transaction::factory()->create(['label_id' => $label->id, 'retailer' => 'Tidal', 'total' => '9']);
    Transaction::factory()->create(['label_id' => $label->id, 'retailer' => 'Spotify', 'total' => '1']);
    Transaction::factory()->create(['label_id' => $label->id, 'retailer' => 'Example Shop', 'total' => '5']);

    $all = collect(IncomeReport::of(Transaction::query()->where('label_id', $label->id))['platforms'])->pluck('position', 'key');
    $one = collect(IncomeReport::of(Transaction::query()->where('label_id', $label->id)->where('retailer', '!=', 'Tidal'))['platforms'])->pluck('position', 'key');

    expect($all['spotify'])->toBeLessThan($all['tidal'])
        ->and($one['spotify'])->toBe($all['spotify'])
        ->and($one['x-example-shop'])->toBe($all['x-example-shop']);
});

test('what a platform counted itself is held against the streams on statements for the same months', function () {
    $label = Label::factory()->create();
    $streams = ['label_id' => $label->id, 'retailer' => 'Spotify', 'format' => 'Subscription Audio Stream', 'total' => '1'];
    Transaction::factory()->create([...$streams, 'quantity' => 300, 'timestamp' => '2026-01-31']);
    Transaction::factory()->create([...$streams, 'quantity' => 100, 'timestamp' => '2026-02-28']);
    Transaction::factory()->create([...$streams, 'quantity' => 50, 'timestamp' => '2026-04-30']);
    PlayCount::factory()->create(['label_id' => $label->id, 'platform' => 'spotify', 'plays' => 350, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31']);
    PlayCount::factory()->create(['label_id' => $label->id, 'platform' => 'spotify', 'plays' => 150, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28']);
    PlayCount::factory()->create(['label_id' => $label->id, 'platform' => 'tidal', 'plays' => 12]);

    $platforms = collect(IncomeReport::of(Transaction::query()->where('label_id', $label->id), PlayCount::query()->where('label_id', $label->id))['platforms'])->keyBy('key');

    expect($platforms['spotify'])->toMatchArray(['plays' => 500, 'plays_from' => '2026-01-01', 'plays_to' => '2026-02-28', 'reported_streams' => 400.0, 'streams' => 450.0])
        ->and($platforms['tidal'])->toMatchArray(['name' => 'Tidal', 'plays' => 12, 'reported_streams' => 0.0, 'total' => 0.0]);
});

test('what earned most comes with its last twelve months and whether it still moves', function () {
    $label = Label::factory()->create();
    $moving = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release']);
    $quiet = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Back Catalogue']);
    $stream = ['label_id' => $label->id, 'release_id' => $moving->id, 'format' => 'Subscription Audio Stream'];
    Transaction::factory()->create([...$stream, 'quantity' => 1000, 'total' => '4', 'timestamp' => '2026-06-30']);
    Transaction::factory()->create([...$stream, 'quantity' => 500, 'total' => '2', 'timestamp' => '2026-02-28']);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $quiet->id, 'format' => 'LP', 'total' => '30', 'timestamp' => '2025-08-15']);
    Transaction::factory()->create(['label_id' => $label->id, 'total' => '99', 'timestamp' => '2026-06-01']);

    $movers = IncomeReport::movers(Transaction::query()->where('label_id', $label->id), 'release_id');

    expect($movers['months'])->toHaveCount(12)->and($movers['months'][0])->toBe('2025-07')->and($movers['months'][11])->toBe('2026-06')
        ->and(array_column($movers['rows'], 'label'))->toBe(['Example Back Catalogue', 'Example Release'])
        ->and($movers['rows'][0])->toMatchArray(['id' => $quiet->id, 'total' => 30.0, 'recent' => 0.0, 'before' => 0.0, 'streams' => 0.0])
        ->and($movers['rows'][0]['series'][1])->toBe(30.0)
        ->and($movers['rows'][1])->toMatchArray(['total' => 6.0, 'recent' => 4.0, 'before' => 2.0, 'streams' => 1500.0, 'stream_total' => 6.0]);
});

test('every page with income carries the same report', function (Closure $page) {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    $release = Release::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'artist_id' => $artist->id]);
    $import = DataImport::factory()->create(['label_id' => $label->id]);
    $period = Period::factory()->create(['label_id' => $label->id, 'starts_on' => now()->startOfMonth(), 'ends_on' => now()->endOfMonth()]);
    Transaction::factory()->create([
        'label_id' => $label->id, 'release_id' => $release->id, 'track_id' => $track->id, 'data_import_id' => $import->id,
        'retailer' => 'Spotify', 'format' => 'Subscription Audio Stream', 'quantity' => 200, 'total' => '1',
        'timestamp' => now()->toDateString(), 'paid_timestamp' => now()->toDateString(),
    ]);

    $this->actingAs(labelAdmin($label))->get($page(compact('artist', 'release', 'track', 'import', 'period')))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->has('report.months', 1)
            ->where('report.platforms.0.key', 'spotify')
            ->where('report.platforms.0.streams', 200)
            ->where('report.platforms.0.stream_total', 1)
            ->where('report.kinds.0.key', 'streams'));
})->with([
    'dashboard' => [fn (array $records) => route('dashboard')],
    'release' => [fn (array $records) => route('releases.show', $records['release'])],
    'track' => [fn (array $records) => route('tracks.show', $records['track'])],
    'artist' => [fn (array $records) => route('artists.show', $records['artist'])],
    'import' => [fn (array $records) => route('data-imports.show', $records['import'])],
    'period' => [fn (array $records) => route('periods.show', $records['period'])],
]);

test('the release page holds the counted plays of its tracks against its income, and no other release\'s', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id]);
    $other = Track::factory()->create(['label_id' => $label->id, 'release_id' => Release::factory()->create(['label_id' => $label->id])->id]);
    PlayCount::factory()->create(['label_id' => $label->id, 'track_id' => $track->id, 'platform' => 'spotify', 'plays' => 350]);
    PlayCount::factory()->create(['label_id' => $label->id, 'track_id' => $other->id, 'platform' => 'spotify', 'plays' => 9000]);

    $this->actingAs(labelAdmin($label))->get(route('releases.show', $release))
        ->assertInertia(fn (Assert $page) => $page->where('report.platforms.0.key', 'spotify')->where('report.platforms.0.plays', 350));
});
