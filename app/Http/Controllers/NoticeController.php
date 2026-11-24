<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The standing notices of the application, such as the one that it
 * guarantees nothing about the law. Each person can acknowledge one,
 * which hides it for them, and bring them all back.
 */
class NoticeController extends Controller
{
    /** @var list<string> The notices that can be hidden. */
    public const array KEYS = ['legal', 'examples'];

    public function hide(Request $request, string $notice): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['hidden_notices' => array_values(array_unique([...($user->hidden_notices ?? []), $notice]))])->save();

        return back();
    }

    public function restore(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['hidden_notices' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The notices are shown again.')]);

        return back();
    }
}
