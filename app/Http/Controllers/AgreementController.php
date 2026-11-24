<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgreementRequest;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Contract;
use App\Models\Release;
use App\Models\Track;
use App\Notifications\AgreementToSign;
use App\Support\AgreementExamples;
use App\Support\CurrentLabel;
use App\Support\Options;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
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
            'disclaimer' => AgreementExamples::DISCLAIMER,
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
                ...$agreement->only(['id', 'name', 'status', 'licensor', 'body_hash', 'contract_id']),
                'share' => $agreement->terms['share'] ?? null,
                'payee' => $agreement->payee?->name,
                'template' => $agreement->template?->name,
                'text' => $agreement->text(),
                'sent_at' => $agreement->sent_at?->toIso8601String(),
                'signed_at' => $agreement->signed_at?->toIso8601String(),
                'send_block' => $agreement->status === Agreement::DRAFT ? $agreement->sendBlock() : null,
                'label_party' => $parties['label'],
                'signatories' => array_column($parties['signatories'], 'name'),
                'signing_rule' => $parties['rule'] ?? 'all',
                'disclaimer' => AgreementExamples::DISCLAIMER,
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
     * The agreement as a PDF, once it was sent.
     */
    public function pdf(Agreement $agreement): HttpResponse
    {
        abort_if($agreement->status === Agreement::DRAFT, 404);

        return $agreement->load('signatures')->pdfResponse();
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

    /**
     * Make the contract that pays what was signed: one split for the
     * payee at the agreed share. It is set on the linked track, or else
     * the linked release, unless that already has a contract: income
     * that is accounted for one way is not silently accounted another.
     */
    public function contract(Agreement $agreement): RedirectResponse
    {
        abort_unless($agreement->status === Agreement::SIGNED && $agreement->contract_id === null, 409, 'A contract is made once, from an agreement everyone has signed.');

        $share = $agreement->terms['share'] ?? null;

        if (! is_numeric($share) || (float) $share <= 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This agreement names no share to make a contract from.')]);

            return back();
        }

        $target = Track::query()->find($agreement->track_id) ?? Release::query()->find($agreement->release_id);

        $contract = DB::transaction(function () use ($agreement, $share, $target): Contract {
            $contract = Contract::query()->create([
                'name' => $agreement->name,
                'memo' => "From the agreement \"{$agreement->name}\", signed by everyone on {$agreement->signed_at?->toDateString()}.",
            ]);
            $contract->splits()->create(['payee_id' => $agreement->payee_id, 'split_amount' => bcdiv((string) $share, '100', 9)]);
            $agreement->forceFill(['contract_id' => $contract->id])->save();

            if ($target !== null && $target->contract_id === null) {
                $target->update(['contract_id' => $contract->id]);
            }

            return $contract;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $target === null => __('Contract created. Set it on the release or track it pays for.'),
            $target->contract_id === $contract->id => __('Contract created and set on :name.', ['name' => $target->name]),
            default => __('Contract created. :name already has a contract, which was left as it is.', ['name' => $target->name]),
        }]);

        return to_route('contracts.show', $contract->id);
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
