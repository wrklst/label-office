<?php

use App\Enums\LabelRole;
use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A release under a 50% contract with 200 of income sold in January and
 * paid in February, a recoupable expense of 40 and a one-time payment of 30.
 *
 * @param  array<string, mixed>  $releaseAttributes
 */
function profitableRelease(Label $label, array $releaseAttributes = []): Release
{
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => Payee::factory()->create(['label_id' => $label->id])->id, 'split_amount' => '0.5']);
    $artist = Artist::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $release = Release::factory()->create(['label_id' => $label->id, 'artist_id' => $artist->id, 'contract_id' => $contract->id, 'name' => 'Example Release', 'catalog_number' => 'EX001', ...$releaseAttributes]);

    $dates = ['timestamp' => '2026-01-20', 'paid_timestamp' => '2026-02-15'];
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'total' => '200', ...$dates]);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'type' => 'expense', 'total' => '-40', ...$dates]);
    Transaction::factory()->labelCost()->create(['label_id' => $label->id, 'release_id' => $release->id, 'total' => '-30', ...$dates]);

    return $release;
}

test('guests are sent to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['profitability.index', 'profitability.download']);

test('a payee cannot see the profitability of the label', function () {
    $label = Label::factory()->create();
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => Payee::factory()->create(['label_id' => $label->id])->id]);

    $this->actingAs($user)->get(route('profitability.index'))->assertRedirect(route('portal.index'));
    $this->actingAs($user)->get(route('profitability.download'))->assertRedirect(route('portal.index'));
});

test('the profitability page shows every release of the label over all time', function () {
    $label = Label::factory()->create(['currency' => 'EUR']);
    $release = profitableRelease($label);
    Transaction::factory()->create(['label_id' => $label->id, 'total' => '80', 'currency' => 'USD', 'conversion_rate' => null, 'converted_total' => null]);
    profitableRelease(Label::factory()->create(), ['name' => 'Foreign Release']);

    $this->actingAs(labelAdmin($label))
        ->get(route('profitability.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('profitability/index')
            ->where('currency', 'EUR')
            ->has('rows', 1)
            ->where('rows.0', [
                'release_id' => $release->id,
                'release_name' => 'Example Release',
                'catalog_number' => 'EX001',
                'artist_name' => 'Example Artist',
                'artist_id' => $release->artist_id,
                'income' => '200.000000000000000',
                'expense' => '-40.000000000000000',
                'net' => '160.000000000000000',
                'payee_share' => '80.000000000000000',
                'label_costs' => '-30.000000000000000',
                'label_result' => '50.000000000000000',
                'margin' => '0.250000000000000',
                'advances' => '0.000000000000000',
            ])
            ->where('totals.label_result', '50.000000000000000')
            ->where('unconverted', 1)
            ->where('filters', ['from' => null, 'to' => null, 'date' => null, 'artist' => null])
            ->missing('artistOptions'));
});

test('the profitability page follows the filters', function (array $query, array $filters, int $rows) {
    $label = Label::factory()->create();
    $release = profitableRelease($label);
    $other = Artist::factory()->create(['label_id' => $label->id]);
    $ids = [':artist' => $release->artist_id, ':other' => $other->id];
    $resolve = fn (array $values): array => array_map(fn (mixed $value): mixed => $ids[$value] ?? $value, $values);

    $this->actingAs(labelAdmin($label))
        ->get(route('profitability.index', $resolve($query)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', $rows)
            ->where('filters', $resolve($filters)));
})->with([
    'sold in the range' => [['from' => '2026-01-01', 'to' => '2026-01-31'], ['from' => '2026-01-01', 'to' => '2026-01-31', 'date' => null, 'artist' => null], 1],
    'sold outside the range' => [['from' => '2026-02-01', 'to' => '2026-02-28'], ['from' => '2026-02-01', 'to' => '2026-02-28', 'date' => null, 'artist' => null], 0],
    'paid in the range' => [['from' => '2026-02-01', 'to' => '2026-02-28', 'date' => 'paid'], ['from' => '2026-02-01', 'to' => '2026-02-28', 'date' => 'paid', 'artist' => null], 1],
    'paid outside the range' => [['to' => '2026-01-31', 'date' => 'paid'], ['from' => null, 'to' => '2026-01-31', 'date' => 'paid', 'artist' => null], 0],
    'the artist of the release' => [['artist' => ':artist'], ['from' => null, 'to' => null, 'date' => null, 'artist' => ':artist'], 1],
    'another artist' => [['artist' => ':other'], ['from' => null, 'to' => null, 'date' => null, 'artist' => ':other'], 0],
]);

test('a range that ends before it starts matches nothing and is shown as typed', function () {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))->get(route('profitability.index', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-02-01')->where('filters.to', '2026-01-01'));
});

test('invalid profitability filters are rejected', function (string $route, array $query, string $field, string $message) {
    $label = Label::factory()->create();

    $this->actingAs(labelAdmin($label))
        ->get(route($route, $query))
        ->assertSessionHasErrors([$field => $message]);
})->with(['profitability.index', 'profitability.download'])->with([
    'from that is no date' => [['from' => 'someday'], 'from', 'The from field must be a valid date.'],
    'to that is no date' => [['to' => 'someday'], 'to', 'The to field must be a valid date.'],
    'unknown date kind' => [['date' => 'shipped'], 'date', 'The selected date is invalid.'],
    'artist that is no id' => [['artist' => 'someone'], 'artist', 'The artist field must be an integer.'],
]);

test('the csv download has the same rows as the page, the filters applied', function () {
    $label = Label::factory()->create();
    $release = profitableRelease($label);
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'type' => 'transfer', 'total' => '-500', 'payee_id' => $payee->id, 'timestamp' => '2026-01-10']);
    Transaction::factory()->create(['label_id' => $label->id, 'total' => '10.005', 'timestamp' => '2026-01-10']);
    Transaction::factory()->create(['label_id' => $label->id, 'total' => '999', 'timestamp' => '2026-03-01']);
    profitableRelease(Label::factory()->create(), ['name' => 'Foreign Release']);

    $response = $this->actingAs(labelAdmin($label))
        ->get(route('profitability.download', ['to' => '2026-01-31']))
        ->assertOk()
        ->assertDownload('profitability.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

    expect($response->streamedContent())->toBe(<<<'CSV'
        Release,"Catalog number",Artist,Income,"Recoupable expenses",Net,"Payee share","Label costs","Label result","Margin %","Advances given"
        "Example Release",EX001,"Example Artist",200.0000,-40.0000,160.0000,80.0000,-30.0000,50.0000,25.00,-500.0000
        "Not assigned to a release",,,10.0050,0.0000,10.0050,0.0000,0.0000,10.0050,100.00,0.0000
        Total,,,210.0050,-40.0000,170.0050,80.0000,-30.0000,60.0050,28.57,-500.0000

        CSV);
});

test('release and artist names cannot run as formulas in the csv', function () {
    $label = Label::factory()->create();
    $release = profitableRelease($label, ['name' => '=HYPERLINK("https://example.com")', 'catalog_number' => '@EX001']);
    $release->artist->update(['name' => '+Example Artist']);

    $content = $this->actingAs(labelAdmin($label))
        ->get(route('profitability.download'))
        ->streamedContent();

    expect($content)->toContain("\"'=HYPERLINK(\"\"https://example.com\"\")\",'@EX001,\"'+Example Artist\",200.0000,-40.0000");
});
