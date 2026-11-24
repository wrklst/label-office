<?php

use App\Models\Label;
use App\Support\Backups;
use App\Support\InstallationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');
    Storage::fake('backup');
    config(['filesystems.disks.s3.bucket' => 'files', 'filesystems.disks.s3.key' => 'key']);

    // The dump of an SQLite database is a copy of its file; the tests' own database is in memory.
    $this->scratch = sys_get_temp_dir().'/backup-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->scratch);
    File::put("{$this->scratch}/database.sqlite", 'the database');
    config(['database.connections.sqlite.database' => "{$this->scratch}/database.sqlite"]);

    $this->travelTo('2026-11-24 02:15:00');
});

afterEach(fn () => File::deleteDirectory($this->scratch));

test('the first backup holds every file, the next only what changed, and each can be put together whole', function () {
    Storage::disk('local')->put('releases/1/master.wav', 'master');
    Storage::disk('local')->put('imports/1/statement.csv', 'first statement');
    Storage::disk('s3')->put('receipts/1/invoice.pdf', 'invoice');
    Storage::disk('local')->put('releases/1/half-written.wav.1a2b.tmp', 'half');

    $this->artisan('royalties:backup')->expectsOutputToContain('3 files, 3 of them new or changed')->assertSuccessful();

    $this->travelTo('2026-11-25 02:15:00');
    Storage::disk('local')->put('imports/1/statement.csv', 'the statement, corrected');
    Storage::disk('local')->put('releases/2/cover.jpg', 'cover');
    Storage::disk('s3')->delete('receipts/1/invoice.pdf');
    File::put("{$this->scratch}/database.sqlite", 'the database, a day later');

    $this->artisan('royalties:backup')->expectsOutputToContain('3 files, 2 of them new or changed')->assertSuccessful();

    [$first, $second] = Backups::names();

    expect([$first, $second])->toBe(['2026-11-24-021500-yearly', '2026-11-25-021500-daily'])
        ->and(Storage::disk('backup')->allFiles("{$second}/files"))->toBe(["{$second}/files/local/imports/1/statement.csv", "{$second}/files/local/releases/2/cover.jpg"]);

    $this->artisan('royalties:assemble-backup', ['backup' => $first, '--to' => "{$this->scratch}/first"])->assertSuccessful();
    $this->artisan('royalties:assemble-backup', ['--to' => "{$this->scratch}/newest"])->assertSuccessful();

    $state = fn (string $folder): array => collect(File::allFiles("{$this->scratch}/{$folder}"))
        ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => $file->getContents()])->sortKeys()->all();

    expect($state('first'))->toBe([
        'database.sqlite' => 'the database',
        'files/local/imports/1/statement.csv' => 'first statement',
        'files/local/releases/1/master.wav' => 'master',
        'files/s3/receipts/1/invoice.pdf' => 'invoice',
    ])->and($state('newest'))->toBe([
        'database.sqlite' => 'the database, a day later',
        'files/local/imports/1/statement.csv' => 'the statement, corrected',
        'files/local/releases/1/master.wav' => 'master',
        'files/local/releases/2/cover.jpg' => 'cover',
    ]);
});

test('a backup that is no longer kept is deleted down to the files a kept one still points at', function () {
    Backups::remember(['keep' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'yearly' => 1]]);
    Storage::disk('local')->put('releases/1/master.wav', 'master');
    Storage::disk('backup')->put('2026-11-20-021500-daily/files/local/left-behind.wav', 'from a backup that never finished');

    $this->artisan('royalties:backup')->assertSuccessful();

    $this->travelTo('2026-11-25 02:15:00');
    Storage::disk('local')->put('releases/2/cover.jpg', 'cover');
    Storage::disk('local')->put('imports/1/statement.csv', 'statement');
    $this->artisan('royalties:backup')->assertSuccessful();

    $this->travelTo('2026-11-26 02:15:00');
    Storage::disk('local')->delete('imports/1/statement.csv');
    $this->artisan('royalties:backup')->expectsOutputToContain('deleted: 2026-11-25-021500-daily')->assertSuccessful();

    expect(Backups::names())->toBe(['2026-11-24-021500-yearly', '2026-11-26-021500-daily'])
        ->and(Storage::disk('backup')->allFiles())->toEqualCanonicalizing([
            '2026-11-24-021500-yearly/database.sqlite.gz',
            '2026-11-24-021500-yearly/manifest.json',
            '2026-11-24-021500-yearly/files/local/releases/1/master.wav',
            '2026-11-25-021500-daily/files/local/releases/2/cover.jpg',
            '2026-11-26-021500-daily/database.sqlite.gz',
            '2026-11-26-021500-daily/manifest.json',
        ]);

    $this->artisan('royalties:assemble-backup', ['--to' => "{$this->scratch}/newest"])->assertSuccessful();

    expect(File::get("{$this->scratch}/newest/files/local/releases/2/cover.jpg"))->toBe('cover');
});

