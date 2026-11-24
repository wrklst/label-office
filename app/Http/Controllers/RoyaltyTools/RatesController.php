<?php

namespace App\Http\Controllers\RoyaltyTools;

use App\Enums\RoyaltyTool;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Label;
use App\Models\Release;
use App\Models\Track;
use App\Support\CurrentLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The rates of one release, its tracks and its items in one place, for
 * the mechanicals and the PPD tool.
 */
class RatesController extends Controller
{
    public function __construct(private readonly CurrentLabel $currentLabel) {}

    public function edit(RoyaltyTool $tool, Release $release): Response
    {
        $label = $this->label($tool);
        $rate = $tool === RoyaltyTool::Mechanicals ? 'mechanicals_rate' : 'ppd_rate';
        $plain = fn (mixed $value): ?string => $value === null ? null : (str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value);

        return Inertia::render('royalty-tools/rates', [
            'tool' => $tool->value,
            'currency' => $label->currency,
            'release' => [
                ...$release->only(['id', 'name', 'catalog_number', 'accounts_mechanicals']),
                'rate' => $plain($release->getAttribute($rate)),
            ],
            'tracks' => $release->tracks()->get()->map(fn (Track $track): array => [
                ...$track->only(['id', 'name', 'track_number', 'duration', 'accounts_mechanicals']),
                'rate' => $plain($track->getAttribute($rate)),
            ]),
            // The items the tools book on never carry a rate of their own.
            'items' => $tool === RoyaltyTool::Ppd
                ? $release->items()->whereNull('tool_role')->with('track:id,name')->orderBy('name')->get()->map(fn (Item $item): array => [
                    ...$item->only(['id', 'name', 'format_group']),
                    'track' => $item->track?->name,
                    'rate' => $plain($item->ppd_rate),
                ])
                : [],
        ]);
    }

    public function update(Request $request, RoyaltyTool $tool, Release $release): RedirectResponse
    {
        $this->label($tool);
        $rate = $tool === RoyaltyTool::Mechanicals ? 'mechanicals_rate' : 'ppd_rate';
        $rateRules = ['nullable', 'numeric', 'decimal:0,6', 'min:0', 'max:999999'];

        $input = $request->validate([
            'rate' => $rateRules,
            'accounts_mechanicals' => [Rule::requiredIf($tool === RoyaltyTool::Mechanicals), 'boolean'],
            'tracks' => ['array'],
            'tracks.*.id' => ['required', 'integer', 'distinct', Rule::exists('release_track', 'track_id')->where('release_id', $release->id)],
            'tracks.*.rate' => $rateRules,
            'tracks.*.accounts_mechanicals' => ['nullable', 'boolean'],
            'items' => ['array'],
            'items.*.id' => ['required', 'integer', 'distinct', Rule::exists('items', 'id')->where('release_id', $release->id)->whereNull('tool_role')],
            'items.*.rate' => $rateRules,
        ]);

        DB::transaction(function () use ($input, $tool, $release, $rate): void {
            $mechanicals = $tool === RoyaltyTool::Mechanicals;

            $release->update([$rate => $input['rate'] ?? null, ...($mechanicals ? ['accounts_mechanicals' => (bool) $input['accounts_mechanicals']] : [])]);

            foreach ($input['tracks'] ?? [] as $row) {
                $flag = $row['accounts_mechanicals'] ?? null;

                $release->tracks()->whereKey($row['id'])->firstOrFail()->update([
                    $rate => $row['rate'] ?? null,
                    ...($mechanicals ? ['accounts_mechanicals' => $flag === null ? null : (bool) $flag] : []),
                ]);
            }

            if (! $mechanicals) {
                foreach ($input['items'] ?? [] as $row) {
                    $release->items()->whereKey($row['id'])->firstOrFail()->update(['ppd_rate' => $row['rate'] ?? null]);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rates saved.')]);

        return back();
    }

    private function label(RoyaltyTool $tool): Label
    {
        $label = $this->currentLabel->get() ?? abort(404);

        abort_unless($tool !== RoyaltyTool::Reserves && $tool->enabledFor($label), 404);

        return $label;
    }
}
