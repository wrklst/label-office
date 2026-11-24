<?php

use App\Support\DirectUploads;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('royalties:sync-bandcamp')
    ->weeklyOn(1, '05:00')
    ->withoutOverlapping(180)
    ->onOneServer();

Schedule::command('royalties:book-recurring-expenses')->dailyAt('04:00')->withoutOverlapping(60)->onOneServer();

// Ten tracks a day keep a catalog of a few hundred tracks researched about once a month.
Schedule::command('royalties:research-airplay')->dailyAt('06:30')->withoutOverlapping(60)->onOneServer();

// Does nothing unless a backup is due at the hour set under Settings → Backups, or one was asked for there.
Schedule::command('royalties:backup --scheduled')->everyMinute()->withoutOverlapping(720)->onOneServer()->runInBackground();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::call(fn () => DirectUploads::prune())->name('prune-unreported-uploads')->dailyAt('03:30')->onOneServer();

// Does nothing unless an update was asked for under Settings → Updates, or automatic updates are due.
Schedule::command('royalties:update --scheduled')->everyMinute()->withoutOverlapping(120)->onOneServer();
