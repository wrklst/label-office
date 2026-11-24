<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgreementRequest;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Notifications\AgreementToSign;
use App\Support\CurrentLabel;
use App\Support\Options;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class AgreementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('agreements/index', [
            'agreements' => Agreement::query()->with('payee:id,name')->latest('id')->paginate(PageSize::MAIN)
                ->through(fn (Agreement $agreement): array => [
                    ...$agreement->only(['id', 'name', 'status']),
                    'payee' => $agreement->payee?->name,
                    'sent_at' => $agreement->sent_at?->toDateString(),
                    'signed_at' => $agreement->signed_at?->toDateString(),
                ]),
            'hasTemplates' => AgreementTemplate::query()->exists(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('agreements/form', ['agreement' => null, ...$this->options()]);
    }

    public function store(AgreementRequest $request): RedirectResponse
    {
        $agreement = Agreement::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agreement drafted.')]);

        return to_route('agreements.show', $agreement->id);
    }

    public function show(Agreement $agreement): Response
    {
        $agreement->load(['template', 'signatures', 'payee:id,name']);
        $parties = $agreement->parties ?? $agreement->currentParties();

        return Inertia::render('agreements/show', [
            'agreement' => [
                ...$agreement->only(['id', 'name', 'status', 'licensor', 'body_hash']),
                'payee' => $agreement->payee?->name,
                'template' => $agreement->template?->name,
                'text' => $agreement->text(),
                'sent_at' => $agreement->sent_at?->toIso8601String(),
                'signed_at' => $agreement->signed_at?->toIso8601String(),
                'send_block' => $agreement->status === Agreement::DRAFT ? $agreement->sendBlock() : null,
                'label_party' => $parties['label'],
                'signatories' => array_column($parties['signatories'], 'name'),
                'lines' => $this->lines($agreement),
                'people' => $agreement->status === Agreement::DRAFT ? [] : $agreement->people()->pluck('name')->all(),
            ],
        ]);
    }

    public function edit(Agreement $agreement): Response|RedirectResponse
    {
        if ($agreement->status !== Agreement::DRAFT) {
            return to_route('agreements.show', $agreement->id);
        }

        return Inertia::render('agreements/form', [
            'agreement' => $agreement->only(['id', 'name', 'agreement_template_id', 'licensor', 'payee_id', 'artist_payee_id', 'release_id', 'track_id', 'terms']),
            ...$this->options(),
        ]);
    }

    public function update(AgreementRequest $request, Agreement $agreement): RedirectResponse
    {
        abort_unless($agreement->status === Agreement::DRAFT, 409, 'An agreement that was sent cannot be changed.');

        $agreement->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agreement saved.')]);

        return to_route('agreements.show', $agreement->id);
    }

    /**
     * Freeze the text and ask everyone who has an account to sign.
     */
    public function send(Agreement $agreement, CurrentLabel $currentLabel): RedirectResponse
    {
        if ($reason = $agreement->sendBlock()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $reason]);

            return back();
        }

        $agreement->send();

        Notification::send($agreement->people(), new AgreementToSign($agreement, (string) $currentLabel->get()?->name));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent for signature. The text can no longer be changed.')]);

        return back();
    }

    /**
     * Withdraw an agreement that is out for signature. A signed one
     * stands: ending it is a matter between the parties, not a button.
     */
    public function void(Agreement $agreement): RedirectResponse
    {
        abort_unless($agreement->status === Agreement::SENT, 409, 'Only an agreement that is out for signature can be withdrawn.');

        $agreement->forceFill(['status' => Agreement::VOID, 'voided_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agreement withdrawn. It can no longer be signed.')]);

        return back();
    }

    public function destroy(Agreement $agreement): RedirectResponse
    {
        abort_unless($agreement->status === Agreement::DRAFT, 409, 'Only a draft can be deleted.');

        $agreement->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft deleted.')]);

        return to_route('agreements.index');
    }

    /**
     * @return list<array{capacity: string, name: string, signed_by: string|null, signed_at: string|null}>
     */
    private function lines(Agreement $agreement): array
    {
        return array_map(fn (array $line): array => [
            'capacity' => $line['capacity'],
            'name' => $line['name'],
            'signed_by' => $line['signature']?->name,
            'signed_at' => $line['signature']?->signed_at->toIso8601String(),
        ], $agreement->parties === null ? [] : $agreement->signatureLines());
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'templateOptions' => AgreementTemplate::query()->orderBy('name')->get(['id', 'name', 'body'])->map(fn (AgreementTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                // Which of the terms the template asks for, so the form shows no others.
                'terms' => array_keys(array_filter(Agreement::TERMS, fn (string $field): bool => preg_match('/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/', $template->body) === 1)),
            ]),
            'payeeOptions' => Options::payees(),
            'releaseOptions' => Options::releases(),
            'trackOptions' => Options::tracks(),
        ];
    }
}
