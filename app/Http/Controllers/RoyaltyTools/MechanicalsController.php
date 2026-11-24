<?php

namespace App\Http\Controllers\RoyaltyTools;

use App\Enums\RoyaltyTool;
use App\Services\Royalties\Tools\MechanicalsTool;
use App\Services\Royalties\Tools\ToolRuns;
use App\Services\Royalties\Tools\ToolSettings;
use App\Support\CurrentLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MechanicalsController extends ToolController
{
    public function __construct(CurrentLabel $currentLabel, ToolRuns $runs, private readonly MechanicalsTool $mechanicals)
    {
        parent::__construct($currentLabel, $runs);
    }

    protected function tool(): RoyaltyTool
    {
        return RoyaltyTool::Mechanicals;
    }

    public function index(Request $request): Response
    {
        $label = $this->label();
        $input = $request->hasAny(['from', 'to']) ? $request->validate($this->rules($label->id)) : null;
        $settings = ToolSettings::for($label);
        $table = (string) ($input['rates'] ?? MechanicalsTool::defaultRateTable());

        return Inertia::render('royalty-tools/mechanicals', [
            ...$this->common($request, $label, $input['from'] ?? null, $input['to'] ?? null),
            'filters' => [
                'from' => $input['from'] ?? null,
                'to' => $input['to'] ?? null,
                'rates' => $table,
                'manufacturing' => array_values($input['manufacturing'] ?? []),
            ],
            'settings' => [
                'usd_rate' => $settings->usdRate === null ? null : MechanicalsTool::plain($settings->usdRate),
                'include_physical' => $settings->includePhysical,
                'ignore_negative_quantities' => $settings->ignoreNegativeQuantities,
            ],
            'rateTables' => collect(MechanicalsTool::rateTables())->map(fn (array $rates, int|string $key): array => ['key' => (string) $key, ...$rates])->values(),
            'report' => $input === null ? null : $this->mechanicals->report($label, $input['from'], $input['to'], $table, $this->manufacturing($input)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $label = $this->label();
        $input = $request->validate([...$this->rules($label->id), ...$this->bookingRules()]);
        $table = (string) ($input['rates'] ?? MechanicalsTool::defaultRateTable());
        $manufacturing = $this->manufacturing($input);

        $report = $this->mechanicals->report($label, $input['from'], $input['to'], $table, $manufacturing);

        return $this->book($request, $label, $input['from'], $input['to'], $input['paid_on'], $report['total'], [
            'rates' => $table,
            'manufacturing' => $manufacturing,
        ], $this->mechanicals->lines($report, $input['paid_on']));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(int $labelId): array
    {
        return [
            ...$this->rangeRules(),
            'rates' => ['nullable', Rule::in(array_map(strval(...), array_keys(MechanicalsTool::rateTables())))],
            'manufacturing' => ['nullable', 'array', 'max:200'],
            'manufacturing.*.release_id' => ['required', 'integer', 'distinct', Rule::exists('releases', 'id')->where('label_id', $labelId)],
            'manufacturing.*.quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, numeric-string>
     */
    private function manufacturing(array $input): array
    {
        $runs = [];

        foreach ($input['manufacturing'] ?? [] as $run) {
            $runs[(int) $run['release_id']] = (string) (int) $run['quantity'];
        }

        return $runs;
    }
}
