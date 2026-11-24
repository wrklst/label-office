<?php

namespace App\Models;

use App\Enums\LabelRole;
use App\Models\Concerns\BelongsToLabel;
use App\Support\IncomingFile;
use App\Support\WorkingCopy;
use Carbon\CarbonImmutable;
use Database\Factories\AgreementFactory;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A written agreement between the label and a payee, made from a
 * template. While it is a draft its text follows the template and the
 * records it names; sending it freezes the text and the parties, and
 * every signature refers to that frozen text by its hash.
 *
 * @property int $id
 * @property int $label_id
 * @property int|null $agreement_template_id
 * @property string $name
 * @property string $status One of the STATUS constants.
 * @property string $licensor "label" or "artist": who grants the rights.
 * @property int|null $payee_id Null only for a signed copy that was filed and is not matched yet.
 * @property int|null $artist_payee_id The original artist, when they are a party of their own.
 * @property int|null $contract_id The contract made from the signed terms.
 * @property string|null $signed_copy_path A copy signed outside the application, as "disk:path".
 * @property string|null $signed_copy_name
 * @property array<string, string|null>|null $terms What the deal fills in, by the keys of TERMS.
 * @property string|null $body
 * @property string|null $body_hash
 * @property array{label: array<string, string|null>, rule?: string, signatories: list<array{user_id: int, name: string, exempt: bool}>, payee: array<string, string|null>, artist: array<string, string|null>|null}|null $parties
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $signed_at
 * @property CarbonImmutable|null $voided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['label_id', 'agreement_template_id', 'name', 'licensor', 'payee_id', 'artist_payee_id', 'terms'])]
class Agreement extends Model
{
    /** @use HasFactory<AgreementFactory> */
    use BelongsToLabel, HasFactory;

    public const string DRAFT = 'draft';

    public const string SENT = 'sent';

    public const string SIGNED = 'signed';

    public const string VOID = 'void';

    /**
     * A new agreement is a draft before it is stored, too.
     *
     * @var array<string, string>
     */
    protected $attributes = ['status' => self::DRAFT];

    /** @var list<string> The sides a person can sign for. */
    public const array CAPACITIES = ['label', 'payee', 'artist'];

    /**
     * What is entered per agreement, by its key in `terms` and the
     * template field it fills.
     *
     * @var array<string, string>
     */
    public const array TERMS = [
        'effective_date' => 'effective_date',
        'work_title' => 'work.title',
        'work_original_title' => 'work.original_title',
        'work_artist' => 'work.artist',
        'work_tracks' => 'work.tracks',
        'share' => 'share',
        'publishing_share' => 'publishing_share',
        'term' => 'term',
        'deliverables' => 'deliverables',
        'delivery_date' => 'delivery_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'parties' => 'array',
            'sent_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AgreementTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class, 'agreement_template_id');
    }

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function artistPayee(): BelongsTo
    {
        return $this->belongsTo(Payee::class, 'artist_payee_id');
    }

