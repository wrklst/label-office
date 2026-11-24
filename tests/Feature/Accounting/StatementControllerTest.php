<?php

use App\Enums\LabelRole;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\Label;
use App\Models\Payee;
use App\Models\Period;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Royalties\PayoutService;
use App\Services\Royalties\StatementGenerator;
use Inertia\Testing\AssertableInertia as Assert;

test('the statement list shows current statements, newest first', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);
    $archived = Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id, 'archived' => true]);
    $revision = Statement::factory()->create([
        'label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id, 'revision_of_id' => $archived->id,
        'name' => 'Example Artist – 2026 Q1', 'balance' => '70', 'payable' => '50', 'published_at' => now(),
    ]);
    $draft = Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'period_id' => $period->id]);
    Statement::factory()->create(['label_id' => Label::factory()]);

    $this->actingAs(labelAdmin($label))
        ->get(route('statements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statements/index')
            ->has('statements.data', 2)
            ->where('statements.data.0.id', $draft->id)
            ->where('statements.data.0.published', false)
            ->where('statements.data.1.id', $revision->id)
            ->where('statements.data.1.name', 'Example Artist – 2026 Q1')
            ->where('statements.data.1.payee', 'Example Artist')
            ->where('statements.data.1.period', '2026 Q1')
            ->where('statements.data.1.published', true)
            ->where('statements.data.1.archived', false)
            ->where('statements.data.1.revision_of_id', $archived->id)
            ->where('statements.data.1.balance', '70.000000000000000')
            ->where('statements.data.1.payable', '50.000000000000000')
            ->where('filters', ['archived' => false, 'period' => null, 'payee' => null, 'sort' => null, 'direction' => null]));
});

test('the statement list includes archived statements on request', function () {
    $label = Label::factory()->create();
    $archived = Statement::factory()->create(['label_id' => $label->id, 'archived' => true]);
    $current = Statement::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('statements.index', ['archived' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('statements.data', 2)
            ->where('statements.data.0.id', $current->id)
            ->where('statements.data.1.id', $archived->id)
            ->where('statements.data.1.archived', true)
            ->where('filters', ['archived' => true, 'period' => null, 'payee' => null, 'sort' => null, 'direction' => null]));
});

test('the statement list can be limited to one period', function () {
    $label = Label::factory()->create();
    $first = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);
    $second = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q2', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);
    Statement::factory()->create(['label_id' => $label->id, 'period_id' => $first->id]);
    $wanted = Statement::factory()->create(['label_id' => $label->id, 'period_id' => $second->id]);

    $this->actingAs(labelAdmin($label))
        ->get(route('statements.index', ['period' => $second->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('statements.data', 1)
            ->where('statements.data.0.id', $wanted->id)
            ->where('filters', ['archived' => false, 'period' => $second->id, 'payee' => null, 'sort' => null, 'direction' => null]));
});

test('a statement shows its stored snapshot', function () {
    $label = Label::factory()->create();
    $statement = Statement::factory()->create([
        'label_id' => $label->id, 'name' => 'Example Artist – 2026 Q1', 'published_at' => now(),
        'data' => ['payee_name' => 'Example Artist', 'totals' => ['payee_share' => '50.000000000000000']],
    ]);

    $this->actingAs(labelAdmin($label))
        ->get(route('statements.show', $statement->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statements/show')
            ->where('statement.id', $statement->id)
            ->where('statement.name', 'Example Artist – 2026 Q1')
            ->where('statement.published', true)
            ->where('statement.archived', false)
            ->where('statement.payee_id', $statement->payee_id)
            ->where('statement.period_id', $statement->period_id)
            ->where('statement.data', ['payee_name' => 'Example Artist', 'totals' => ['payee_share' => '50.000000000000000']])
            ->where('portal', false));
});

test('publishing a draft statement makes it visible to the payee', function () {
    $label = Label::factory()->create();
    $statement = Statement::factory()->create(['label_id' => $label->id]);
    $this->travelTo('2026-04-05 10:00:00');

    $this->actingAs(labelAdmin($label))
        ->from(route('statements.index'))
        ->post(route('statements.publish', $statement->id))
        ->assertRedirect(route('statements.index'))
        ->assertInertiaFlash('toast.message', 'Statement published to the payee.');

    $this->assertDatabaseHas('statements', ['id' => $statement->id, 'published_at' => '2026-04-05 10:00:00']);
});

test('publishing a published statement takes it back', function () {
    $label = Label::factory()->create();
    $statement = Statement::factory()->create(['label_id' => $label->id, 'published_at' => '2026-04-05 10:00:00']);

    $this->actingAs(labelAdmin($label))
        ->post(route('statements.publish', $statement->id))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Statement unpublished.');

    $this->assertDatabaseHas('statements', ['id' => $statement->id, 'published_at' => null]);
});

test('deleting a statement removes it', function () {
    $label = Label::factory()->create();
    $statement = Statement::factory()->create(['label_id' => $label->id]);
    $kept = Statement::factory()->create(['label_id' => $label->id]);

    $this->actingAs(labelAdmin($label))
        ->delete(route('statements.destroy', $statement->id))
        ->assertRedirect(route('statements.index'))
        ->assertInertiaFlash('toast.message', 'Statement deleted.');

    $this->assertModelMissing($statement);
    $this->assertModelExists($kept);
});

test('the csv download lists the summary, ledgers, income, expenses, transfers and payouts', function () {
    $label = Label::factory()->create(['currency' => 'EUR']);
    $period = Period::factory()->create(['label_id' => $label->id, 'name' => '2026 Q1']);
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $contract = Contract::factory()->create(['label_id' => $label->id, 'name' => 'Example Contract']);
    ContractSplit::factory()->create(['contract_id' => $contract->id, 'payee_id' => $payee->id, 'split_amount' => '0.5']);
    $release = Release::factory()->create(['label_id' => $label->id, 'name' => 'Example Release']);
    Track::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'contract_id' => $contract->id]);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'total' => '100', 'quantity' => 4, 'format' => 'Download Track']);
    Transaction::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'type' => 'expense', 'total' => '-40', 'category' => 'Mastering']);
    Transaction::factory()->create([
        'label_id' => $label->id, 'release_id' => $release->id, 'type' => 'transfer', 'total' => '-10', 'category' => 'Advance',
        'payee_id' => $payee->id, 'memo' => 'Example advance', 'paid_timestamp' => '2026-02-01',
    ]);
    app(PayoutService::class)->record($period, $payee, '5', '2026-04-10', 'Example payout');
    $statement = app(StatementGenerator::class)->generate($period)->sole();

    $response = $this->actingAs(labelAdmin($label))
        ->get(route('statements.download', $statement->id))
        ->assertOk()
        ->assertDownload('example-artist-2026-q1.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

    expect($response->streamedContent())->toBe(<<<'CSV'
        Statement,"Example Artist"
        Period,"2026 Q1",2026-01-01,2026-03-31
        Currency,EUR

        Summary
        "Previous balance",Income,Expenses,Net,"Your share",Transfers,Payable,Paid,Balance
        0.0000,100.0000,-40.0000,60.0000,30.0000,-10.0000,20.0000,5.0000,15.0000

        Ledger,Primary
        Release,Track,Contract,"Split %",Income,Expenses,Net,"Your share","Previous balance",Balance
        "Example Release",N/A,"Example Contract",50.0000,100.0000,-40.0000,60.0000,30.0000,0.0000,30.0000
        "Ledger total",,,,100.0000,-40.0000,60.0000,30.0000,0.0000,15.0000
        Transfers,-10.0000,Payouts,5.0000

        Income
        Release,Track,Contract,Format,Units,Income,"Split %","Your share"
        "Example Release",,"Example Contract","Download Track",4.00,100.0000,50.0000,50.0000

        Expenses
        Release,Track,Contract,Category,Amount,"Split %","Your share"
        "Example Release",,"Example Contract",Mastering,-40.0000,50.0000,-20.0000

        "Transfers and payouts"
        Date,Type,Ledger,Release,Memo,Amount
        2026-02-01,Advance,Primary,"Example Release","Example advance",-10.0000
        2026-04-10,Payout,,,"Example payout",-5.0000

        CSV);
});

