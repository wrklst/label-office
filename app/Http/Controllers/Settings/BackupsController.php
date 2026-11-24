<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Backups;
use App\Support\InstallationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The nightly backup of the database and the files: whether and when
 * it runs, how many backups are kept and which bucket they go to. For
 * the installation's own admins only. A backup is made by the
 * scheduler, never by the request that asks for it.
 */
class BackupsController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()->installation_admin, 403);

        $settings = Backups::settings();
        $available = Backups::available();

        return Inertia::render('settings/backups', [
            'available' => $available,
            'settings' => [
                'enabled' => $settings['enabled'],
                'hour' => $settings['hour'],
                'keep' => $settings['keep'],
                'requested' => $settings['requested'],
                'restore' => $settings['restore'],
                'last' => $settings['last'],
            ],
            // The secret never goes back to the page.
            'bucket' => $settings['bucket'] === null ? null : [
                'bucket' => $settings['bucket']['bucket'] ?? null,
                'endpoint' => $settings['bucket']['endpoint'] ?? null,
                'region' => $settings['bucket']['region'] ?? null,
                'key_hint' => '••••'.mb_substr((string) ($settings['bucket']['key'] ?? ''), -4),
            ],
            'filesBucket' => config('filesystems.disks.s3.bucket'),
            'backups' => $available ? $this->listed() : ['items' => [], 'error' => null],
            'serverTime' => now()->format('H:i'),
        ]);
    }

    /**
     * Whether a backup is made every night, at which hour, and how many are kept of each tier.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->installation_admin, 403);

        // At least one of each: with none kept of a tier, its backup would be deleted the moment it is made.
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'hour' => ['required', 'integer', 'between:0,23'],
            'keep' => ['required', 'array:'.implode(',', Backups::TIERS)],
            'keep.*' => ['required', 'integer', 'between:1,99'],
        ]);

        Backups::remember([
            'enabled' => (bool) $validated['enabled'],
            'hour' => (int) $validated['hour'],
            'keep' => [...Backups::settings()['keep'], ...array_map(intval(...), $validated['keep'])],
        ]);

        return $this->saved($validated['enabled'] ? __('A backup is made every night.') : __('Backups are made only when you ask for one.'));
    }

    /**
     * A bucket for the backups alone. It is proven to take and return a file before it is used.
     */
    public function bucket(Request $request): RedirectResponse
    {
        abort_unless($request->user()->installation_admin, 403);

        $validated = $request->validate([
            'bucket' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:255'],
            'secret' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'url:https', 'max:255'],
            'path_style' => ['boolean'],
        ]);

        $bucket = [
            'key' => $validated['key'],
            'secret' => $validated['secret'],
            'region' => $validated['region'] ?? null,
            'bucket' => $validated['bucket'],
            'endpoint' => $validated['endpoint'] ?? null,
            'path_style' => $request->boolean('path_style'),
        ];

        try {
            InstallationSettings::probe($bucket);
        } catch (Throwable $exception) {
            // Also what the storage library refuses before it asks the bucket, such as a missing region.
            throw ValidationException::withMessages(['bucket' => $exception instanceof RuntimeException ? $exception->getMessage() : __('The bucket could not be used: :answer', ['answer' => mb_substr($exception->getMessage(), 0, 300)])]);
        }

        Backups::remember(['bucket' => $bucket]);
        Cache::forget(Backups::LISTED);

        return $this->saved(__('Backups go to their own bucket from now on. Backups made before stay where they are.'));
    }

    /**
     * Forget the backups' own bucket. They go beside the files again.
     */
    public function destroyBucket(Request $request): RedirectResponse
    {
        abort_unless($request->user()->installation_admin, 403);

        Backups::remember(['bucket' => null]);
        Cache::forget(Backups::LISTED);

        return $this->saved(__('Backups go to the bucket for files again.'));
    }

    /**
     * Have the scheduler make a backup within the next minute.
     */
    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->user()->installation_admin, 403);

        if (! Backups::available()) {
            throw ValidationException::withMessages(['backup' => __('Connect a bucket first: there is nowhere to put a backup.')]);
        }

        Backups::remember(['requested' => true]);

        return $this->saved(__('The backup starts within a minute.'));
    }

    /**
     * Have the scheduler put a backup back within the next minute: its
     * files, and its database in place of the one there is.
     */
    public function restore(Request $request): RedirectResponse
    {
        abort_unless($request->user()->installation_admin, 403);

        // Typed out, so that no single click replaces the database.
        $validated = $request->validate(
            ['backup' => ['required', 'string', 'max:255'], 'confirmation' => ['required', 'same:backup']],
            ['confirmation.*' => __('Type the name of the backup exactly as it is shown.')],
        );

        if (! Backups::available() || ! in_array($validated['backup'], Backups::names(), true)) {
            throw ValidationException::withMessages(['backup' => __('There is no such backup in the bucket.')]);
        }

        Backups::remember(['restore' => $validated['backup']]);

        return $this->saved(__('The backup is put back within a minute. Sign in again afterwards, with an account as it was then.'));
    }

    /**
     * The backups in the bucket, newest first; asked for at most every ten minutes.
     *
     * @return array{items: list<array{name: string, made_at: string, files: int, copied: int}>, error: ?string}
     */
    private function listed(): array
    {
        try {
            return ['items' => Cache::remember(Backups::LISTED, 600, fn (): array => array_map(function (string $name): array {
                $manifest = Backups::manifest($name);

                return ['name' => $name, 'made_at' => $manifest['made_at'], 'files' => count($manifest['files']), 'copied' => $manifest['copied']];
            }, array_reverse(Backups::names()))), 'error' => null];
        } catch (Throwable $exception) {
            return ['items' => [], 'error' => mb_substr($exception->getMessage(), 0, 300)];
        }
    }

    private function saved(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