test('a manifest that names a place outside the backup is refused', function () {
    $this->artisan('royalties:backup')->assertSuccessful();
    $name = Backups::names()[0];
    $manifest = json_decode(Storage::disk('backup')->get("{$name}/manifest.json"), true);
    Storage::disk('backup')->put("{$name}/manifest.json", json_encode([...$manifest, 'files' => ['local/../../../outside.php' => ['size' => 1, 'modified' => 1, 'in' => $name]]]));

    $this->artisan('royalties:assemble-backup', ['--to' => "{$this->scratch}/restore"])->assertFailed();

    expect(File::exists("{$this->scratch}/outside.php"))->toBeFalse();
});

test('a backup is of the first tier that has none yet in its year, month or week', function (array $names, string $now, string $tier) {
    expect(Backups::tier($names, CarbonImmutable::parse($now)))->toBe($tier);
})->with([
    'the first ever' => [[], '2026-11-24 02:15', 'yearly'],
    'the first of a year' => [['2026-12-31-021500-daily'], '2027-01-01 02:15', 'yearly'],
    'the first of a month' => [['2026-11-30-021500-weekly'], '2026-12-01 02:15', 'monthly'],
    'the first of a week, a Monday' => [['2026-11-01-021500-monthly', '2026-11-22-021500-daily'], '2026-11-23 02:15', 'weekly'],
    'the first of a week after a missed Monday' => [['2026-11-01-021500-monthly', '2026-11-22-021500-daily'], '2026-11-24 02:15', 'weekly'],
    'any other night' => [['2026-11-01-021500-monthly', '2026-11-23-021500-weekly'], '2026-11-24 02:15', 'daily'],
]);

test('six daily, three weekly, eleven monthly and seven yearly backups are kept unless set otherwise', function () {
    $names = [
        ...array_map(fn (int $year): string => "{$year}-01-01-021500-yearly", range(2017, 2026)),
        ...array_map(fn (int $month): string => sprintf('2026-%02d-01-021500-monthly', $month), range(2, 12)),
        '2026-12-07-021500-weekly', '2026-12-14-021500-weekly', '2026-12-21-021500-weekly', '2026-12-28-021500-weekly',
        ...array_map(fn (int $day): string => "2026-12-{$day}-021500-daily", range(22, 27)),
        '2026-12-29-021500-daily',
    ];

    expect(Backups::settings()['keep'])->toBe(['daily' => 6, 'weekly' => 3, 'monthly' => 11, 'yearly' => 7])
        ->and(Backups::expired($names, Backups::settings()['keep']))->toBe([
            '2017-01-01-021500-yearly', '2018-01-01-021500-yearly', '2019-01-01-021500-yearly',
            '2026-12-07-021500-weekly', '2026-12-22-021500-daily',
        ]);
});

test('without a bucket there are no backups: the scheduler says nothing, a run by hand says why', function () {
    config(['filesystems.disks.s3.bucket' => null]);
    Backups::remember(['requested' => true]);

    $this->artisan('royalties:backup')->expectsOutputToContain('need an S3-compatible bucket')->assertFailed();
    $this->artisan('royalties:backup --scheduled')->doesntExpectOutput()->assertSuccessful();

    expect(Storage::disk('backup')->allFiles())->toBe([])
        ->and(Backups::settings()['requested'])->toBeFalse();
});

test('the scheduler makes one backup a night at the chosen hour, and one whenever it is asked for', function () {
    Backups::remember(['hour' => 4]);

    $this->artisan('royalties:backup --scheduled')->assertSuccessful();
    expect(Backups::names())->toBe([]);

    $this->travelTo('2026-11-24 04:00:00');
    $this->artisan('royalties:backup --scheduled')->assertSuccessful();
    $this->travelTo('2026-11-24 04:01:00');
    $this->artisan('royalties:backup --scheduled')->assertSuccessful();

    expect(Backups::names())->toBe(['2026-11-24-040000-yearly'])
        ->and(Backups::settings()['last'])->toMatchArray(['status' => 'ok', 'name' => '2026-11-24-040000-yearly', 'files' => 0]);

    $this->travelTo('2026-11-24 15:30:00');
    Backups::remember(['requested' => true]);
    $this->artisan('royalties:backup --scheduled')->assertSuccessful();

    expect(Backups::names())->toHaveCount(2)
        ->and(Backups::settings()['requested'])->toBeFalse();

    Backups::remember(['enabled' => false]);
    $this->travelTo('2026-11-25 04:00:00');
    $this->artisan('royalties:backup --scheduled')->assertSuccessful();

    expect(Backups::names())->toHaveCount(2);
});

