<?php

namespace App\Http\Controllers\Settings;

use App\Enums\LabelRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\MemberRequest;
use App\Models\Payee;
use App\Models\User;
use App\Notifications\LabelInvitation;
use App\Support\CurrentLabel;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(CurrentLabel $currentLabel): Response
    {
        $payees = Payee::query()->pluck('name', 'id');

        return Inertia::render('settings/team', [
            'members' => $currentLabel->get()->users()->orderBy('name')->get()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'payee_id' => $user->pivot->payee_id,
                'signs_as_payee_id' => $user->pivot->signs_as_payee_id,
                'payee' => $payees[$user->pivot->payee_id] ?? null,
            ]),
            'payeeOptions' => Options::payees(),
        ]);
    }

    /**
     * Add a person to the label. Someone without an account gets one.
     * Either way they are emailed an invitation that leads to signing in
     * with a one-time code, so nothing in it can expire before it is read.
     */
    public function store(MemberRequest $request, CurrentLabel $currentLabel): RedirectResponse
    {
        $label = $currentLabel->get();
        $email = Str::lower($request->validated('email'));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && $label->users()->whereKey($user->id)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This person already has access to the label.')]);

            return back();
        }

        if (! $user) {
            $user = User::query()->create([
                'name' => $request->validated('name') ?? Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(32),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->hasVerifiedEmail()) {
            // Whoever holds this account never proved the address is theirs: they may have put it
            // there to be invited in its owner's place. Only the owner's code opens it from here on.
            $user->forceFill(['password' => Str::password(32)])->save();
            $user->revokeAccessExceptPassword();
        }

        $label->users()->attach($user, $this->pivot($request));
        $user->notify(new LabelInvitation($label, LabelRole::from($request->validated('role'))));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was added to the label.', ['name' => $user->name])]);

        return back();
    }

    public function update(MemberRequest $request, User $user, CurrentLabel $currentLabel): RedirectResponse
    {
        $label = $currentLabel->get();
        abort_unless($label->users()->whereKey($user->id)->exists(), 404);

        if ($request->validated('role') !== LabelRole::Admin->value && $this->isLastAdmin($user, $currentLabel)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('A label needs at least one admin.')]);

            return back();
        }

        $label->users()->updateExistingPivot($user->id, $this->pivot($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access saved.')]);

        return back();
    }

    public function destroy(User $user, CurrentLabel $currentLabel): RedirectResponse
    {
        abort_unless($currentLabel->get()->users()->whereKey($user->id)->exists(), 404);

        if ($this->isLastAdmin($user, $currentLabel)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('A label needs at least one admin.')]);

            return back();
        }

        $currentLabel->get()->users()->detach($user->id);

        if ($user->current_label_id === $currentLabel->id()) {
            $user->forceFill(['current_label_id' => null])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access removed.')]);

        return back();
    }

    /**
     * @return array{role: string, payee_id: int|null, signs_as_payee_id: int|null}
     */
    private function pivot(MemberRequest $request): array
    {
        $role = $request->validated('role');

        return [
            'role' => $role,
            'payee_id' => $role === LabelRole::Payee->value ? $request->validated('payee_id') : null,
            // An admin reads no statements as a payee, but can be a party to an agreement as one.
            'signs_as_payee_id' => $role === LabelRole::Admin->value ? $request->validated('signs_as_payee_id') : null,
        ];
    }

    private function isLastAdmin(User $user, CurrentLabel $currentLabel): bool
    {
        $admins = $currentLabel->get()->users()->wherePivot('role', LabelRole::Admin->value)->pluck('users.id');

        return $admins->count() === 1 && $admins->first() === $user->id;
    }
}