test('the csv download of a statement without figures still has every section', function () {
    $label = Label::factory()->create();
    $statement = Statement::factory()->create(['label_id' => $label->id, 'name' => 'Empty statement', 'data' => []]);

    $response = $this->actingAs(labelAdmin($label))
        ->get(route('statements.download', $statement->id))
        ->assertOk()
        ->assertDownload('empty-statement.csv');

    expect($response->streamedContent())
        ->toContain("0.0000,0.0000,0.0000,0.0000,0.0000,0.0000,0.0000,0.0000,0.0000\n")
        ->toContain("Income\n")
        ->toContain("Expenses\n")
        ->toContain("\"Transfers and payouts\"\n")
        ->not->toContain('Ledger total');
});

test('a statement of another label cannot be opened, published, downloaded or deleted', function () {
    $label = Label::factory()->create();
    $foreign = Statement::factory()->create(['label_id' => Label::factory()]);
    $admin = labelAdmin($label);

    $this->actingAs($admin)->get(route('statements.show', $foreign->id))->assertNotFound();
    $this->actingAs($admin)->post(route('statements.publish', $foreign->id))->assertNotFound();
    $this->actingAs($admin)->get(route('statements.download', $foreign->id))->assertNotFound();
    $this->actingAs($admin)->delete(route('statements.destroy', $foreign->id))->assertNotFound();

    $this->assertDatabaseHas('statements', ['id' => $foreign->id, 'published_at' => null]);
});

test('a payee cannot publish or delete statements', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $statement = Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id]);
    $user = User::factory()->create(['current_label_id' => $label->id]);
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    $this->actingAs($user)->post(route('statements.publish', $statement->id))->assertForbidden();
    $this->actingAs($user)->delete(route('statements.destroy', $statement->id))->assertForbidden();
    $this->actingAs($user)->get(route('statements.download', $statement->id))->assertRedirect(route('portal.index'));

    $this->assertDatabaseHas('statements', ['id' => $statement->id, 'published_at' => null]);
});
