<?php

use App\Http\Controllers\AgreementController;
use App\Http\Controllers\AgreementTemplateController;
use App\Http\Controllers\AirplayController;
use App\Http\Controllers\AirplayResearchController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\HandbookController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\MyAgreementController;
use App\Http\Controllers\PayeeController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\PlatformLinkController;
use App\Http\Controllers\PlayCountController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\Settings\BackupsController;
use App\Http\Controllers\Settings\ConnectionsController;
use App\Http\Controllers\Settings\LabelBrandingController;
use App\Http\Controllers\Settings\LabelSettingsController;
use App\Http\Controllers\Settings\TeamController;
use App\Http\Controllers\Settings\UpdatesController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UploadController;
use App\Support\Services;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Called by the updater on this server while the site is in maintenance mode; see bootstrap/app.php.
Route::get('system/code-cache/reset', [UpdatesController::class, 'resetCodeCache'])
    ->middleware(['signed:relative', 'throttle:10,1'])->name('updates.code-cache');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('labels/create', [LabelController::class, 'create'])->name('labels.create');
    Route::post('labels', [LabelController::class, 'store'])->name('labels.store');
    Route::post('labels/{label}/switch', [LabelController::class, 'switch'])->name('labels.switch');

    Route::middleware('label')->group(function () {
        Route::get('my-statements', [PortalController::class, 'index'])->name('portal.index');
        Route::get('my-statements/{statement}', [PortalController::class, 'show'])->name('portal.show')->whereNumber('statement');
        Route::get('my-statements/{statement}/download', [PortalController::class, 'download'])->name('portal.download')->whereNumber('statement');

        Route::get('my-agreements', [MyAgreementController::class, 'index'])->name('my-agreements.index');
        Route::get('my-agreements/{agreement}', [MyAgreementController::class, 'show'])->name('my-agreements.show')->whereNumber('agreement');
        Route::post('my-agreements/{agreement}/code', [MyAgreementController::class, 'code'])->middleware('throttle:5,1')->name('my-agreements.code')->whereNumber('agreement');
        Route::post('my-agreements/{agreement}/sign', [MyAgreementController::class, 'sign'])->middleware('throttle:10,1')->name('my-agreements.sign')->whereNumber('agreement');

        Route::get('label-branding/{kind}', [LabelBrandingController::class, 'show'])->whereIn('kind', LabelBrandingController::KINDS)->name('label-branding.show');

        Route::middleware('label.admin')->group(function () {
            Route::post('label-branding/{kind}', [LabelBrandingController::class, 'store'])->whereIn('kind', LabelBrandingController::KINDS)->name('label-branding.store');
            Route::delete('label-branding/{kind}', [LabelBrandingController::class, 'destroy'])->whereIn('kind', LabelBrandingController::KINDS)->name('label-branding.destroy');

            Route::post('uploads/sign', [UploadController::class, 'sign'])->name('uploads.sign');

            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::get('handbook/{slug}/panel', [HandbookController::class, 'panel'])->where('slug', '[\w-]+')->name('handbook.panel');
            Route::get('handbook/{slug?}', [HandbookController::class, 'show'])->where('slug', '[\w-]+')->name('handbook');

            Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
            Route::patch('catalog/assign', [CatalogController::class, 'assign'])->name('catalog.assign');

            Route::resource('artists', ArtistController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::resource('releases', ReleaseController::class);

            Route::post('platform-links', [PlatformLinkController::class, 'store'])->name('platform-links.store');
            Route::post('platform-links/detect', [PlatformLinkController::class, 'detect'])->middleware('throttle:20,1')->name('platform-links.detect');
            Route::put('platform-links/{platform_link}', [PlatformLinkController::class, 'update'])->name('platform-links.update');
            Route::delete('platform-links/{platform_link}', [PlatformLinkController::class, 'destroy'])->name('platform-links.destroy');
            Route::get('tracks/{track}/fields', [TrackController::class, 'fields'])->name('tracks.fields');
            Route::resource('tracks', TrackController::class)->only(['index', 'show', 'update', 'destroy']);
            Route::resource('items', ItemController::class)->only(['index', 'update', 'destroy']);
            Route::resource('contracts', ContractController::class);
            Route::resource('payees', PayeeController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::resource('ledgers', LedgerController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::resource('agreements', AgreementController::class);
            Route::post('agreements/{agreement}/send', [AgreementController::class, 'send'])->name('agreements.send');
            Route::post('agreements/{agreement}/void', [AgreementController::class, 'void'])->name('agreements.void');
            Route::post('agreements/{agreement}/contract', [AgreementController::class, 'contract'])->name('agreements.contract');
            Route::resource('agreement-templates', AgreementTemplateController::class)->only(['index', 'store', 'update', 'destroy']);

            Route::resource('airplays', AirplayController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::post('airplays/{airplay}/links', [AirplayController::class, 'storeLink'])->name('airplays.links.store');
            Route::delete('airplays/{airplay}/links', [AirplayController::class, 'destroyLink'])->name('airplays.links.destroy');
            Route::post('airplays/research', [AirplayResearchController::class, 'store'])->middleware('throttle:10,1')->name('airplays.research.store');
            Route::post('airplay-findings/{finding}/keep', [AirplayResearchController::class, 'keep'])->name('airplay-findings.keep');
            Route::post('airplay-findings/{finding}/dismiss', [AirplayResearchController::class, 'dismiss'])->name('airplay-findings.dismiss');
            Route::post('airplays/{airplay}/research', [AirplayResearchController::class, 'airplay'])->middleware('throttle:30,1')->name('airplays.research.airplay');
            Route::post('airplays/{airplay}/search', [AirplayController::class, 'search'])->middleware('throttle:30,1')->name('airplays.search');

            Route::resource('transactions', TransactionController::class)->except(['show']);

            Route::get('data-imports/template', [DataImportController::class, 'template'])->name('data-imports.template');
            Route::post('data-imports/{data_import}/resolve', [DataImportController::class, 'resolve'])->name('data-imports.resolve');
            Route::resource('data-imports', DataImportController::class)->only(['index', 'create', 'store', 'show', 'destroy']);

            Route::get('play-counts', [PlayCountController::class, 'index'])->name('play-counts.index');
            Route::post('play-counts', [PlayCountController::class, 'store'])->name('play-counts.store');
            Route::delete('play-counts/{batch}', [PlayCountController::class, 'destroy'])->name('play-counts.destroy');

            Route::resource('periods', PeriodController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
            Route::get('periods/{period}/payees/{payee}', [PeriodController::class, 'payee'])->name('periods.payee');
            Route::post('periods/{period}/statements', [PeriodController::class, 'statements'])->name('periods.statements');
            Route::post('periods/{period}/payouts', [PeriodController::class, 'payout'])->name('periods.payout');

            Route::resource('statements', StatementController::class)->only(['index', 'show', 'destroy']);
            Route::post('statements/{statement}/publish', [StatementController::class, 'publish'])->name('statements.publish');
            Route::get('statements/{statement}/download', [StatementController::class, 'download'])->name('statements.download');

            Route::resource('payouts', PayoutController::class)->only(['index', 'destroy']);

            Route::get('settings/label', [LabelSettingsController::class, 'edit'])->name('label-settings.edit');
            Route::put('settings/label', [LabelSettingsController::class, 'update'])->name('label-settings.update');
            Route::put('settings/label/legal', [LabelSettingsController::class, 'updateLegal'])->name('label-settings.legal');
            Route::post('settings/territories', [LabelSettingsController::class, 'storeTerritory'])->name('territories.store');
            Route::delete('settings/territories/{territory}', [LabelSettingsController::class, 'destroyTerritory'])->name('territories.destroy');

            Route::get('settings/connections', [ConnectionsController::class, 'edit'])->name('connections.edit');
            Route::put('settings/connections/services/{service}', [ConnectionsController::class, 'service'])->whereIn('service', Services::keys())->name('connections.service');
            Route::put('settings/connections/search', [ConnectionsController::class, 'search'])->name('connections.search');
            Route::put('settings/connections/search/markets', [ConnectionsController::class, 'markets'])->name('connections.search.markets');
            Route::put('settings/connections/ai', [ConnectionsController::class, 'ai'])->middleware('throttle:10,1')->name('connections.ai');
            Route::put('settings/connections/ai/model', [ConnectionsController::class, 'aiModel'])->middleware('throttle:10,1')->name('connections.ai.model');
            Route::put('settings/connections/storage', [ConnectionsController::class, 'storage'])->name('connections.storage');
            Route::post('settings/connections/storage/direct-uploads', [ConnectionsController::class, 'directUploads'])->name('connections.storage.direct-uploads');
            Route::delete('settings/connections/search', [ConnectionsController::class, 'destroySearch'])->name('connections.search.destroy');
            Route::delete('settings/connections/ai', [ConnectionsController::class, 'destroyAi'])->name('connections.ai.destroy');
            Route::delete('settings/connections/storage', [ConnectionsController::class, 'destroyStorage'])->name('connections.storage.destroy');

            Route::get('settings/updates', [UpdatesController::class, 'edit'])->name('updates.edit');
            Route::put('settings/updates', [UpdatesController::class, 'update'])->name('updates.update');
            Route::post('settings/updates/check', [UpdatesController::class, 'check'])->name('updates.check');
            Route::post('settings/updates/install', [UpdatesController::class, 'install'])->name('updates.install');

            Route::get('settings/backups', [BackupsController::class, 'edit'])->name('backups.edit');
            Route::put('settings/backups', [BackupsController::class, 'update'])->name('backups.update');
            Route::put('settings/backups/bucket', [BackupsController::class, 'bucket'])->middleware('throttle:10,1')->name('backups.bucket');
            Route::delete('settings/backups/bucket', [BackupsController::class, 'destroyBucket'])->name('backups.bucket.destroy');
            Route::post('settings/backups/run', [BackupsController::class, 'run'])->middleware('throttle:10,1')->name('backups.run');
            Route::post('settings/backups/restore', [BackupsController::class, 'restore'])->middleware('throttle:10,1')->name('backups.restore');

            Route::get('settings/team', [TeamController::class, 'index'])->name('team.index');
            Route::post('settings/team', [TeamController::class, 'store'])->middleware('throttle:30,60')->name('team.store');
            Route::put('settings/team/{user}', [TeamController::class, 'update'])->name('team.update');
            Route::delete('settings/team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');
        });
    });
});

require __DIR__.'/portal.php';
require __DIR__.'/profitability.php';
require __DIR__.'/royalty-tools.php';
require __DIR__.'/bandcamp.php';
require __DIR__.'/release-assets.php';
require __DIR__.'/settings.php';
