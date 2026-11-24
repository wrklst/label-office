<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Services\Handbook\Handbook;
use App\Support\CurrentLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TermsController extends Controller
{
    public function show(Handbook $handbook, CurrentLabel $currentLabel): Response
    {
        $label = $currentLabel->get();
        $article = $handbook->find(Label::TERMS_ARTICLE) ?? abort(404);

        return Inertia::render('settings/terms', [
            'html' => $handbook->html($article),
            'version' => Label::TERMS_VERSION,
            'accepted' => $label->hasAcceptedTerms(),
            'acceptance' => $label->terms_accepted,
        ]);
    }

    /**
     * Accept the terms for the label, as the admin who is signed in.
     */
    public function store(Request $request, CurrentLabel $currentLabel): RedirectResponse
    {
        $request->validate(['accepted' => ['accepted']], ['accepted.accepted' => 'Tick the box to accept the terms for the label.']);

        $currentLabel->get()->update(['terms_accepted' => [
            'version' => Label::TERMS_VERSION,
            'user_id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'at' => now()->toIso8601String(),
        ]]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terms accepted for the label.')]);

        return to_route('agreements.index');
    }
}
