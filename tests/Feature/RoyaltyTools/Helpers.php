<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Item;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Period;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Royalties\RoyaltyCalculator;

/**
 * A label with all three tools switched on, an admin signed in, and a
 * release whose tracks (made with toolTrack) are under a contract that
 * pays "Example Artist" half. The first quarter of
 * 2026 is the period most sales in these tests are paid in.
 *
 * @param  array<string, mixed>  $labelAttributes
 */
function toolScenario(array $labelAttributes = []): void
{
    $test = test();
    $test->label = currentLabel(['accounts_mechanicals' => true, 'accounts_ppd' => true, 'accounts_reserves' => true, ...$labelAttributes]);
    $test->admin = labelAdmin($test->label);
    $test->actingAs($test->admin);

    $test->artist = Payee::factory()->create(['label_id' => $test->label->id, 'name' => 'Example Artist']);
    $test->contract = Contract::factory()->create(['label_id' => $test->label->id, 'name' => 'Example Deal']);
    ContractSplit::factory()->create(['contract_id' => $test->contract->id, 'payee_id' => $test->artist->id, 'split_amount' => '0.5']);
    $test->release = Release::factory()->create(['label_id' => $test->label->id, 'name' => 'Example Release']);
    $test->firstQuarter = Period::factory()->create(['label_id' => $test->label->id]);
    $test->secondQuarter = Period::factory()->create(['label_id' => $test->label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function toolTrack(string $name, array $attributes = []): Track
{
    return Track::factory()->create(['label_id' => test()->label->id, 'release_id' => test()->release->id, 'contract_id' => test()->contract->id, 'name' => $name, ...$attributes]);
}

/**
 * An income line on the scenario release, paid in the first quarter.
 *
 * @param  array<string, mixed>  $attributes
 */
function toolSale(array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'label_id' => test()->label->id,
        'release_id' => test()->release->id,
        'format' => 'Download Track',
        'paid_timestamp' => '2026-02-15',
        ...$attributes,
    ]);
}

/**
 * What the real calculator gives a payee for a period.
 *
 * @return array<string, string>|null
 */
function toolPayeeTotals(Period $period, Payee $payee): ?array
{
    return collect(app(RoyaltyCalculator::class)->forPeriod($period)['payees'])->firstWhere('payee_id', $payee->id)['totals'] ?? null;
}

/**
 * The transactions a tool booked, keyed by the role of their item.
 *
 * @return array<string, Transaction>
 */
function toolBooked(?int $trackId = null): array
{
    return Transaction::query()
        ->whereNotNull('data_import_id')
        ->when($trackId, fn ($query) => $query->where('track_id', $trackId))
        ->with('item')
        ->orderBy('id')
        ->get()
        ->keyBy(fn (Transaction $transaction): string => (string) $transaction->item?->tool_role)
        ->all();
}

function toolItem(string $role, ?int $trackId = null): Item
{
    return Item::query()->where('tool_role', $role)->when($trackId, fn ($query) => $query->where('track_id', $trackId))->sole();
}

function toolPayeeUser(Label $label): User
{
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    return $user;
}
