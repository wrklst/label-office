<?php

namespace App\Http\Middleware;

use App\Support\CurrentLabel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the agreement tools closed until an admin has accepted the
 * current terms of use for the label: using them is a decision the
 * label makes knowing what the software does not promise.
 */
class EnsureTermsAccepted
{
    public function __construct(private readonly CurrentLabel $currentLabel) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentLabel->get()?->hasAcceptedTerms()) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('terms.show');
        }

        abort(403, 'Accept the terms of use for the label first.');
    }
}
