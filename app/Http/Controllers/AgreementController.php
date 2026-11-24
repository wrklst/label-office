<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgreementRequest;
use App\Models\Agreement;
use App\Models\AgreementDeliverable;
use App\Models\AgreementTemplate;
use App\Models\Contract;
use App\Models\Payee;
use App\Models\Release;
use App\Models\Track;
use App\Notifications\AgreementToSign;
use App\Notifications\DeliverableReturned;
use App\Support\AgreementExamples;
use App\Support\CurrentLabel;
use App\Support\IncomingFile;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgreementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('agreements/index', [
            'agreements' => Agreement::query()->with(['payee:id,name', 'releases:id,name', 'tracks:id,name', 'tracks.releases:id'])->latest('id')->paginate(PageSize::MAIN)
                ->through(fn (Agreement $agreement): array => [
                    ...$agreement->only(['id', 'name', 'status']),
                    'payee' => $agreement->payee?->name,
                    'unmatched' => $agreement->isFiled() && $agreement->payee_id === null,
                    // Each release with the tracks chosen from it; without any, the whole release.
                    'covers' => $agreement->releases->map(fn (Release $release): array => [
                        'release' => $release->name,
                        'tracks' => $agreement->tracks->filter(fn (Track $track): bool => $track->releases->contains('id', $release->id))->pluck('name')->values()->all(),
                    ])->values()->all(),
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
        $agreement = Agreement::query()->create($request->safe()->except(['release_ids', 'track_ids']));
        $agreement->cover($request->validated('release_ids', []), $request->validated('track_ids', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agreement drafted.')]);

        return to_route('agreements.show', $agreement->id);
    }

    public function show(Request $request, Agreement $agreement): Response
    {
        $agreement->load(['template', 'signatures', 'payee:id,name', 'deliverables.file']);
        $parties = $agreement->parties ?? $agreement->currentParties();

        return Inertia::render('agreements/show', [
            'agreement' => [
                ...$agreement->only(['id', 'name', 'status', 'licensor', 'body_hash', 'contract_id', 'payee_id']),
                'contract' => $agreement->contract_id ? Contract::query()->find($agreement->contract_id)?->name : null,
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
                'coverage' => $agreement->coverage(),
                'deliverables' => $agreement->deliverables->map->toRow()->all(),
                'planned_deliverables' => $agreement->status === Agreement::DRAFT ? $agreement->deliverableLines() : [],
                'signed_copy' => $agreement->signed_copy_name,
                'delete_block' => $agreement->deleteBlock(),
                'has_text' => ! $agreement->isFiled(),
                'filed' => $agreement->isFiled() ? [
                    ...$agreement->only(['payee_id', 'contract_id']),
                    'signed_on' => $agreement->signed_at?->toDateString(),
                ] : null,
                'can_sign' => $agreement->status === Agreement::SENT && $agreement->capacitiesOf($request->user()) !== [],
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
            'agreement' => [...$agreement->only(['id', 'name', 'agreement_template_id', 'licensor', 'payee_id', 'artist_payee_id', 'terms']), 'coverage' => $agreement->coverage()],
            ...$this->options(),
        ]);
    }

    public function update(AgreementRequest $request, Agreement $agreement): RedirectResponse
    {
        abort_unless($agreement->status === Agreement::DRAFT, 409, 'An agreement that was sent cannot be changed.');

        $agreement->update($request->safe()->except(['release_ids', 'track_ids']));
        $agreement->cover($request->validated('release_ids', []), $request->validated('track_ids', []));

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
     * File a copy that was signed outside the application, such as an
     * older agreement on paper: dropped as it is, named after its file
     * and matched to a payee when exactly one is named in the file name.
     * Everything else is matched afterwards on the agreement.
     */
    public function storeFiled(Request $request, CurrentLabel $currentLabel): RedirectResponse
    {
        $file = $this->signedCopyFrom($request, (int) $currentLabel->id());
        $title = trim((string) preg_replace('/[\s_-]+/', ' ', pathinfo($file->name, PATHINFO_FILENAME))) ?: 'Signed agreement';
        $named = Payee::query()->get(['id', 'name'])->filter(fn (Payee $payee): bool => mb_strlen($payee->name) > 2 && mb_stripos($title, $payee->name) !== false);

        DB::transaction(function () use ($file, $title, $named): void {
            $agreement = Agreement::query()->create(['name' => Str::limit($title, 250, ''), 'payee_id' => $named->count() === 1 ? $named->first()->id : null, 'licensor' => 'label']);
            $agreement->forceFill(['status' => Agreement::SIGNED])->save();
            $agreement->keepSignedCopy($file);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name filed. Match it to its payee and contract.', ['name' => $file->name])]);

        return back();
    }

    /**
     * Say what a filed copy belongs to: its payee and contract, picked
     * from what exists or made new here, and its release and track.
     */
    public function match(Request $request, Agreement $agreement, CurrentLabel $currentLabel): RedirectResponse
    {
        abort_unless($agreement->isFiled(), 409, 'Only a filed copy is matched this way.');

        $inLabel = fn (string $table) => Rule::exists($table, 'id')->where('label_id', $currentLabel->id());
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'signed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'payee_id' => ['nullable', 'integer', $inLabel('payees')],
            'new_payee' => ['nullable', 'string', 'max:255'],
            'contract_id' => ['nullable', 'integer', $inLabel('contracts')],
            'new_contract' => ['boolean'],
            'release_ids' => ['array'],
            'release_ids.*' => ['integer', 'distinct', $inLabel('releases')],
            'track_ids' => ['array'],
            'track_ids.*' => ['integer', Rule::exists('release_track', 'track_id')->whereIn('release_id', array_filter((array) $request->input('release_ids'), 'is_numeric') ?: [0])],
        ]);

        DB::transaction(function () use ($agreement, $input): void {
            // Something typed as new wins over what was picked: it was typed because the list did not have it.
            $payeeId = filled($input['new_payee'] ?? null) ? Payee::query()->create(['name' => $input['new_payee']])->id : ($input['payee_id'] ?? null);
            $contractId = ($input['new_contract'] ?? false)
                ? Contract::query()->create(['name' => $input['name'], 'memo' => 'For the filed agreement "'.$input['name'].'". Add its splits.'])->id
                : ($input['contract_id'] ?? null);

            $agreement->forceFill([
                'name' => $input['name'],
                'signed_at' => $input['signed_on'] ?? null,
                'payee_id' => $payeeId,
                'contract_id' => $contractId,
            ])->save();
            $agreement->cover($input['release_ids'] ?? [], $input['track_ids'] ?? []);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agreement matched.')]);

        return back();
    }

    /**
     * A signed copy as it was handed over, posted here or sent to the
     * bucket: a document or a scan, small enough to read as one.
     */
    private function signedCopyFrom(Request $request, int $labelId): IncomingFile
    {
        $request->validate(['file' => ['required_without:upload', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'], ...IncomingFile::RULES]);

        return IncomingFile::from($request, $labelId, 20480, ['application/pdf', 'image/jpeg', 'image/png'], ['pdf', 'jpg', 'jpeg', 'png'], ['type' => __('A signed copy is a PDF, JPEG or PNG.')]);
    }

    /**
     * Close an agreement that is out for signature with a copy the
     * parties signed elsewhere, on paper or with another service.
     */
    public function signedCopy(Request $request, Agreement $agreement): RedirectResponse
    {
        abort_unless($agreement->status === Agreement::SENT, 409, 'A signed copy closes an agreement that is out for signature.');

        $request->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Confirm that every party has signed this copy.']);
        $file = $this->signedCopyFrom($request, $agreement->label_id);

        DB::transaction(function () use ($agreement, $file): void {
            $agreement->keepSignedCopy($file);
            $agreement->forceFill(['status' => Agreement::SIGNED, 'signed_at' => now()])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed copy kept. The agreement counts as signed by everyone.')]);

        return back();
    }

    public function downloadSignedCopy(Request $request, Agreement $agreement): StreamedResponse
    {
        return $agreement->signedCopyResponse($request->boolean('inline'));
    }

    /**
     * Take what was delivered, or hand it back with a reason.
     */
    public function accept(Agreement $agreement, AgreementDeliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->agreement_id === $agreement->id && $deliverable->status === AgreementDeliverable::DELIVERED, 409, 'Only something that was delivered can be accepted.');

        $deliverable->update(['status' => AgreementDeliverable::ACCEPTED, 'accepted_at' => now(), 'returned_note' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Accepted.')]);

        return back();
    }

    public function return(Request $request, Agreement $agreement, AgreementDeliverable $deliverable): RedirectResponse
    {
        abort_unless($deliverable->agreement_id === $agreement->id && in_array($deliverable->status, [AgreementDeliverable::DELIVERED, AgreementDeliverable::ACCEPTED]), 409, 'Only something that was delivered can be handed back.');

        $input = $request->validate(['returned_note' => ['required', 'string', 'max:2000']]);

        $deliverable->update(['status' => AgreementDeliverable::RETURNED, 'accepted_at' => null, ...$input]);

        Notification::send($agreement->people()->filter(fn ($user): bool => in_array('payee', $agreement->capacitiesOf($user))), new DeliverableReturned($agreement, $deliverable->title, $input['returned_note']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Handed back. The payee is told why and can deliver again.')]);

        return back();
    }

    /**
     * A release with its tracks, for the form that chooses what an
     * agreement covers.
     */
    public function release(Request $request, Release $release): JsonResponse
    {
        // With a contract or payee chosen, the tracks their contracts pay for come ticked: those the contract is set on.
        $contracts = $request->integer('contract_id')
            ? [$request->integer('contract_id')]
            : Contract::query()->whereHas('splits', fn ($splits) => $splits->where('payee_id', $request->integer('payee_id')))->pluck('id')->all();
        $chosen = array_values($release->tracks()
            ->whereIn('tracks.contract_id', $contracts ?: [0])
            ->pluck('tracks.id')->map(fn (mixed $id): int => (int) $id)->all());

        return response()->json(Agreement::releaseRow($release, $chosen));
    }

    /**
     * The agreement as a PDF, once it was sent.
     */
    public function pdf(Request $request, Agreement $agreement): HttpResponse
    {
        abort_if($agreement->status === Agreement::DRAFT || $agreement->body === null, 404);

        return $agreement->load('signatures')->pdfResponse($request->boolean('inline'));
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
     * payee at the agreed share. It is set on the linked tracks, or else
     * on all tracks of the linked release, unless a track already has a contract: income
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

        // What the contract goes on: the chosen tracks of each release, or all its tracks when none is chosen.
        $targets = collect($agreement->releases()->get())->flatMap(function (Release $release) use ($agreement) {
            $tracks = $agreement->tracks()->onRelease($release->id)->get();

            return $tracks->isEmpty() ? $release->tracks()->get()->all() : $tracks->all();
        });
        // The same recording on another release, such as a single beside the album, is paid the same way.
        $isrcs = $targets->whereInstanceOf(Track::class)->pluck('isrc')->filter()->unique()->all();
        $sameRecording = $isrcs === [] ? collect() : Track::query()->whereIn('isrc', $isrcs)->whereNotIn('id', $targets->whereInstanceOf(Track::class)->map->getKey()->all() ?: [0])->with('release:id,name')->get();

        $contract = DB::transaction(function () use ($agreement, $share): Contract {
            $contract = Contract::query()->create([
                'name' => $agreement->name,
                'memo' => "From the agreement \"{$agreement->name}\", signed by everyone on {$agreement->signed_at?->toDateString()}.",
            ]);
            $contract->splits()->create(['payee_id' => $agreement->payee_id, 'split_amount' => bcdiv((string) $share, '100', 9)]);

            // The original artist who signed as a party has a share of their own; the label keeps the rest.
            $artistShare = $agreement->terms['artist_share'] ?? null;

            if ($agreement->artist_payee_id !== null && is_numeric($artistShare) && (float) $artistShare > 0) {
                $contract->splits()->create(['payee_id' => $agreement->artist_payee_id, 'split_amount' => bcdiv((string) $artistShare, '100', 9)]);
            }
            $agreement->forceFill(['contract_id' => $contract->id])->save();

            return $contract;
        });

        // Income that is accounted for one way is not silently accounted another: what has a contract keeps it.
        $set = [];
        $left = [];

        foreach ([...$targets, ...$sameRecording] as $target) {
            $name = $target instanceof Track && $sameRecording->contains($target) ? "{$target->name} ({$target->release?->name})" : $target->name;

            if ($target->contract_id === null) {
                $target->update(['contract_id' => $contract->id]);
                $set[] = $name;
            } else {
                $left[] = $name;
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => implode(' ', array_filter([
            __('Contract created.'),
            $set === [] ? null : __('Set on: :names.', ['names' => implode(', ', $set)]),
            $left === [] ? null : __('Left as they are, because they have a contract: :names.', ['names' => implode(', ', $left)]),
            $set === [] && $left === [] ? __('Set it on the track it pays for.') : null,
        ]))]);

        return to_route('contracts.show', $contract->id);
    }

    public function destroy(Agreement $agreement): RedirectResponse
    {
        abort_if($agreement->deleteBlock() !== null, 409, (string) $agreement->deleteBlock());

        $agreement->remove();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agreement deleted.')]);

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
        ], $agreement->parties === null ? [] : $agreement->shownLines());
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
        ];
    }
}
