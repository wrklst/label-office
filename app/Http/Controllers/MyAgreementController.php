<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\Label;
use App\Models\LoginCode;
use App\Notifications\AgreementSigned;
use App\Notifications\AgreementSigningCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where an agreement is read and signed, by a payee or by someone who
 * signs for the label. Nothing here goes by the label the user works
 * in: an agreement is reached only through the user's own memberships.
 */
class MyAgreementController extends Controller
{
    public function index(Request $request): Response
    {
        $labels = Label::query()->pluck('name', 'id');

        return Inertia::render('my-agreements/index', [
            'agreements' => Agreement::query()->forPayeeUser($request->user())->with('signatures')->latest('id')->get()
                ->map(fn (Agreement $agreement): array => [
                    ...$agreement->only(['id', 'name', 'status']),
                    'label' => $labels[$agreement->label_id] ?? null,
                    'sent_at' => $agreement->sent_at?->toDateString(),
                    'to_sign' => $this->open($agreement, $request) !== [],
                ]),
        ]);
    }

    public function show(Request $request, int $agreement): Response
    {
        $agreement = $this->find($request, $agreement);

        return Inertia::render('my-agreements/show', [
            'agreement' => [
                ...$agreement->only(['id', 'name', 'status', 'body_hash']),
                'label' => Label::query()->whereKey($agreement->label_id)->value('name'),
                'text' => $agreement->text(),
                'sent_at' => $agreement->sent_at?->toIso8601String(),
                'signed_at' => $agreement->signed_at?->toIso8601String(),
                'lines' => array_map(fn (array $line): array => [
                    'capacity' => $line['capacity'],
                    'name' => $line['name'],
                    'signed_by' => $line['signature']?->name,
                    'signed_at' => $line['signature']?->signed_at->toIso8601String(),
                ], $agreement->signatureLines()),
                'capacities' => $this->open($agreement, $request),
                'blocked' => collect($agreement->capacitiesOf($request->user()))
                    ->map(fn (string $capacity): ?string => $agreement->status === Agreement::SENT ? $agreement->signBlock($request->user(), $capacity) : null)
                    ->filter()->unique()->values()->all(),
                'is_admin' => $agreement->isRunBy($request->user()),
            ],
        ]);
    }

    /**
     * The agreement as a PDF, once it was sent.
     */
    public function pdf(Request $request, int $agreement): HttpResponse
    {
        return $this->find($request, $agreement)->pdfResponse();
    }

    /**
     * Email the code the signature is confirmed with.
     */
    public function code(Request $request, int $agreement): RedirectResponse
    {
        $agreement = $this->find($request, $agreement);

        abort_if($this->open($agreement, $request) === [], 403);

        // ponytail: shares the sign-in codes, so asking for one replaces an unused sign-in code of the same account. Both go to the same mailbox; give signing its own table if the two ever need different rules.
        $request->user()->notify(new AgreementSigningCode(LoginCode::issueFor($request->user())));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('We emailed you a code.')]);

        return back();
    }

    /**
     * Sign: the typed name, the consent and the emailed code together
     * are the signature.
     */
    public function sign(Request $request, int $agreement): RedirectResponse
    {
        $agreement = $this->find($request, $agreement);
        $user = $request->user();

        $input = $request->validate([
            'capacity' => ['required', Rule::in(Agreement::CAPACITIES)],
            'name' => ['required', 'string', 'max:255'],
            'consent' => ['accepted'],
            'body_hash' => ['required', 'string'],
            'code' => ['required', 'string'],
        ], ['consent.accepted' => 'Confirm that you have read the agreement and want to be bound by it.']);

        if ($reason = $agreement->signBlock($user, $input['capacity'])) {
            throw ValidationException::withMessages(['capacity' => $reason]);
        }

        // What is signed is the text the signer had in front of them.
        if (! hash_equals((string) $agreement->body_hash, $input['body_hash'])) {
            throw ValidationException::withMessages(['body_hash' => 'The agreement changed since you opened it. Reload the page and read it again.']);
        }

        if (! LoginCode::redeem($user, trim($input['code']))) {
            throw ValidationException::withMessages(['code' => 'This code is wrong or has expired. Ask for a new one.']);
        }

        // Two people linked to the same payee could sign at the same moment: the second one waits and is refused.
        $agreement = DB::transaction(function () use ($agreement, $user, $input, $request): Agreement {
            $locked = Agreement::query()->withoutGlobalScope('label')->lockForUpdate()->with('signatures')->findOrFail($agreement->id);

            if ($reason = $locked->signBlock($user, $input['capacity'])) {
                throw ValidationException::withMessages(['capacity' => $reason]);
            }

            $locked->sign($user, $input['capacity'], $input['name'], $request->ip(), $request->userAgent());

            return $locked;
        });

        if ($agreement->status === Agreement::SIGNED) {
            Notification::send($agreement->people(), new AgreementSigned($agreement, (string) Label::query()->whereKey($agreement->label_id)->value('name')));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $agreement->status === Agreement::SIGNED
            ? __('Signed. Every party has now signed; a copy is on its way to everyone by email.')
            : __('Signed. The agreement takes effect once every party has signed.')]);

        return back();
    }

    /**
     * An agreement the user is a party to or signs for, sent or later.
     */
    private function find(Request $request, int $id): Agreement
    {
        $agreement = Agreement::query()->withoutGlobalScope('label')->with('signatures')->where('status', '!=', Agreement::DRAFT)->findOrFail($id);

        abort_if($agreement->capacitiesOf($request->user()) === [], 404);

        return $agreement;
    }

    /**
     * The capacities the user can sign in right now.
     *
     * @return list<string>
     */
    private function open(Agreement $agreement, Request $request): array
    {
        return array_values(array_filter(
            $agreement->capacitiesOf($request->user()),
            fn (string $capacity): bool => $agreement->signBlock($request->user(), $capacity) === null,
        ));
    }
}
