<?php

namespace App\Http\Controllers\Settings;

use App\Enums\LabelRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LabelLegalRequest;
use App\Http\Requests\LabelSettingsRequest;
use App\Http\Requests\TerritoryRequest;
use App\Models\Territory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Royalties\Tools\MechanicalsTool;
use App\Services\Royalties\Tools\ToolSettings;
use App\Support\CurrentLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LabelSettingsController extends Controller
{
    public function edit(CurrentLabel $currentLabel): Response
    {
        $label = $currentLabel->get();
        $tools = ToolSettings::for($label);

        return Inertia::render('settings/label', [
            'settings' => [
                ...$label->only(['name', 'currency', 'contact_email', 'accounts_mechanicals', 'accounts_ppd', 'accounts_reserves']),
            ],
            'royaltyTools' => [
                'mechanicals_usd_rate' => $tools->usdRate === null ? null : MechanicalsTool::plain($tools->usdRate),
                'mechanicals_include_physical' => $tools->includePhysical,
                'ignore_negative_quantities' => $tools->ignoreNegativeQuantities,
            ],
            'legal' => $label->only(['legal_name', 'legal_form', 'legal_address', 'legal_register', 'signing_rule']),
            'admins' => $label->users()->wherePivot('role', LabelRole::Admin->value)->orderBy('name')->get()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'signs_for_label' => (bool) $user->pivot->signs_for_label,
                'self_dealing_exempt' => (bool) $user->pivot->self_dealing_exempt,
            ]),
            'territories' => Territory::query()->orderBy('name')->get()->map(fn (Territory $territory): array => [...$territory->only(['id', 'name', 'countries']), 'delete_block' => $territory->deleteBlock()]),
        ]);
    }

    public function update(LabelSettingsRequest $request, CurrentLabel $currentLabel): RedirectResponse
    {
        $label = $currentLabel->get();
        $tools = ToolSettings::for($label);

        if ($request->validated('currency') !== $label->currency && Transaction::query()->exists()) {
            throw ValidationException::withMessages([
                'currency' => "The label already has transactions in {$label->currency}. Changing the accounting currency would not convert them.",
            ]);
        }

        $label->update([
            ...$request->safe()->only(['name', 'currency', 'contact_email', 'accounts_mechanicals', 'accounts_ppd', 'accounts_reserves']),
            // A form that does not send the tool settings leaves them alone.
            'royalty_tool_settings' => [
                'usd_rate' => $request->has('mechanicals_usd_rate') ? $request->validated('mechanicals_usd_rate') : $tools->usdRate,
                'include_physical' => $request->boolean('mechanicals_include_physical', $tools->includePhysical),
                'ignore_negative_quantities' => $request->boolean('ignore_negative_quantities', $tools->ignoreNegativeQuantities),
            ],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Label settings saved.')]);

        return back();
    }

    /**
     * Who the label is on an agreement and whose signatures it needs.
     * Only admins sign for the label; anyone not ticked stops signing.
     */
    public function updateLegal(LabelLegalRequest $request, CurrentLabel $currentLabel): RedirectResponse
    {
        $label = $currentLabel->get();
        $signatories = $request->validated('signatories', []);

        $label->update($request->safe()->only(['legal_name', 'legal_form', 'legal_address', 'legal_register', 'signing_rule']));

        foreach ($label->users()->pluck('users.id') as $userId) {
            $label->users()->updateExistingPivot($userId, [
                'signs_for_label' => in_array($userId, $signatories),
                'self_dealing_exempt' => in_array($userId, $signatories) && in_array($userId, $request->validated('self_dealing_exempt', [])),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Legal details saved.')]);

        return back();
    }

    public function storeTerritory(TerritoryRequest $request): RedirectResponse
    {
        Territory::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Territory created.')]);

        return back();
    }

    /**
     * A territory that splits still refer to stays: without it they
     * would apply worldwide and pay out on every sale.
     */
    public function destroyTerritory(Territory $territory): RedirectResponse
    {
        if ($reason = $territory->deleteBlock()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $reason]);

            return back();
        }

        $territory->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Territory deleted.')]);

        return back();
    }
}
