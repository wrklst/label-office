<?php

namespace App\Http\Controllers\RoyaltyTools;

use App\Enums\RoyaltyTool;
use App\Services\Royalties\Tools\PpdTool;
use App\Services\Royalties\Tools\ToolRuns;
use App\Services\Royalties\Tools\ToolSettings;
use App\Support\CurrentLabel;
use App\Support\Formats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PpdController extends ToolController
{
    public function __construct(CurrentLabel $currentLabel, ToolRuns $runs, private readonly PpdTool $ppd)
    {
        parent::__construct($currentLabel, $runs);
    }

    protected function tool(): RoyaltyTool
    {
        return RoyaltyTool::Ppd;
    }

    public function index(Request $request): Response
    {
        $label = $this->label();
        $input = $request->hasAny(['from', 'to']) ? $request->validate($this->rules($label->id)) : null;

        return Inertia::render('royalty-tools/ppd', [
            ...$this->common($request, $label, $input['from'] ?? null, $input['to'] ?? null),
            'filters' => [
                'from' => $input['from'] ?? null,
                'to' => $input['to'] ?? null,
                ...$this->filters($input ?? []),
            ],
            'settings' => ['ignore_negative_quantities' => ToolSettings::for($label)->ignoreNegativeQuantities],
            'report' => $input === null ? null : $this->ppd->report($label, $input['from'], $input['to'], $this->filters($input)),
            'formatOptions' => Formats::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $label = $this->label();
        $input = $request->validate([...$this->rules($label->id), ...$this->bookingRules()]);
        $filters = $this->filters($input);

        $report = $this->ppd->report($label, $input['from'], $input['to'], $filters);

        return $this->book($request, $label, $input['from'], $input['to'], $input['paid_on'], $report['total'], $filters, $this->ppd->lines($report, $input['paid_on']));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(int $labelId): array
    {
        return [
            ...$this->rangeRules(),
            'artist_id' => ['nullable', 'integer', Rule::exists('artists', 'id')->where('label_id', $labelId)],
            'release_id' => ['nullable', 'integer', Rule::exists('releases', 'id')->where('label_id', $labelId)],
            'format' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{artist_id: int|null, release_id: int|null, format: string|null}
     */
    private function filters(array $input): array
    {
        return [
            'artist_id' => isset($input['artist_id']) ? (int) $input['artist_id'] : null,
            'release_id' => isset($input['release_id']) ? (int) $input['release_id'] : null,
            'format' => isset($input['format']) ? (string) $input['format'] : null,
        ];
    }
}
