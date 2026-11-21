<?php

use App\Enums\LabelRole;
use App\Enums\RoyaltyTool;
use App\Models\Airplay;
use App\Models\AirplaySearch;
use App\Models\Artist;
use App\Models\DataImport;
use App\Models\Item;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\Period;
use App\Models\PlayCount;
use App\Models\Receipt;
use App\Models\RecurringExpense;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Royalties\Tools\ToolRuns;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every list a user reads has pages of its own, sorts across all of them
 * and narrows by its filters. Fifty rows fill a page; the fifty-first
 * proves there is a second one.
 */
test('booked costs, recurring costs and waiting receipts each have pages of their own, and the sort covers every page', function () {
    $label = Label::factory()->create(['currency' => 'EUR']);
    foreach (range(1, 51) as $number) {
        Transaction::factory()->create([
            'label_id' => $label->id, 'release_id' => null, 'type' => 'expense', 'recoupable' => false, 'currency' => 'EUR',
            'total' => (string) (-$number), 'converted_total' => (string) (-$number), 'paid_timestamp' => '2026-03-15', 'source' => "Cost {$number}", 'memo' => $number === 51 ? 'Example Needle' : null,
        ]);
    }
    RecurringExpense::factory()->count(26)->create(['label_id' => $label->id, 'starts_on' => '2030-01-15']);
    Receipt::factory()->count(26)->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('label-costs.index'))->assertInertia(fn (Assert $page) => $page
        ->has('costs.data', 50)->where('costs.total', 51)
        ->has('recurring.data', 25)->where('recurring.total', 26)
        ->has('receipts.data', 25)->where('receipts.total', 26));

    $this->actingAs($admin)->get(route('label-costs.index', ['costs_page' => 2, 'recurring_page' => 2, 'receipts_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('costs.data', 1)->has('recurring.data', 1)->has('receipts.data', 1));

    // The cost of 51 is the largest outflow, however it is on the last page by date.
    $this->actingAs($admin)->get(route('label-costs.index', ['costs_sort' => 'amount', 'costs_direction' => 'asc']))->assertInertia(fn (Assert $page) => $page
        ->where('costs.data.0.source', 'Cost 51')
        ->where('filters.costs_sort', 'amount'));

    $this->actingAs($admin)->get(route('label-costs.index', ['search' => 'Needle']))->assertInertia(fn (Assert $page) => $page
        ->has('costs.data', 1)->where('costs.data.0.source', 'Cost 51'));
});

test('recurring costs sort by their next payment across pages', function () {
    $label = Label::factory()->create();
    RecurringExpense::factory()->count(25)->create(['label_id' => $label->id, 'starts_on' => '2031-02-01']);
    $early = RecurringExpense::factory()->create(['label_id' => $label->id, 'name' => 'Early', 'starts_on' => '2030-01-01', 'interval' => 'yearly']);

    $this->actingAs(labelAdmin($label))->get(route('label-costs.index', ['recurring_sort' => 'next_due_on', 'recurring_direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page->where('recurring.data.0.id', $early->id));
});

test('a payee page pages and sorts the transfers, payouts and statements of the payee', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $period = Period::factory()->create(['label_id' => $label->id]);
    foreach (range(1, 26) as $number) {
        Transaction::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'type' => 'transfer', 'total' => (string) $number, 'converted_total' => (string) $number, 'paid_timestamp' => '2026-03-15']);
    }
    foreach (range(1, 26) as $number) {
        Payout::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id, 'total' => (string) $number]);
        Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => Period::factory()->create(['label_id' => $label->id, 'starts_on' => now()->subYears($number)->toDateString(), 'ends_on' => now()->subYears($number)->addDay()->toDateString()]), 'payable' => (string) $number]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('payees.show', $payee))->assertInertia(fn (Assert $page) => $page
        ->has('transfers.data', 25)->where('transfers.total', 26)
        ->has('payouts.data', 25)->where('payouts.total', 26)
        ->has('statements.data', 25)->where('statements.total', 26));

    $this->actingAs($admin)->get(route('payees.show', [$payee, 'transfers_page' => 2, 'payouts_page' => 2, 'statements_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('transfers.data', 1)->has('payouts.data', 1)->has('statements.data', 1));

    $this->actingAs($admin)->get(route('payees.show', [$payee, 'transfers_sort' => 'amount', 'transfers_direction' => 'desc', 'payouts_sort' => 'total', 'payouts_direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transfers.data.0.total', fn (string $total): bool => (float) $total === 26.0)
            ->where('payouts.data.0.total', fn (string $total): bool => (float) $total === 26.0));
});

test('the files of play counts page, sort across pages, and say how many names are unmatched', function () {
    $label = Label::factory()->create();
    foreach (range(1, 51) as $number) {
        PlayCount::factory()->create(['label_id' => $label->id, 'batch' => sprintf('batch-%02d', $number), 'source_file' => "file-{$number}.csv", 'plays' => $number, 'track_id' => null, 'track_name' => 'Example Track']);
    }
    foreach (range(1, 31) as $number) {
        PlayCount::factory()->create(['label_id' => $label->id, 'batch' => 'batch-01', 'source_file' => 'file-1.csv', 'plays' => 0, 'track_id' => null, 'track_name' => sprintf('Unknown %02d', $number)]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('play-counts.index'))->assertInertia(fn (Assert $page) => $page
        ->has('files.data', 50)->where('files.total', 51));
    $this->actingAs($admin)->get(route('play-counts.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('files.data', 1));
    $this->actingAs($admin)->get(route('play-counts.index', ['sort' => 'plays', 'direction' => 'desc']))->assertInertia(fn (Assert $page) => $page
        ->where('files.data.0.source_file', 'file-51.csv')
        ->where('filters', ['sort' => 'plays', 'direction' => 'desc']));
    $this->actingAs($admin)->get(route('play-counts.index', ['sort' => 'file', 'direction' => 'asc']))->assertInertia(fn (Assert $page) => $page
        ->where('files.data.0.source_file', 'file-1.csv')
        ->where('files.data.0.unmatched.total', 32)
        ->has('files.data.0.unmatched.names', 30));
});

test('files of play counts that tie on the sorted column keep one order across pages, in a fixed number of queries', function () {
    $label = Label::factory()->create();
    foreach (range(1, 60) as $number) {
        PlayCount::factory()->create(['label_id' => $label->id, 'batch' => sprintf('batch-%02d', $number), 'source_file' => 'same.csv', 'plays' => 5, 'platform' => 'spotify']);
    }
    $admin = labelAdmin($label);
    $batches = [];

    foreach ([1, 2] as $page) {
        $this->actingAs($admin)->get(route('play-counts.index', ['sort' => 'plays', 'direction' => 'asc', 'page' => $page]))->assertInertia(function (Assert $inertia) use (&$batches) {
            $batches = [...$batches, ...collect($inertia->toArray()['props']['files']['data'])->pluck('batch')->all()];
        });
    }

    expect($batches)->toHaveCount(60)->and(array_unique($batches))->toHaveCount(60);

    DB::enableQueryLog();
    $this->get(route('play-counts.index', ['sort' => 'plays']));
    $perRow = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'play_counts'))->count();
    DB::disableQueryLog();

    expect($perRow)->toBeLessThan(25);
});

test('a page beyond the last one shows the last page, whatever the list calls its page parameter', function () {
    $label = Label::factory()->create();
    foreach (range(1, 51) as $number) {
        Transaction::factory()->create(['label_id' => $label->id, 'type' => 'expense', 'recoupable' => false, 'release_id' => null, 'total' => '-1']);
        PlayCount::factory()->create(['label_id' => $label->id, 'batch' => sprintf('batch-%02d', $number)]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('play-counts.index', ['page' => 9, 'sort' => 'plays']))->assertRedirect(route('play-counts.index', ['page' => 2, 'sort' => 'plays']));
    $this->actingAs($admin)->get(route('label-costs.index', ['costs_page' => 9]))->assertRedirect(route('label-costs.index', ['costs_page' => 2]));
    $this->actingAs($admin)->get(route('label-costs.index', ['costs_page' => 2]))->assertOk();
    $this->actingAs($admin)->get(route('transactions.index', ['page' => 9]))->assertRedirect(route('transactions.index'));
    // An empty list has no last page to go to.
    $this->actingAs($admin)->get(route('releases.index', ['page' => 3]))->assertOk();
});

test('a track page pages and sorts its airplay and its research log', function () {
    $label = Label::factory()->create();
    $track = Track::factory()->create(['label_id' => $label->id]);
    foreach (range(1, 26) as $number) {
        Airplay::factory()->create(['label_id' => $label->id, 'track_id' => $track->id, 'station' => sprintf('Station %02d', $number), 'amount' => (string) $number]);
    }
    foreach (range(1, 26) as $number) {
        AirplaySearch::query()->create(['label_id' => $label->id, 'track_id' => $track->id, 'query' => "query {$number}", 'succeeded' => true, 'results' => []]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('tracks.show', $track))->assertInertia(fn (Assert $page) => $page
        ->has('airplays.data', 25)->where('airplays.total', 26)->where('airplayTotals.plays', 26)
        ->has('searches.data', 25)->where('searches.total', 26));
    $this->actingAs($admin)->get(route('tracks.show', [$track, 'airplays_page' => 2, 'searches_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('airplays.data', 1)->has('searches.data', 1));
    $this->actingAs($admin)->get(route('tracks.show', [$track, 'airplays_sort' => 'amount', 'airplays_direction' => 'desc', 'searches_sort' => 'query', 'searches_direction' => 'asc']))->assertInertia(fn (Assert $page) => $page
        ->where('airplays.data.0.station', 'Station 26')
        ->where('searches.data.0.query', 'query 1'));
});

test('an artist page pages and sorts its releases and tracks', function () {
    $label = Label::factory()->create();
    $artist = Artist::factory()->create(['label_id' => $label->id]);
    foreach (range(1, 26) as $number) {
        $release = Release::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id, 'name' => sprintf('Release %02d', $number)]);
        Track::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id, 'release_id' => $release->id, 'name' => sprintf('Track %02d', $number), 'isrc' => null]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('artists.show', $artist))->assertInertia(fn (Assert $page) => $page
        ->has('releases.data', 25)->where('releases.total', 26)->has('tracks.data', 25)->where('tracks.total', 26));
    $this->actingAs($admin)->get(route('artists.show', [$artist, 'releases_page' => 2, 'tracks_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('releases.data', 1)->has('tracks.data', 1));
    $this->actingAs($admin)->get(route('artists.show', [$artist, 'releases_sort' => 'name', 'releases_direction' => 'desc', 'tracks_sort' => 'name', 'tracks_direction' => 'desc']))->assertInertia(fn (Assert $page) => $page
        ->where('releases.data.0.name', 'Release 26')->where('tracks.data.0.name', 'Track 26'));
});

test('the items of a release have pages of their own and sort across them', function () {
    $label = Label::factory()->create();
    $release = Release::factory()->create(['label_id' => $label->id]);
    foreach (range(1, 25) as $number) {
        Item::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'name' => sprintf('Item %02d', $number)]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('releases.show', $release))->assertInertia(fn (Assert $page) => $page->has('items.data', 25)->where('items.total', 26));
    $this->actingAs($admin)->get(route('releases.show', [$release, 'items_page' => 2]))->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
    $this->actingAs($admin)->get(route('releases.show', [$release, 'items_sort' => 'name', 'items_direction' => 'desc']))->assertInertia(fn (Assert $page) => $page
        ->where('items.data.0.is_additionals', true));
});

test('the statements and payouts a payee sees have pages of their own and sort across them', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $period = Period::factory()->create(['label_id' => $label->id]);
    foreach (range(1, 26) as $number) {
        Statement::factory()->create([
            'label_id' => $label->id, 'payee_id' => $payee->id, 'published_at' => now(), 'payable' => (string) $number,
            'period_id' => Period::factory()->create(['label_id' => $label->id, 'starts_on' => now()->subYears($number)->toDateString(), 'ends_on' => now()->subYears($number)->addDay()->toDateString()]),
        ]);
        Payout::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id, 'total' => (string) $number]);
    }
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    $this->actingAs($user)->get(route('portal.index'))->assertInertia(fn (Assert $page) => $page
        ->has('groups.0.statements.data', 25)->where('groups.0.statements.total', 26)->has('groups.0.payouts.data', 25)->where('groups.0.payouts.total', 26));
    $this->actingAs($user)->get(route('portal.index', ["statements_{$label->id}_page" => 2, "payouts_{$label->id}_page" => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('groups.0.statements.data', 1)->has('groups.0.payouts.data', 1));
    $this->actingAs($user)->get(route('portal.index', ["statements_{$label->id}_sort" => 'payable', "statements_{$label->id}_direction" => 'desc', "payouts_{$label->id}_sort" => 'total', "payouts_{$label->id}_direction" => 'desc']))->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.statements.data.0.payable', fn (string $payable): bool => (float) $payable === 26.0)
        ->where('groups.0.payouts.data.0.total', fn (string $total): bool => (float) $total === 26.0));
});

test('the catalog manager pages the releases, and its search and filter cover every page', function () {
    $label = Label::factory()->create();
    foreach (range(1, 51) as $number) {
        Release::factory()->create(['label_id' => $label->id, 'name' => sprintf('Release %02d', $number), 'catalog_number' => sprintf('EX%03d', $number)]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('catalog.index'))->assertInertia(fn (Assert $page) => $page
        ->has('releases.data', 50)->where('releases.total', 51)->where('hasReleases', true));
    $this->actingAs($admin)->get(route('catalog.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('releases.data', 1));
    $this->actingAs($admin)->get(route('catalog.index', ['search' => 'Release 51']))->assertInertia(fn (Assert $page) => $page
        ->has('releases.data', 1)->where('releases.data.0.name', 'Release 51')->where('filters.search', 'Release 51'));
    $this->actingAs($admin)->get(route('catalog.index', ['without_contract' => 1]))->assertInertia(fn (Assert $page) => $page->where('releases.total', 51));
});

test('the earlier runs of a royalty tool have pages of their own and sort across them', function () {
    $label = Label::factory()->create(['accounts_mechanicals' => true]);
    foreach (range(1, 26) as $number) {
        DataImport::factory()->create([
            'label_id' => $label->id, 'importer' => RoyaltyTool::Mechanicals->importer(), 'status' => 'imported', 'name' => "Run {$number}",
            'total' => (string) $number, 'transactions_count' => $number, 'options' => ['from' => sprintf('2026-01-%02d', $number), 'to' => '2026-02-28', 'total' => (string) $number],
        ]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('royalty-tools.mechanicals.index'))->assertInertia(fn (Assert $page) => $page
        ->has('runs.data', 25)->where('runs.total', 26));
    $this->actingAs($admin)->get(route('royalty-tools.mechanicals.index', ['runs_page' => 2]))->assertInertia(fn (Assert $page) => $page->has('runs.data', 1));
    $this->actingAs($admin)->get(route('royalty-tools.mechanicals.index', ['runs_sort' => 'total', 'runs_direction' => 'desc']))->assertInertia(fn (Assert $page) => $page
        ->where('runs.data.0.name', 'Run 26')
        ->where('runFilters', ['runs_sort' => 'total', 'runs_direction' => 'desc']));
    // Overlaps are looked for in every run, not only on the page.
    expect(app(ToolRuns::class)->overlapping($label, RoyaltyTool::Mechanicals, '2026-01-26', '2026-01-26'))->toHaveCount(26);
});

test('the periods list has pages and sorts across them', function () {
    $label = Label::factory()->create();
    foreach (range(1, 51) as $number) {
        Period::factory()->create(['label_id' => $label->id, 'name' => sprintf('Period %02d', $number), 'starts_on' => now()->subYears($number)->toDateString(), 'ends_on' => now()->subYears($number)->addDay()->toDateString()]);
    }
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('periods.index'))->assertInertia(fn (Assert $page) => $page->has('periods.data', 50)->where('periods.total', 51));
    $this->actingAs($admin)->get(route('periods.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('periods.data', 1));
    $this->actingAs($admin)->get(route('periods.index', ['sort' => 'name', 'direction' => 'desc']))->assertInertia(fn (Assert $page) => $page->where('periods.data.0.name', 'Period 51'));
});

test('statements and payouts are narrowed by period and payee, and the filters stay in the pager links', function () {
    $label = Label::factory()->create();
    $first = Period::factory()->create(['label_id' => $label->id, 'name' => 'Period A']);
    $second = Period::factory()->create(['label_id' => $label->id, 'name' => 'Period B', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);
    $example = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Payee']);
    $other = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Other Payee']);
    Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $example->id, 'period_id' => $first->id]);
    Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $other->id, 'period_id' => $first->id]);
    Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $other->id, 'period_id' => $second->id]);
    Payout::factory()->create(['label_id' => $label->id, 'payee_id' => $example->id, 'period_id' => $first->id]);
    Payout::factory()->create(['label_id' => $label->id, 'payee_id' => $other->id, 'period_id' => $second->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('statements.index', ['payee' => $other->id]))->assertInertia(fn (Assert $page) => $page
        ->where('statements.total', 2)->where('filters.payee', $other->id)->has('payeeOptions', 2)->has('periodOptions', 2));
    $this->actingAs($admin)->get(route('statements.index', ['payee' => $other->id, 'period' => $second->id]))->assertInertia(fn (Assert $page) => $page->where('statements.total', 1));
    $this->actingAs($admin)->get(route('payouts.index', ['period' => $second->id]))->assertInertia(fn (Assert $page) => $page
        ->where('payouts.total', 1)->where('filters.period', $second->id));
    $this->actingAs($admin)->get(route('payouts.index', ['payee' => $example->id]))->assertInertia(fn (Assert $page) => $page
        ->where('payouts.total', 1)->where('payouts.data.0.payee', 'Example Payee'));
});

test('data imports are searched by name and narrowed by source and status', function () {
    $label = Label::factory()->create();
    DataImport::factory()->create(['label_id' => $label->id, 'name' => 'Example Statement', 'importer' => 'template', 'status' => 'imported']);
    DataImport::factory()->create(['label_id' => $label->id, 'name' => 'Sample Export', 'original_filename' => 'bandcamp-sales.csv', 'importer' => 'bandcamp', 'status' => 'review']);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('data-imports.index', ['search' => 'bandcamp-sales']))->assertInertia(fn (Assert $page) => $page
        ->where('imports.total', 1)->where('imports.data.0.name', 'Sample Export'));
    $this->actingAs($admin)->get(route('data-imports.index', ['source' => 'template']))->assertInertia(fn (Assert $page) => $page
        ->where('imports.total', 1)->where('imports.data.0.name', 'Example Statement')->has('sourceOptions', 2));
    $this->actingAs($admin)->get(route('data-imports.index', ['status' => 'review']))->assertInertia(fn (Assert $page) => $page
        ->where('imports.total', 1)->where('filters.status', 'review'));
    $this->actingAs($admin)->get(route('data-imports.index', ['status' => 'review', 'source' => 'template']))->assertInertia(fn (Assert $page) => $page->where('imports.total', 0));
});

test('airplay is searched, and narrowed by track and by where it is known from', function () {
    $label = Label::factory()->create();
    $track = Track::factory()->create(['label_id' => $label->id, 'name' => 'Example Track']);
    Airplay::factory()->create(['label_id' => $label->id, 'track_id' => $track->id, 'track_name' => 'Example Track', 'station' => 'Example Radio', 'origin' => 'statement']);
    Airplay::factory()->create(['label_id' => $label->id, 'track_name' => 'Sample Song', 'station' => 'Sample FM', 'origin' => 'manual']);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('airplays.index', ['search' => 'Sample FM']))->assertInertia(fn (Assert $page) => $page
        ->where('airplays.total', 1)->where('airplays.data.0.station', 'Sample FM'));
    $this->actingAs($admin)->get(route('airplays.index', ['origin' => 'statement']))->assertInertia(fn (Assert $page) => $page
        ->where('airplays.total', 1)->where('filters.origin', 'statement'));
    $this->actingAs($admin)->get(route('airplays.index', ['track' => $track->id]))->assertInertia(fn (Assert $page) => $page
        ->where('airplays.total', 1)->where('filters.track', $track->id)->has('tracks', 1));
    $this->actingAs($admin)->get(route('airplays.index', ['origin' => 'search']))->assertInertia(fn (Assert $page) => $page->where('airplays.total', 0));
});

test('the transaction list can be narrowed to a track from its filter, every import is offered and the dates are validated', function () {
    $label = Label::factory()->create();
    $track = Track::factory()->create(['label_id' => $label->id]);
    Transaction::factory()->create(['label_id' => $label->id, 'track_id' => $track->id]);
    Transaction::factory()->create(['label_id' => $label->id]);
    DataImport::factory()->count(101)->create(['label_id' => $label->id]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('transactions.index', ['track' => $track->id]))->assertInertia(fn (Assert $page) => $page
        ->where('transactions.total', 2 - 1)->where('filters.track', (string) $track->id)->has('trackOptions', 1)->has('importOptions', 101));
    // An inverted range matches nothing, and the fields show the range the figures are computed for.
    $this->actingAs($admin)->get(route('transactions.index', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-02-01')->where('filters.to', '2026-01-01')->where('transactions.total', 0));
});

test('the dashboard keeps its last twelve months, shows the dates it uses and shows an inverted range as typed', function () {
    $this->travelTo('2026-06-15');
    $label = Label::factory()->create();
    Transaction::factory()->create(['label_id' => $label->id, 'timestamp' => '2026-05-10', 'paid_timestamp' => '2026-05-10']);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['from' => '2025-07-01', 'to' => '2026-06-30', 'date' => null]));
    $this->actingAs($admin)->get(route('dashboard', ['from' => '2026-01-01', 'to' => '2026-03-31', 'date' => 'paid']))->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['from' => '2026-01-01', 'to' => '2026-03-31', 'date' => 'paid'])->where('totals.transactions', 0));
    $this->actingAs($admin)->get(route('dashboard', ['from' => '2026-03-01', 'to' => '2026-01-01']))->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['from' => '2026-03-01', 'to' => '2026-01-01', 'date' => null])->where('totals.transactions', 0));
    // Only an end date given: the start follows it, and the fields show both.
    $this->actingAs($admin)->get(route('dashboard', ['to' => '2024-12-31']))->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['from' => '2024-01-01', 'to' => '2024-12-31', 'date' => null]));
});

test('filtering, sorting and paging the transactions leave the choice lists out', function () {
    $label = Label::factory()->create();
    Transaction::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))->get(route('transactions.index'))->assertInertia(fn (Assert $page) => $page
        ->has('releaseOptions')->has('importOptions')
        ->reloadExcept(['releaseOptions', 'trackOptions', 'platformOptions', 'importOptions'], fn (Assert $reload) => $reload
            ->has('transactions.data', 1)->missing('releaseOptions')->missing('trackOptions')->missing('platformOptions')->missing('importOptions')));
});
