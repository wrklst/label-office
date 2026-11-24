<?php

namespace App\Http\Controllers;

use App\Services\Royalties\ProfitabilityReport;
use App\Support\CurrentLabel;
use App\Support\DateRange;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @phpstan-import-type Report from ProfitabilityReport
 */
class ProfitabilityController extends Controller
{
    public function __construct(private readonly ProfitabilityReport $profitability, private readonly CurrentLabel $currentLabel) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('profitability/index', [
            ...$this->report($filters),
            'filters' => $filters,
        ]);
    }

    /**
     * Stream the table of the report as a CSV.
     */
    public function download(Request $request): StreamedResponse
    {
        $report = $this->report($this->filters($request));
        $money = fn (string $value): string => Decimal::round($value, 4);
        $margin = fn (?string $value): string => $value === null ? '' : Decimal::round(Decimal::mul($value, '100'), 2);

        return response()->streamDownload(function () use ($report, $money, $margin): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                throw new RuntimeException('The download stream could not be opened.');
            }

            // Text starting with =, +, - or @ would run as a formula in a spreadsheet.
            $safe = fn (mixed $cell): mixed => is_string($cell) && ! is_numeric($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell;
            $put = fn (array $row) => fputcsv($stream, array_map($safe, $row), ',', '"', '');

            $put(['Release', 'Catalog number', 'Artist', 'Income', 'Recoupable expenses', 'Net', 'Payee share', 'Label costs', 'Label result', 'Margin %', 'Advances given']);

            foreach ($report['rows'] as $row) {
                $put([
                    $row['release_name'], $row['catalog_number'], $row['artist_name'], $money($row['income']), $money($row['expense']), $money($row['net']),
                    $money($row['payee_share']), $money($row['label_costs']), $money($row['label_result']), $margin($row['margin']), $money($row['advances']),
                ]);
            }

            $totals = $report['totals'];
            $put([
                'Total', '', '', $money($totals['income']), $money($totals['expense']), $money($totals['net']),
                $money($totals['payee_share']), $money($totals['label_costs']), $money($totals['label_result']), $margin($totals['margin']), $money($totals['advances']),
            ]);

            fclose($stream);
        }, 'profitability.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{from: string|null, to: string|null, date: 'paid'|null, artist: int|null}
     */
    private function filters(Request $request): array
    {
        $request->validate(['artist' => ['nullable', 'integer']]);

        return [
            ...DateRange::fromRequest($request)->toArray(),
            'artist' => $request->integer('artist') ?: null,
        ];
    }

    /**
     * @param  array{from: string|null, to: string|null, date: 'paid'|null, artist: int|null}  $filters
     * @return Report
     */
    private function report(array $filters): array
    {
        $label = $this->currentLabel->get() ?? abort(404);

        return $this->profitability->forLabel($label, $filters['from'], $filters['to'], $filters['date'] === 'paid', $filters['artist']);
    }
}
