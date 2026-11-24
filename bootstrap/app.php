<?php

use App\Http\Middleware\EnsureLabelAdmin;
use App\Http\Middleware\EnsureServiceEnabled;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentLabel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // The updater empties the web server's code cache before it opens the site again.
        $middleware->preventRequestsDuringMaintenance(except: ['system/code-cache/reset']);

        // Links in emails and upload addresses are built from the host a request names.
        $middleware->trustHosts(at: fn (): array => array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)]));

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The label scope must be in place before route model binding
        // resolves records, or a record of another label could be loaded.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentLabel::class,
        );

        $middleware->alias([
            'label' => SetCurrentLabel::class,
            'label.admin' => EnsureLabelAdmin::class,
            'terms' => EnsureTermsAccepted::class,
            'service' => EnsureServiceEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