test('a backup that fails says so on the settings page and is not tried again the same night', function () {
    Backups::remember(['hour' => 2]);
    config(['database.connections.sqlite.database' => ':memory:']);

    $this->artisan('royalties:backup --scheduled')->assertFailed();
    $this->artisan('royalties:backup --scheduled')->assertSuccessful();

    expect(Backups::names())->toBe([])
        ->and(Backups::settings()['last'])->toMatchArray(['status' => 'failed', 'message' => 'The database file could not be copied.']);
});

test('the settings page shows the backups and takes the schedule, what is kept and a request', function () {
    $admin = labelAdmin(Label::factory()->create());
    $admin->forceFill(['installation_admin' => true])->save();
    $this->actingAs($admin);
    $this->artisan('royalties:backup')->assertSuccessful();

    $this->get(route('backups.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/backups')
        ->where('available', true)
        ->where('settings.enabled', true)
        ->where('settings.keep', ['daily' => 6, 'weekly' => 3, 'monthly' => 11, 'yearly' => 7])
        ->where('bucket', null)
        ->where('backups.items.0.name', '2026-11-24-021500-yearly'));

    $keep = ['daily' => '10', 'weekly' => '4', 'monthly' => '12', 'yearly' => '2'];
    $this->put(route('backups.update'), ['enabled' => '0', 'hour' => '5', 'keep' => $keep])->assertSessionHasNoErrors();
    $this->put(route('backups.update'), ['enabled' => '1', 'hour' => '24', 'keep' => $keep])->assertSessionHasErrors('hour');
    $this->put(route('backups.update'), ['enabled' => '1', 'hour' => '5', 'keep' => [...$keep, 'yearly' => '0']])->assertSessionHasErrors('keep.yearly');
    $this->post(route('backups.run'))->assertSessionHasNoErrors();

    expect(Backups::settings())->toMatchArray(['enabled' => false, 'hour' => 5, 'keep' => ['daily' => 10, 'weekly' => 4, 'monthly' => 12, 'yearly' => 2], 'requested' => true]);
});

test('without a bucket the page says so and takes no request', function () {
    config(['filesystems.disks.s3.bucket' => null]);
    $admin = labelAdmin(Label::factory()->create());
    $admin->forceFill(['installation_admin' => true])->save();
    $this->actingAs($admin);

    $this->get(route('backups.edit'))->assertInertia(fn (Assert $page) => $page->where('available', false)->where('backups.items', []));
    $this->post(route('backups.run'))->assertSessionHasErrors('backup');

    expect(Backups::settings()['requested'])->toBeFalse();
});

test('the backups can have a bucket of their own, which never shows its secret and can be given up again', function () {
    config(['filesystems.disks.s3.bucket' => null]);
    $admin = labelAdmin(Label::factory()->create());
    $admin->forceFill(['installation_admin' => true])->save();
    $this->actingAs($admin);

    // A bucket nobody can reach is refused before it is stored.
    $this->put(route('backups.bucket'), ['bucket' => 'backups-only', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret', 'endpoint' => 'https://127.0.0.1:1'])->assertSessionHasErrors('bucket');
    expect(Backups::settings()['bucket'])->toBeNull();

    Backups::remember(['bucket' => ['bucket' => 'backups-only', 'key' => 'AKIAEXAMPLE', 'secret' => 'secret', 'endpoint' => 'https://s3.example.com', 'path_style' => true]]);
    Backups::disk();

    expect(Backups::available())->toBeTrue()
        ->and(config('filesystems.disks.backup'))->toMatchArray(['driver' => 's3', 'bucket' => 'backups-only', 'key' => 'AKIAEXAMPLE', 'endpoint' => 'https://s3.example.com', 'use_path_style_endpoint' => true, 'throw' => true]);

    $this->get(route('backups.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('bucket', ['bucket' => 'backups-only', 'endpoint' => 'https://s3.example.com', 'region' => null, 'key_hint' => '••••MPLE']));

    $this->delete(route('backups.bucket.destroy'))->assertSessionHasNoErrors();

    expect(Backups::settings()['bucket'])->toBeNull()
        ->and(Backups::available())->toBeFalse();
});

test('backups are for admins of the installation only', function (string $method, string $route) {
    $this->actingAs(labelAdmin(Label::factory()->create()))->{$method}(route($route), ['enabled' => '0', 'hour' => '5'])->assertForbidden();

    expect(InstallationSettings::get('backups'))->toBeNull();
})->with([['get', 'backups.edit'], ['put', 'backups.update'], ['put', 'backups.bucket'], ['delete', 'backups.bucket.destroy'], ['post', 'backups.run']]);