    /**
     * @return HasMany<AgreementSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(AgreementSignature::class);
    }

    /**
     * The releases the agreement is about, in whichever label the
     * reader works: only releases of its own label are ever attached.
     *
     * @return BelongsToMany<Release, $this>
     */
    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class)->withoutGlobalScope('label')->orderBy('agreement_release.id');
    }

    /**
     * The tracks the agreement is about, where it is not about a whole
     * release. Each is a track of one of its releases.
     *
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)->withoutGlobalScope('label');
    }

    /**
     * What the agreement covers, release by release: every track of the
     * release with whether it was chosen. A release with no chosen track
     * is covered as a whole.
     *
     * @return list<array{id: int, name: string, catalog_number: string|null, tracks: list<array{id: int, name: string, chosen: bool}>}>
     */
    public function coverage(): array
    {
        $chosen = array_values($this->tracks()->get(['tracks.id'])->map(fn (Track $track): int => $track->id)->all());

        return array_values($this->releases()->get()->map(fn (Release $release): array => self::releaseRow($release, $chosen))->all());
    }

    /**
     * A release as the coverage lists it.
     *
     * @param  list<int>  $chosen
     * @return array{id: int, name: string, catalog_number: string|null, tracks: list<array{id: int, name: string, chosen: bool}>}
     */
    public static function releaseRow(Release $release, array $chosen = []): array
    {
        return [
            'id' => $release->id,
            'name' => $release->name,
            'catalog_number' => $release->catalog_number,
            'tracks' => array_values(Track::query()->withoutGlobalScope('label')->where('release_id', $release->id)->orderBy('track_number')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Track $track): array => ['id' => $track->id, 'name' => $track->name, 'chosen' => in_array($track->id, $chosen)])->all()),
        ];
    }

    /**
     * Set what the agreement covers. A track only counts with its release.
     *
     * @param  list<int>  $releaseIds
     * @param  list<int>  $trackIds
     */
    public function cover(array $releaseIds, array $trackIds): void
    {
        $this->releases()->sync($releaseIds);
        $this->tracks()->sync(Track::query()->withoutGlobalScope('label')->whereIn('release_id', $releaseIds ?: [0])->whereIn('id', $trackIds ?: [0])->pluck('id')->all());
    }

    /**
     * @return HasMany<AgreementDeliverable, $this>
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(AgreementDeliverable::class);
    }

    /**
     * What is to be delivered, one item per line of the term.
     *
     * @return list<string>
     */
    public function deliverableLines(): array
    {
        return array_values(array_filter(array_map(
            fn (string $line): string => Str::limit(trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line)), 990, ''),
            preg_split('/\R/', (string) ($this->terms['deliverables'] ?? '')) ?: [],
        )));
    }

    /**
     * The agreements a user is asked to sign or has signed as a payee,
     * in whichever label: limited to the payees the user's own
     * memberships are linked to, and never a draft.
     *
     * @param  Builder<Agreement>  $query
     */
    public function scopeForPayeeUser(Builder $query, User $user): void
    {
        $query->withoutGlobalScope('label')
            ->where('status', '!=', self::DRAFT)
            ->whereExists(fn ($member) => $member->from('label_user')
                ->whereColumn('label_user.label_id', 'agreements.label_id')
                ->where('label_user.user_id', $user->id)
                ->where(fn ($linked) => $linked
                    ->whereColumn('label_user.payee_id', 'agreements.payee_id')
                    ->orWhereColumn('label_user.payee_id', 'agreements.artist_payee_id')));
    }

    /**
     * What the party of a payee looks like on paper.
     *
     * @return array<string, string|null>
     */
    private static function party(Payee $payee): array
    {
        return [
            'name' => $payee->name,
            'legal_name' => $payee->legal_name ?: $payee->name,
            'address' => $payee->address,
        ];
    }

    /**
     * The parties as they stand now: who the label is, who signs for
     * it, and who the payees are.
     *
     * @return array{label: array<string, string|null>, rule: string, signatories: list<array{user_id: int, name: string, exempt: bool}>, payee: array<string, string|null>, artist: array<string, string|null>|null}
     */
    public function currentParties(): array
    {
        $label = Label::query()->findOrFail($this->label_id);
        $payees = Payee::query()->withoutGlobalScope('label')->where('label_id', $this->label_id)
            ->findMany(array_filter([$this->payee_id, $this->artist_payee_id]))->keyBy('id');

        return [
            'label' => $label->only(['legal_name', 'legal_form', 'legal_address', 'legal_register']),
            'rule' => $label->signing_rule,
            'signatories' => array_values($label->signatories()->orderBy('name')->get()->map(fn (User $user): array => [
                'user_id' => $user->id,
                'name' => $user->name,
                'exempt' => (bool) $user->pivot->self_dealing_exempt,
            ])->all()),
            'payee' => isset($payees[$this->payee_id]) ? self::party($payees[$this->payee_id]) : ['name' => null, 'legal_name' => null, 'address' => null],
            'artist' => $this->licensor === 'artist' && isset($payees[$this->artist_payee_id]) ? self::party($payees[$this->artist_payee_id]) : null,
        ];
    }

    /**
     * The value of every template field for this agreement. A field
     * nobody filled in is null.
     *
     * @return array<string, string|null>
     */
    public function values(): array
    {
        $parties = $this->parties ?? $this->currentParties();
        $values = [
            'label.legal_name' => $parties['label']['legal_name'],
            'label.address' => $parties['label']['legal_address'],
            'label.register' => $parties['label']['legal_register'],
            'label.signatories' => implode(($parties['rule'] ?? 'all') === 'any' ? ' or ' : ', ', array_column($parties['signatories'], 'name')),
        ];

        foreach (['payee', 'artist'] as $side) {
            foreach (['name', 'legal_name', 'address'] as $key) {
                $values["{$side}.{$key}"] = $parties[$side][$key] ?? null;
            }
        }

        foreach (self::TERMS as $key => $field) {
            $values[$field] = $this->terms[$key] ?? null;
        }

        return array_map(fn (?string $value): ?string => trim((string) $value) === '' ? null : trim((string) $value), $values);
    }

    /**
     * The text of the agreement: the frozen one once it was sent, and
     * until then the template with what is known so far. An open field
     * stays visible as "[field]".
     */
    public function text(): string
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $values = $this->values();

        return (string) preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/s',
            fn (array $match): string => $values[$match[1]] ?? "[{$match[1]}]",
            str_replace("\r\n", "\n", (string) $this->template?->body),
        );
    }

    /**
     * The fields the template uses that have no value yet.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        preg_match_all('/\{\{\s*(.*?)\s*\}\}/s', (string) $this->template?->body, $matches);
        $values = $this->values();

        return array_values(array_filter(array_unique($matches[1]), fn (string $field): bool => ($values[$field] ?? null) === null));
    }

    /**
     * Why the agreement cannot be sent, or null when it can.
     */
    public function sendBlock(): ?string
    {
        return match (true) {
            $this->status !== self::DRAFT => 'Only a draft can be sent.',
            $this->template === null => 'The template of this agreement was deleted. Choose another one.',
            $this->currentParties()['signatories'] === [] => 'Nobody signs for the label yet. Choose who does under Settings → Label.',
            $this->missingFields() !== [] => 'These fields are still empty: '.implode(', ', $this->missingFields()).'.',
            $this->deliverableLines() !== [] && $this->releases()->doesntExist() => 'Choose the release this agreement belongs to: what is delivered is filed with it.',
            default => null,
        };
    }

    /**
     * Freeze the text and the parties and ask for signatures.
     */
    public function send(): void
    {
        $parties = $this->currentParties();
        $body = $this->text();

        $this->forceFill([
            'parties' => $parties,
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'status' => self::SENT,
            'sent_at' => now(),
        ])->save();

        // What the text says is to be delivered becomes the list the payee hands files in against.
        foreach ($this->deliverableLines() as $title) {
            $this->deliverables()->create(['title' => $title]);
        }
    }

    /**
     * The sides a user can sign for, whether or not they did: the label
     * when they were one of its signatories as the agreement was sent,
     * a payee when their membership of the label is linked to it.
     *
     * @return list<string>
     */
    public function capacitiesOf(User $user): array
    {
        $payeeIds = DB::table('label_user')->where('label_id', $this->label_id)->where('user_id', $user->id)->whereNotNull('payee_id')->pluck('payee_id')->all();

        return array_values(array_filter([
            in_array($user->id, array_column($this->parties['signatories'] ?? [], 'user_id')) ? 'label' : null,
            in_array($this->payee_id, $payeeIds) ? 'payee' : null,
            $this->licensor === 'artist' && in_array($this->artist_payee_id, $payeeIds) ? 'artist' : null,
        ]));
    }

    /**
     * Why a user cannot sign in a capacity now, or null when they can.
     */
    public function signBlock(User $user, string $capacity): ?string
    {
        $signed = $this->signatures->where('user_id', $user->id)->pluck('capacity')->all();
        $exempt = collect($this->parties['signatories'] ?? [])->firstWhere('user_id', $user->id)['exempt'] ?? false;
        // The label on one side and the same person on the other is self-dealing.
        $crossesSides = $signed !== [] && ($capacity === 'label') !== in_array('label', $signed);

        return match (true) {
            $this->status !== self::SENT => 'This agreement is not open for signature.',
            ! in_array($capacity, $this->capacitiesOf($user)) => 'You cannot sign this agreement in that role.',
            in_array($capacity, $signed) => 'You have already signed in that role.',
            $capacity === 'label' && $this->anyOneSigns() && $this->signatures->contains('capacity', 'label') => 'The label has already signed: one of its signatories is enough.',
            $capacity !== 'label' && $this->signatures->contains('capacity', $capacity) => 'Someone has already signed in that role.',
            $crossesSides && ! $exempt => 'You have signed the other side of this agreement. The same person cannot sign for the label and as its other party unless the label has exempted them from the ban on self-dealing.',
            default => null,
        };
    }

    /**
     * Whether one of the label's signatories is enough, as the rule
     * stood when the agreement was sent.
     */
    private function anyOneSigns(): bool
    {
        return ($this->parties['rule'] ?? 'all') === 'any';
    }

    /**
     * Every signature the agreement needs: for the label each of its
     * signatories, or one line for any one of them; the payee; and the
     * artist when they are a party.
     *
     * @return list<array{capacity: string, name: string, user_id: int|null, signature: AgreementSignature|null}>
     */
    public function signatureLines(): array
    {
        $lines = [];
        $signatories = $this->parties['signatories'] ?? [];

        if ($this->anyOneSigns()) {
            $signature = $this->signatures->firstWhere('capacity', 'label');
            $lines[] = [
                'capacity' => 'label',
                'name' => $signature->name ?? (count($signatories) > 1 ? 'One of: ' : '').implode(', ', array_column($signatories, 'name')),
                'user_id' => $signature?->user_id,
                'signature' => $signature,
            ];
        } else {
            foreach ($signatories as $signatory) {
                $lines[] = [
                    'capacity' => 'label',
                    'name' => $signatory['name'],
                    'user_id' => $signatory['user_id'],
                    'signature' => $this->signatures->first(fn (AgreementSignature $signature): bool => $signature->capacity === 'label' && $signature->user_id === $signatory['user_id']),
                ];
            }
        }

        foreach (['payee', 'artist'] as $side) {
            if ($this->parties[$side] ?? null) {
                $lines[] = [
                    'capacity' => $side,
                    'name' => (string) $this->parties[$side]['legal_name'],
                    'user_id' => null,
                    'signature' => $this->signatures->firstWhere('capacity', $side),
                ];
            }
        }

        return $lines;
    }

    /**
     * The signature lines to show. An agreement closed with a copy that
     * was signed elsewhere shows only who signed here: the others are
     * on the copy, not missing.
     *
     * @return list<array{capacity: string, name: string, user_id: int|null, signature: AgreementSignature|null}>
     */
    public function shownLines(): array
    {
        return array_values(array_filter($this->signatureLines(), fn (array $line): bool => $this->signed_copy_path === null || $line['signature'] !== null));
    }

    /**
     * Record a signature and close the agreement once it has them all.
     */
    public function sign(User $user, string $capacity, string $name, ?string $ip, ?string $userAgent): AgreementSignature
    {
        $signature = $this->signatures()->create([
            'user_id' => $user->id,
            'capacity' => $capacity,
            'name' => $name,
            'email' => $user->email,
            'body_hash' => (string) $this->body_hash,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'signed_at' => now(),
        ]);

        $this->unsetRelation('signatures');

        if (collect($this->signatureLines())->every(fn (array $line): bool => $line['signature'] !== null)) {
            $this->forceFill(['status' => self::SIGNED, 'signed_at' => now()])->save();
        }

        return $signature;
    }

    /**
     * The agreement as a text of its own: what was agreed, who signed
     * it and how, and the hash that ties the two together.
     */
    public function record(): string
    {
        $lines = collect($this->shownLines())->map(fn (array $line): string => $line['signature'] === null
            ? "- {$line['name']} ({$line['capacity']}): not signed"
            : "- {$line['signature']->name} ({$line['capacity']}), {$line['signature']->email}, signed {$line['signature']->signed_at->utc()->format('Y-m-d H:i:s')} UTC from {$line['signature']->ip}");

        return $this->text()
            ."\n\n----------------------------------------\nSigned electronically\n\n"
            .$lines->implode("\n")
            ."\n\nSHA-256 of the text above the line: {$this->body_hash}\n";
    }

    /**
     * The agreement as a PDF: the text, then who signed it and how, and
     * the hash of the text. Made from the frozen record each time it is
     * asked for, so there is no second copy that could differ from it.
     */
    public function pdf(): string
    {
        $signatures = collect($this->shownLines())->map(fn (array $line): string => '<tr><td>'.e($line['signature']->name ?? $line['name']).'<br><small>'.e($line['capacity'] === 'label' ? 'for the label' : $line['capacity']).'</small></td><td>'
            .($line['signature'] === null ? 'Not signed' : e($line['signature']->email).'<br>'.$line['signature']->signed_at->utc()->format('Y-m-d H:i:s').' UTC<br>from '.e((string) $line['signature']->ip))
            .'</td></tr>')->implode('');

        $html = '<html><head><meta charset="utf-8"><style>
            @page { margin: 25mm 22mm; }
            body { font-family: "DejaVu Serif", serif; font-size: 10pt; line-height: 1.45; }
            .text { white-space: pre-wrap; }
            h2 { font-size: 11pt; margin: 0 0 6pt; }
            .record { page-break-inside: avoid; margin-top: 24pt; border-top: 1px solid #000; padding-top: 10pt; font-size: 9pt; }
            table { width: 100%; border-collapse: collapse; }
            td { vertical-align: top; padding: 5pt 8pt 5pt 0; border-bottom: 1px solid #ccc; }
            small { color: #555; }
            .hash { font-family: "DejaVu Sans Mono", monospace; font-size: 7.5pt; word-wrap: break-word; }
        </style></head><body>'
            .'<div class="text">'.e($this->text()).'</div>'
            .'<div class="record"><h2>'.($this->status === self::SIGNED && $this->signed_copy_path === null ? 'Signed electronically by every party' : 'Signatures').'</h2>'
            .'<table>'.$signatures.'</table>'
            .($this->signed_copy_path === null ? '<p>Each signature was given with a typed name, a confirmation and a one-time code sent to the email address shown.</p>' : '<p>This agreement was closed with a copy signed outside the application, which is kept with it.</p>')
            .'<p class="hash">SHA-256 of the text of the agreement: '.e((string) $this->body_hash).'</p></div>'
            .'</body></html>';

        $options = new Options;
        // Nothing is fetched and nothing outside the text is read; what the library caches goes where the application may write.
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setFontCache(storage_path('framework/cache'));
        $options->setTempDir(storage_path('framework/cache'));

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        // Every page says which agreement it belongs to and where it stands in it.
        $pdf->getCanvas()->page_text(62, 800, "Agreement {$this->id} · page {PAGE_NUM} of {PAGE_COUNT}", $pdf->getFontMetrics()->getFont('DejaVu Serif'), 7.5, [0.4, 0.4, 0.4]);

        return (string) $pdf->output();
    }

    /**
     * Keep a copy that was signed outside the application.
     */
    public function keepSignedCopy(IncomingFile $file): void
    {
        $disk = WorkingCopy::disk();
        $path = $file->storeAs("agreements/{$this->label_id}", Str::uuid()->toString().'.'.$file->extension(), $disk);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        $this->forceFill([
            'signed_copy_path' => "{$disk}:{$path}",
            'signed_copy_name' => $file->name,
        ])->save();
    }

    /**
     * The copy signed outside the application, as a download or to be
     * shown in the page.
     */
    public function signedCopyResponse(bool $inline = false): StreamedResponse
    {
        abort_if($this->signed_copy_path === null, 404);

        [$disk, $path] = explode(':', $this->signed_copy_path, 2);

        abort_unless(Storage::disk($disk)->exists($path), 404);

        // Shown in the page it is only ever a PDF or a picture, and is told to be nothing else.
        return $inline
            ? Storage::disk($disk)->response($path, (string) $this->signed_copy_name, ['X-Content-Type-Options' => 'nosniff'])
            : Storage::disk($disk)->download($path, (string) $this->signed_copy_name);
    }

    /**
     * Whether this is a copy signed elsewhere that was filed as it is:
     * there is no text here, the copy is the agreement.
     */
    public function isFiled(): bool
    {
        return $this->body === null && $this->signed_copy_path !== null;
    }

    /**
     * Why the agreement cannot be deleted, or null when it can. What
     * was signed here stands as the record of it; a draft, a withdrawn
     * agreement and a copy that was only filed here can go.
     */
    public function deleteBlock(): ?string
    {
        return match (true) {
            $this->status === self::SENT => 'This agreement is out for signature. Withdraw it first.',
            $this->status === self::SIGNED && ! $this->isFiled() => 'This agreement was signed here and is kept as the record of it.',
            default => null,
        };
    }

    /**
     * Remove the agreement and, with it, the one copy that was filed for it.
     */
    public function remove(): void
    {
        $copy = $this->signed_copy_path;

        $this->delete();

        if ($copy !== null) {
            [$disk, $path] = explode(':', $copy, 2);
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * The PDF as a download named after the agreement.
     */
    public function pdfResponse(bool $inline = false): Response
    {
        return response($this->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.(Str::slug($this->name) ?: 'agreement')."-{$this->id}.pdf\"",
        ]);
    }

    /**
     * Everyone with an account who is to sign or has a copy coming: the
     * label's signatories and the members linked to the payees.
     *
     * @return Collection<int, User>
     */
    public function people(): Collection
    {
        $payeeIds = array_filter([$this->payee_id, $this->licensor === 'artist' ? $this->artist_payee_id : null]);
        $linked = DB::table('label_user')->where('label_id', $this->label_id)
            ->whereIn('payee_id', $payeeIds)
            ->pluck('user_id')->all();

        return User::query()->whereIn('id', [...array_column($this->parties['signatories'] ?? [], 'user_id'), ...$linked])->get();
    }

    /**
     * Whether the user runs the label of this agreement.
     */
    public function isRunBy(User $user): bool
    {
        return DB::table('label_user')->where('label_id', $this->label_id)->where('user_id', $user->id)->where('role', LabelRole::Admin->value)->exists();
    }
}
