<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\Artist;
use App\Models\Contract;
use App\Models\ContractSplit;
use App\Models\DataImport;
use App\Models\Item;
use App\Models\Label;
use App\Models\Ledger;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Release;
use App\Models\Statement;
use App\Models\Territory;
use App\Models\Track;
use App\Models\Transaction;
use App\Services\Imports\Identifiers;
use App\Services\Releases\ArtistCredits;
use App\Support\CurrentLabel;
use App\Support\Decimal;
use App\Support\Formats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('royalties:import-infinite-catalog
    {directory : Folder holding the exported JSON files}
    {--label= : Slug or id of the label to import into}
    {--timezone=Europe/Berlin : Time zone the dates were entered in}
    {--rate=* : Conversion rate for a currency Infinite Catalog converted itself, e.g. GBP=1.16}
    {--fresh : Delete everything the label holds before importing}')]
#[Description('Migrate a complete Infinite Catalog account export into a label')]
class ImportInfiniteCatalog extends Command
{
    private const int CHUNK = 1000;

    private string $directory;

    private Label $label;

    /** @var array<string, array<int, int>> entity => Infinite Catalog id => local id */
    private array $ids = [];

    public function handle(CurrentLabel $currentLabel): int
    {
        $this->directory = rtrim((string) $this->argument('directory'), '/');
        $label = Label::query()->where(ctype_digit((string) $this->option('label')) ? 'id' : 'slug', $this->option('label'))->first();

        if ($label === null || ! is_file("{$this->directory}/transactions.ndjson")) {
            $this->error('Pass --label and a directory containing the export (transactions.ndjson and the entity JSON files).');

            return self::FAILURE;
        }

        $this->label = $label;
        $currentLabel->set($label);

        if (Release::query()->exists() || Transaction::query()->exists() || Payee::query()->exists()) {
            if (! $this->option('fresh')) {
                $this->error("{$label->name} already holds data. Pass --fresh to replace it.");

                return self::FAILURE;
            }
        }

        DB::transaction(function (): void {
            if ($this->option('fresh')) {
                $this->wipe();
            }

            $this->label->update(['currency' => $this->read('customer')['currency'] ?? $this->label->currency]);

            $this->importCatalog();
            $this->importPeriodsAndPayouts();
            $this->importTransactions();
        });

        $this->table(['', 'Imported'], collect([
            'Artists' => Artist::class, 'Payees' => Payee::class, 'Ledgers' => Ledger::class, 'Contracts' => Contract::class,
            'Releases' => Release::class, 'Tracks' => Track::class, 'Items' => Item::class, 'Data imports' => DataImport::class,
            'Periods' => Period::class, 'Payouts' => Payout::class, 'Transactions' => Transaction::class,
        ])->map(fn (string $model, string $name): array => [$name, $model::query()->count()])->values()->all());

        return self::SUCCESS;
    }

    private function importCatalog(): void
    {
        $artists = $this->read('artists');

        foreach ($artists as $artist) {
            $this->ids['artists'][$artist['id']] = Artist::query()->create([
                'name' => $artist['name'],
                'discogs_search_name' => $artist['discogs_search_name'] ?: null,
            ])->id;
        }

        foreach ($artists as $artist) {
            if ($artist['alias_of']) {
                Artist::query()->whereKey($this->ids['artists'][$artist['id']])->update(['alias_of_id' => $this->map('artists', $artist['alias_of'])]);
            }
        }

        foreach ($this->read('payees') as $payee) {
            $this->ids['payees'][$payee['id']] = Payee::query()->create(['name' => $payee['name'], 'tags' => $payee['tags'] ?: null])->id;
        }

        foreach ($this->read('ledgers') as $ledger) {
            $this->ids['ledgers'][$ledger['id']] = Ledger::query()->create(['name' => $ledger['name']])->id;
        }

        foreach ($this->read('contracts') as $contract) {
            if ($contract['has_child_contracts']) {
                throw new RuntimeException("Contract \"{$contract['name']}\" is prorated; migrating prorated contracts is not supported yet.");
            }

            $this->ids['contracts'][$contract['id']] = Contract::query()->create(['name' => $contract['name'], 'memo' => $contract['memo'] ?: null])->id;
        }

        $regions = collect($this->read('regions'))->pluck('alpha_2', 'id');

        foreach ($this->read('splits') as $split) {
            $territory = null;

            if ($split['region']) {
                $code = $regions[$this->id($split['region'])] ?? throw new RuntimeException('A split is limited to an unknown region.');
                $territory = Territory::query()->firstOrCreate(['name' => $split['region_data']['name'] ?? $code], ['countries' => [$code]])->id;
            }

            ContractSplit::query()->create([
                'contract_id' => $this->map('contracts', $split['contract']),
                'payee_id' => $this->map('payees', $split['payee']),
                'ledger_id' => $this->map('ledgers', $split['ledger']),
                'territory_id' => $territory,
                'split_amount' => $split['split_amount'],
            ]);
        }

        $upcs = [];
        $releaseContracts = [];

        foreach ($this->read('releases') as $release) {
            $upc = Identifiers::upc($release['upc'] ?? null);

            // A label holds each UPC once; a second release with it is imported without.
            if ($upc !== null && isset($upcs[Identifiers::upcKey($upc)])) {
                $this->warn("{$release['name']} shares its UPC {$upc} with another release and was imported without one.");
                $upc = null;
            }

            if ($upc !== null) {
                $upcs[Identifiers::upcKey($upc)] = true;
            }

            $this->ids['releases'][$release['id']] = Release::query()->create([
                'name' => $release['name'],
                'artist_id' => $this->map('artists', $release['artist']),
                'catalog_number' => $release['catalog_number'] ?: null,
                'upc' => $upc,
            ])->id;
            $releaseContracts[$this->ids['releases'][$release['id']]] = $this->map('contracts', $release['contract']);
        }

        // A release has no contract here: the one the old system gave it goes to each of its tracks that has none.
        foreach ($this->read('tracks') as $track) {
            $this->ids['tracks'][$track['id']] = Track::query()->create([
                'release_id' => $this->map('releases', $track['release']),
                'artist_id' => $this->map('artists', $track['artist']),
                'contract_id' => $this->map('contracts', $track['contract']) ?? $releaseContracts[$this->map('releases', $track['release'])] ?? null,
                'name' => $track['name'],
                'isrc' => $track['isrc'] ?: null,
                'iswc' => $track['iswc'] ?: null,
                'track_number' => $track['track_number'],
                'mechanicals_rate' => $track['mechanicals_rate'],
            ])->id;
        }

        // The export names one artist per track; remixers are found in the titles.
        ArtistCredits::creditRemixers();

        foreach ($this->read('data-imports') as $import) {
            $this->ids['data-imports'][$import['id']] = DataImport::query()->create([
                'importer' => 'infinite-catalog',
                'name' => $import['display_name'] ?: $import['name'],
                'status' => ImportStatus::tryFrom($import['status']) ?? ImportStatus::Imported,
                'original_filename' => $import['import_file_path'] ? basename($import['import_file_path']) : null,
                'default_timestamp' => $this->date($import['default_timestamp']),
                'default_paid_timestamp' => $this->date($import['default_paid_timestamp']),
                'replace_timestamp' => (bool) $import['replace_timestamp'],
                'replace_paid_timestamp' => (bool) $import['replace_paid_timestamp'],
                'tags' => $import['tags'] ?: null,
                'metadata' => ['infinite_catalog_id' => $import['id'], 'source' => $import['source_display'] ?: $import['importer_name']],
                'created_at' => CarbonImmutable::parse($import['created_at']),
            ])->id;
        }

        foreach ($this->read('items') as $item) {
            $this->ids['items'][$item['id']] = Item::query()->create([
                'release_id' => $this->map('releases', $item['release']),
                'track_id' => $this->map('tracks', $item['track']),
                'contract_id' => $this->map('contracts', $item['contract']),
                'data_import_id' => $this->map('data-imports', $item['csv_import']),
                'format_group' => $item['format_group_data']['name'] ?? null,
                'name' => $item['formatted_name'] ?: ($item['name'] ?: $item['track_name']),
                'artist_name' => $item['artist_data']['name'] ?? null,
                'track_name' => $item['track_name'] ?: null,
                'catalog_number' => $item['catalog_number'] ?: null,
                'isrc' => $item['isrc'] ?: null,
                'iswc' => $item['iswc'] ?: null,
                'upc' => $item['upc'] ?: null,
            ])->id;
        }
    }

    /**
     * Infinite Catalog books a payout as transfers dated on the payment
     * day. Here it becomes a payout of the period it settles.
     */
    private function importPeriodsAndPayouts(): void
    {
        foreach ($this->read('periods') as $period) {
            $this->ids['periods'][$period['id']] = Period::query()->create([
                'name' => $period['name'],
                'starts_on' => $period['start'],
                'ends_on' => $period['end'],
            ])->id;
        }

        foreach ($this->read('payouts') as $payout) {
            $total = Decimal::parse($payout['total']);

            Payout::query()->create([
                'payee_id' => $this->map('payees', $payout['payee']),
                'period_id' => $this->map('periods', $payout['period']),
                'total' => $total,
                'paid_on' => $this->date($payout['timestamp']),
                'memo' => $payout['name'],
            ])->allocations()->create(['ledger_id' => null, 'amount' => $total]);
        }
    }

    private function importTransactions(): void
    {
        $regions = collect($this->read('regions'))->pluck('alpha_2', 'id');
        $rates = collect($this->option('rate'))
            ->filter()
            ->mapWithKeys(fn (string $rate): array => [strtoupper(str($rate)->before('=')->value()) => Decimal::parse(str($rate)->after('=')->value())]);
        $now = now();
        $buffer = [];
        $skippedPayoutTransfers = 0;
        $unconverted = 0;
        $handle = fopen("{$this->directory}/transactions.ndjson", 'r');

        if ($handle === false) {
            throw new RuntimeException('transactions.ndjson could not be opened.');
        }

        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);

            if ($row['importer_source'] === 'infinite_catalog_payout') {
                $skippedPayoutTransfers++;

                continue;
            }

            $currency = strtoupper($row['currency']);
            $rate = match (true) {
                $currency === $this->label->currency => '1',
                isset($rates[$currency]) => $rates[$currency],
                default => Decimal::parse($row['conversion_rate']),
            };
            // The exported rate is rounded to five decimals, so the amount
            // Infinite Catalog converted with the full rate is taken as is.
            $converted = match (true) {
                $currency !== $this->label->currency && ! isset($rates[$currency]) && $row['converted_total'] !== null => Decimal::parse($row['converted_total']),
                $rate === null => null,
                default => Decimal::mul($row['total'], $rate),
            };
            $unconverted += $converted === null ? 1 : 0;

            $buffer[] = [
                'label_id' => $this->label->id,
                'data_import_id' => $this->ids['data-imports'][$row['data_import']] ?? null,
                'release_id' => $this->ids['releases'][$row['release']] ?? null,
                'track_id' => $this->ids['tracks'][$row['track']] ?? null,
                'item_id' => $this->ids['items'][$row['item']] ?? null,
                'contract_id' => $this->ids['contracts'][$row['contract']] ?? null,
                'payee_id' => $this->ids['payees'][$row['payee']] ?? null,
                'type' => $row['type'],
                'timestamp' => $this->date($row['timestamp']),
                'paid_timestamp' => $this->date($row['paid_timestamp']),
                'quantity' => $row['quantity'],
                'price' => $row['price'],
                'total' => $row['total'],
                'currency' => $currency,
                'conversion_rate' => $rate,
                'converted_total' => $converted,
                'category' => $row['category'] ?: null,
                'format' => Formats::canonical($row['format']),
                'region' => $regions[$row['region']] ?? null,
                'provider' => $row['source'] ?: null,
                'retailer' => $row['provider'] ?: null,
                'external_id' => $row['external_id'] ?: null,
                'source' => $row['source'] ?: null,
                'importer_source' => $row['importer_source'] ?: 'manual',
                'memo' => $row['memo'] ? mb_substr($row['memo'], 0, 1000) : null,
                'published' => $row['published'],
                'billable' => $row['billable'],
                'recoupable' => true,
                'fingerprint' => sha1('infinite-catalog|'.$row['id']),
                'raw_data' => json_encode(['infinite_catalog_id' => $row['id']]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::CHUNK) {
                DB::table('transactions')->insert($buffer);
                $buffer = [];
            }
        }

        fclose($handle);

        if ($buffer !== []) {
            DB::table('transactions')->insert($buffer);
        }

        $totals = DB::table('transactions')->where('label_id', $this->label->id)->whereNotNull('data_import_id')
            ->selectRaw('data_import_id, COUNT(*) as transactions, SUM(converted_total) as total')->groupBy('data_import_id')->get();

        foreach ($totals as $row) {
            DataImport::query()->whereKey($row->data_import_id)->update([
                'transactions_count' => $row->transactions,
                'rows_count' => $row->transactions,
                'total' => Decimal::parse($row->total) ?? '0',
            ]);
        }

        $this->line("{$skippedPayoutTransfers} payout transfers were not imported as transfers: they are covered by the payouts.");

        if ($unconverted > 0) {
            $this->warn("{$unconverted} transactions are in another currency without a rate and will not be counted. Pass --rate=CUR=rate.");
        }
    }

    private function wipe(): void
    {
        foreach ([Statement::class, Payout::class, Period::class, Transaction::class, Item::class, DataImport::class, Track::class, Release::class, Contract::class, Ledger::class, Payee::class, Territory::class] as $model) {
            $model::query()->delete();
        }

        Artist::query()->update(['alias_of_id' => null]);
        Artist::query()->delete();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function read(string $name): array
    {
        return json_decode((string) file_get_contents("{$this->directory}/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The local id of the record an Infinite Catalog URL points at.
     */
    private function map(string $entity, ?string $url): ?int
    {
        if ($url === null || $url === '') {
            return null;
        }

        return $this->ids[$entity][$this->id($url)] ?? throw new RuntimeException("The export references a missing record: {$url}");
    }

    private function id(string $url): int
    {
        return (int) basename(rtrim($url, '/'));
    }

    /**
     * Infinite Catalog stores instants; the day meant is the one in the
     * time zone the label works in.
     */
    private function date(?string $value): ?string
    {
        return $value === null || $value === ''
            ? null
            : CarbonImmutable::parse($value)->setTimezone((string) $this->option('timezone'))->toDateString();
    }
}
