<?php

use App\Enums\LabelRole;
use App\Enums\ReleaseFileKind;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Contract;
use App\Models\Label;
use App\Models\LoginCode;
use App\Models\Payee;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\Statement;
use App\Models\Track;
use App\Models\User;
use App\Notifications\AgreementSigned;
use App\Notifications\AgreementSigningCode;
use App\Notifications\AgreementToSign;
use App\Notifications\DeliverableReturned;
use App\Support\PayeeAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A label with a legal entity, one admin who signs for it, a payee with
 * an account, and a draft agreement between them.
 *
 * @return array{label: Label, signatory: User, payee: Payee, payeeUser: User, agreement: Agreement}
 */
function agreementBetweenLabelAndPayee(array $signatory = [], array $agreement = []): array
{
    $label = Label::factory()->create(['name' => 'Example Records', 'legal_name' => 'Example Records GmbH', 'legal_form' => 'gmbh', 'legal_address' => 'Example Street 1, 10000 Berlin', 'legal_register' => 'AG Example, HRB 1']);
    $admin = User::factory()->create(['name' => 'Dana Director', 'current_label_id' => $label->id]);
    $label->users()->attach($admin, ['role' => LabelRole::Admin->value, 'signs_for_label' => true, ...$signatory]);
    $payee = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Remixer', 'legal_name' => 'Robin Example', 'address' => 'Example Road 2, London']);
    $payeeUser = User::factory()->create(['name' => 'Robin Example']);
    $label->users()->attach($payeeUser, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);
    $template = AgreementTemplate::factory()->create([
        'label_id' => $label->id,
        'body' => "{{ label.legal_name }} ({{ label.signatories }}) and {{ payee.legal_name }} aka {{payee.name}}\nagree on {{ share }} % of \"{{ work.title }}\".",
    ]);

    return [
        'label' => $label, 'signatory' => $admin, 'payee' => $payee, 'payeeUser' => $payeeUser,
        'agreement' => Agreement::factory()->create(['label_id' => $label->id, 'agreement_template_id' => $template->id, 'payee_id' => $payee->id, 'name' => 'Example remix deal', ...$agreement]),
    ];
}

/**
 * Sign as the user, with a fresh emailed code.
 */
function signAgreement(Agreement $agreement, User $user, string $capacity, array $input = [])
{
    return test()->actingAs($user)->post(route('my-agreements.sign', $agreement->id), [
        'capacity' => $capacity, 'name' => $user->name, 'consent' => '1',
        'body_hash' => $agreement->refresh()->body_hash, 'code' => LoginCode::issueFor($user), ...$input,
    ]);
}

test('a draft is made from a template and shows its text with what is still open', function () {
    ['label' => $label, 'signatory' => $admin, 'payee' => $payee, 'agreement' => $existing] = agreementBetweenLabelAndPayee();

    $this->actingAs($admin)
        ->post(route('agreements.store'), [
            'name' => 'Second deal', 'agreement_template_id' => $existing->agreement_template_id, 'licensor' => 'label', 'payee_id' => $payee->id,
            'terms' => ['work_title' => 'Example Song (Remix)', 'share' => ''],
        ])
        ->assertSessionHasNoErrors();

    $agreement = Agreement::query()->where('name', 'Second deal')->sole();

    expect($agreement->status)->toBe(Agreement::DRAFT)
        ->and($agreement->label_id)->toBe($label->id);

    $this->actingAs($admin)
        ->get(route('agreements.show', $agreement->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('agreements/show')
            ->where('agreement.text', "Example Records GmbH (Dana Director) and Robin Example aka Example Remixer\nagree on [share] % of \"Example Song (Remix)\".")
            ->where('agreement.send_block', 'These fields are still empty: share.'));
});

test('a draft refuses records of another label and an artist who is the payee', function () {
    ['signatory' => $admin, 'payee' => $payee, 'agreement' => $existing] = agreementBetweenLabelAndPayee();
    $foreign = Payee::factory()->create();
    $input = ['name' => 'Deal', 'agreement_template_id' => $existing->agreement_template_id, 'licensor' => 'label', 'payee_id' => $payee->id];

    $this->actingAs($admin)->post(route('agreements.store'), [...$input, 'payee_id' => $foreign->id])->assertSessionHasErrors('payee_id');
    $this->actingAs($admin)->post(route('agreements.store'), [...$input, 'agreement_template_id' => AgreementTemplate::factory()->create()->id])->assertSessionHasErrors('agreement_template_id');
    $this->actingAs($admin)->post(route('agreements.store'), [...$input, 'licensor' => 'artist'])->assertSessionHasErrors('artist_payee_id');
    $this->actingAs($admin)->post(route('agreements.store'), [...$input, 'licensor' => 'artist', 'artist_payee_id' => $payee->id])->assertSessionHasErrors('artist_payee_id');
    $this->actingAs($admin)->post(route('agreements.store'), [...$input, 'terms' => ['share' => '120']])->assertSessionHasErrors('terms.share');
    $this->actingAs($admin)->post(route('agreements.store'), [...$input, 'terms' => ['status' => 'signed']])->assertSessionHasErrors('terms');
});

test('an agreement is not sent while nobody signs for the label', function () {
    ['signatory' => $admin, 'agreement' => $agreement] = agreementBetweenLabelAndPayee(['signs_for_label' => false]);

    $this->actingAs($admin)->post(route('agreements.send', $agreement->id))
        ->assertInertiaFlash('toast.message', 'Nobody signs for the label yet. Choose who does under Settings → Label.');

    expect($agreement->refresh()->status)->toBe(Agreement::DRAFT);
});

test('sending freezes the text and the parties and asks everyone to sign', function () {
    Notification::fake();
    ['label' => $label, 'signatory' => $admin, 'payee' => $payee, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();

    $this->actingAs($admin)->post(route('agreements.send', $agreement->id))->assertSessionHasNoErrors();

    $agreement->refresh();
    $text = "Example Records GmbH (Dana Director) and Robin Example aka Example Remixer\nagree on 50 % of \"Example Song (Example Remix)\".";

    expect($agreement->status)->toBe(Agreement::SENT)
        ->and($agreement->body)->toBe($text)
        ->and($agreement->body_hash)->toBe(hash('sha256', $text));
    Notification::assertSentTo([$admin, $payeeUser], AgreementToSign::class);

    // What changes afterwards does not reach the agreement.
    $agreement->template->update(['body' => 'Something else.']);
    $payee->update(['legal_name' => 'Renamed']);
    $label->update(['legal_name' => 'Other GmbH']);
    $label->users()->updateExistingPivot($admin->id, ['signs_for_label' => false]);

    expect($agreement->refresh()->text())->toBe($text)
        ->and($agreement->capacitiesOf($admin))->toBe(['label']);

    $this->actingAs($admin)->put(route('agreements.update', $agreement->id), ['name' => 'Changed', 'agreement_template_id' => $agreement->agreement_template_id, 'licensor' => 'label', 'payee_id' => $payee->id])->assertStatus(409);
    $this->actingAs($admin)->delete(route('agreements.destroy', $agreement->id))->assertStatus(409);
});

test('an agreement is signed by the payee and the label with an emailed code and then sent to everyone', function () {
    Notification::fake();
    ['signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $agreement->send();

    $this->actingAs($payeeUser)->post(route('my-agreements.code', $agreement->id))->assertSessionHasNoErrors();
    Notification::assertSentTo($payeeUser, AgreementSigningCode::class);

    signAgreement($agreement, $payeeUser, 'payee')->assertSessionHasNoErrors();

    $signature = $agreement->signatures()->sole();

    expect($agreement->refresh()->status)->toBe(Agreement::SENT)
        ->and($signature->only(['capacity', 'name', 'email', 'body_hash', 'ip']))->toBe(['capacity' => 'payee', 'name' => 'Robin Example', 'email' => $payeeUser->email, 'body_hash' => $agreement->body_hash, 'ip' => '127.0.0.1']);
    Notification::assertNothingSentTo($admin);

    signAgreement($agreement, $admin, 'label')->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::SIGNED)
        ->and($agreement->signed_at)->not->toBeNull()
        ->and($agreement->record())->toContain($agreement->body, 'Robin Example (payee)', 'Dana Director (label)', "SHA-256 of the text above the line: {$agreement->body_hash}");
    Notification::assertSentTo([$admin, $payeeUser], AgreementSigned::class);
});

test('a signature needs the name, the consent, the right code and the text that was read', function (array $input, string $field) {
    ['payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $agreement->send();

    signAgreement($agreement, $payeeUser, 'payee', $input)->assertSessionHasErrors($field);

    expect($agreement->signatures()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'no consent' => [['consent' => '0'], 'consent'],
    'a wrong code' => [['code' => 'wrong!'], 'code'],
    'another text' => [['body_hash' => 'something else'], 'body_hash'],
    'a role they do not have' => [['capacity' => 'label'], 'capacity'],
]);

test('a code works for one signature only and the same role is not signed twice', function () {
    ['payeeUser' => $payeeUser, 'label' => $label, 'payee' => $payee, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $agreement->send();
    $code = LoginCode::issueFor($payeeUser);
    $input = ['capacity' => 'payee', 'name' => 'Robin Example', 'consent' => '1', 'body_hash' => $agreement->body_hash, 'code' => $code];

    // A wrong code first: it must not use up the right one's single use.
    $this->actingAs($payeeUser)->post(route('my-agreements.sign', $agreement->id), [...$input, 'code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    $this->actingAs($payeeUser)->post(route('my-agreements.sign', $agreement->id), $input)->assertSessionHasNoErrors();
    expect(LoginCode::query()->count())->toBe(0);
    signAgreement($agreement, $payeeUser, 'payee')->assertSessionHasErrors('capacity');

    $second = User::factory()->create();
    $label->users()->attach($second, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);

    signAgreement($agreement, $second, 'payee')->assertSessionHasErrors('capacity');

    expect($agreement->signatures()->count())->toBe(1);
});

test('the label needs every one of its signatories', function () {
    ['label' => $label, 'signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $partner = User::factory()->create(['name' => 'Pat Partner']);
    $label->users()->attach($partner, ['role' => LabelRole::Admin->value, 'signs_for_label' => true]);
    $agreement->send();

    signAgreement($agreement, $payeeUser, 'payee')->assertSessionHasNoErrors();
    signAgreement($agreement, $admin, 'label')->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::SENT);

    signAgreement($agreement, $partner, 'label')->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::SIGNED);
});

test('someone who signs for the label does not also sign the other side unless they are exempt', function (bool $exempt) {
    ['label' => $label, 'signatory' => $admin, 'payee' => $payee, 'agreement' => $agreement] = agreementBetweenLabelAndPayee(['self_dealing_exempt' => $exempt]);
    $label->users()->updateExistingPivot($admin->id, ['payee_id' => $payee->id]);
    $agreement->send();

    signAgreement($agreement, $admin, 'payee')->assertSessionHasNoErrors();
    $second = signAgreement($agreement, $admin, 'label');

    if ($exempt) {
        $second->assertSessionHasNoErrors();
        expect($agreement->refresh()->status)->toBe(Agreement::SIGNED);
    } else {
        $second->assertSessionHasErrors('capacity');
        expect($agreement->refresh()->status)->toBe(Agreement::SENT);
    }
})->with([true, false]);

test('an agreement granted by an artist needs the artist as a third party', function () {
    ['label' => $label, 'signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $artist = Payee::factory()->create(['label_id' => $label->id, 'name' => 'Example Artist']);
    $artistUser = User::factory()->create();
    $label->users()->attach($artistUser, ['role' => LabelRole::Payee->value, 'payee_id' => $artist->id]);
    $agreement->update(['licensor' => 'artist', 'artist_payee_id' => $artist->id]);
    $agreement->send();

    signAgreement($agreement, $payeeUser, 'payee')->assertSessionHasNoErrors();
    signAgreement($agreement, $payeeUser, 'artist')->assertSessionHasErrors('capacity');
    signAgreement($agreement, $admin, 'label')->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::SENT);

    signAgreement($agreement, $artistUser, 'artist')->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::SIGNED);
});

test('a payee reads their own agreements, in whichever label, and nobody else\'s', function () {
    ['payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    ['payeeUser' => $stranger, 'agreement' => $other] = agreementBetweenLabelAndPayee();
    $draft = Agreement::factory()->create(['label_id' => $agreement->label_id, 'payee_id' => $agreement->payee_id]);
    $agreement->send();
    $other->send();

    $this->actingAs($payeeUser)
        ->get(route('my-agreements.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-agreements/index')
            ->has('agreements', 1)
            ->where('agreements.0.id', $agreement->id)
            ->where('agreements.0.label', 'Example Records')
            ->where('agreements.0.to_sign', true));

    $this->actingAs($payeeUser)
        ->get(route('my-agreements.show', $agreement->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-agreements/show')
            ->where('agreement.text', $agreement->body)
            ->where('agreement.capacities', ['payee'])
            ->where('agreement.is_admin', false));

    $this->actingAs($payeeUser)->get(route('my-agreements.show', $other->id))->assertNotFound();
    $this->actingAs($payeeUser)->get(route('my-agreements.show', $draft->id))->assertNotFound();
    $this->actingAs($stranger)->post(route('my-agreements.code', $agreement->id))->assertNotFound();
    signAgreement($agreement, $stranger, 'payee')->assertNotFound();
    $this->actingAs($payeeUser)->get(route('agreements.show', $agreement->id))->assertRedirect(route('portal.index'));
});

test('an agreement that is out for signature can be withdrawn, a signed one cannot', function () {
    ['signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $agreement->send();

    $this->actingAs($admin)->post(route('agreements.void', $agreement->id))->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::VOID);
    signAgreement($agreement, $payeeUser, 'payee')->assertSessionHasErrors('capacity');

    ['signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $signed] = agreementBetweenLabelAndPayee();
    $signed->send();
    signAgreement($signed, $payeeUser, 'payee');
    signAgreement($signed, $admin, 'label');

    $this->actingAs($admin)->post(route('agreements.void', $signed->id))->assertStatus(409);
});

test('one signatory is enough for a label that is bound by any one of them', function () {
    ['label' => $label, 'signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $second = User::factory()->create(['name' => 'Sam Second']);
    $label->users()->attach($second, ['role' => LabelRole::Admin->value, 'signs_for_label' => true]);
    $label->update(['signing_rule' => 'any']);
    $agreement->send();

    expect($agreement->text())->toContain('(Dana Director or Sam Second)')
        ->and(array_column($agreement->signatureLines(), 'name'))->toBe(['One of: Dana Director, Sam Second', 'Robin Example']);

    // The rule is the one the agreement was sent under.
    $label->update(['signing_rule' => 'all']);

    signAgreement($agreement, $payeeUser, 'payee')->assertSessionHasNoErrors();
    signAgreement($agreement, $second, 'label')->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::SIGNED)
        ->and(array_column($agreement->signatureLines(), 'name'))->toBe(['Sam Second', 'Robin Example']);
});

test('an admin without the right to sign cannot sign for the label', function () {
    ['label' => $label, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $plainAdmin = labelAdmin($label);
    $agreement->send();

    $this->actingAs($plainAdmin)->get(route('agreements.show', $agreement->id))->assertOk();
    $this->actingAs($plainAdmin)->get(route('my-agreements.show', $agreement->id))->assertNotFound();
    signAgreement($agreement, $plainAdmin, 'label')->assertNotFound();
});

test('an admin who is also a payee of the label reads their own statements and agreements as that payee', function () {
    ['label' => $label, 'signatory' => $admin, 'payee' => $payee, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $other = Payee::factory()->create(['label_id' => $label->id]);
    $own = Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $payee->id, 'published_at' => now(), 'archived' => false]);
    Statement::factory()->create(['label_id' => $label->id, 'payee_id' => $other->id, 'published_at' => now(), 'archived' => false]);
    $agreement->send();

    expect((new PayeeAccess($admin))->isPayeeAnywhere())->toBeFalse();

    $label->users()->updateExistingPivot($admin->id, ['payee_id' => $payee->id]);
    $access = new PayeeAccess($admin->fresh());

    expect($access->isPayeeAnywhere())->toBeTrue()
        ->and($access->statements()->pluck('id')->all())->toBe([$own->id])
        ->and(Agreement::query()->forPayeeUser($admin)->pluck('id')->all())->toBe([$agreement->id]);

    $this->actingAs($admin)
        ->get(route('portal.index'))
        ->assertInertia(fn (Assert $page) => $page->where('view', 'payee')->has('groups', 1)->has('groups.0.statements.data', 1));
});

test('a signed agreement makes the contract that pays its share, once, and leaves an existing contract alone', function () {
    ['label' => $label, 'signatory' => $admin, 'payee' => $payee, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $track = Track::factory()->create(['label_id' => $label->id, 'contract_id' => null]);
    $agreement->update(['track_id' => $track->id, 'terms' => [...$agreement->terms, 'share' => '37.5']]);
    $agreement->send();

    $this->actingAs($admin)->post(route('agreements.contract', $agreement->id))->assertStatus(409);

    signAgreement($agreement, $payeeUser, 'payee');
    signAgreement($agreement, $admin, 'label');

    $this->actingAs($admin)->post(route('agreements.contract', $agreement->id))->assertSessionHasNoErrors();

    $contract = Contract::query()->sole();
    $split = $contract->splits()->sole();

    expect($agreement->refresh()->contract_id)->toBe($contract->id)
        ->and($split->payee_id)->toBe($payee->id)
        ->and($split->split_amount)->toBe('0.375000000')
        ->and($track->refresh()->contract_id)->toBe($contract->id);

    $this->actingAs($admin)->post(route('agreements.contract', $agreement->id))->assertStatus(409);

    // A track that is already accounted for keeps its contract.
    ['label' => $label, 'signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $second] = agreementBetweenLabelAndPayee();
    $existing = Contract::factory()->create(['label_id' => $label->id]);
    $taken = Track::factory()->create(['label_id' => $label->id, 'contract_id' => $existing->id]);
    $second->update(['track_id' => $taken->id]);
    $second->send();
    signAgreement($second, $payeeUser, 'payee');
    signAgreement($second, $admin, 'label');

    $this->actingAs($admin)->post(route('agreements.contract', $second->id))
        ->assertInertiaFlash('toast.message', "Contract created. {$taken->name} already has a contract, which was left as it is.");

    expect($taken->refresh()->contract_id)->toBe($existing->id)
        ->and($second->refresh()->contract_id)->not->toBeNull();
});

test('a sent agreement is downloaded as a PDF by its parties and admins, and mailed as one once all have signed', function () {
    ['signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    ['payeeUser' => $stranger] = agreementBetweenLabelAndPayee();

    $this->actingAs($admin)->get(route('agreements.pdf', $agreement->id))->assertNotFound();

    $agreement->send();
    signAgreement($agreement, $payeeUser, 'payee');
    signAgreement($agreement, $admin, 'label');

    foreach ([[$admin, 'agreements.pdf'], [$payeeUser, 'my-agreements.pdf']] as [$user, $route]) {
        $response = $this->actingAs($user)->get(route($route, $agreement->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "attachment; filename=\"example-remix-deal-{$agreement->id}.pdf\"");

        expect($response->getContent())->toStartWith('%PDF-');
    }

    $this->actingAs($stranger)->get(route('my-agreements.pdf', $agreement->id))->assertNotFound();

    $attachments = collect((new AgreementSigned($agreement->refresh()->load('signatures'), 'Example Records'))->toMail($admin)->rawAttachments);

    expect($attachments->pluck('name')->all())->toBe(["agreement-{$agreement->id}.pdf", "agreement-{$agreement->id}.txt"])
        ->and($attachments[0]['data'])->toStartWith('%PDF-');
});

/**
 * A signed agreement with two things to deliver, filed with a release.
 *
 * @return array{label: Label, signatory: User, payee: Payee, payeeUser: User, agreement: Agreement, release: Release}
 */
function signedAgreementWithDeliverables(): array
{
    $setup = agreementBetweenLabelAndPayee();
    $release = Release::factory()->create(['label_id' => $setup['label']->id]);
    $setup['agreement']->update(['release_id' => $release->id, 'terms' => [...$setup['agreement']->terms, 'deliverables' => "- Master as WAV\n\n2. Artwork 3000 px"]]);
    $setup['agreement']->send();
    signAgreement($setup['agreement'], $setup['payeeUser'], 'payee');
    signAgreement($setup['agreement'], $setup['signatory'], 'label');

    return [...$setup, 'agreement' => $setup['agreement']->refresh(), 'release' => $release];
}

test('what is to be delivered needs a release, and sending makes one item of every line', function () {
    ['label' => $label, 'signatory' => $admin, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $agreement->update(['terms' => [...$agreement->terms, 'deliverables' => "- Master as WAV\n\n2. Artwork 3000 px"]]);

    expect($agreement->sendBlock())->toBe('Choose the release this agreement belongs to: what is delivered is filed with it.');

    $agreement->update(['release_id' => Release::factory()->create(['label_id' => $label->id])->id]);
    $this->actingAs($admin)->post(route('agreements.send', $agreement->id))->assertSessionHasNoErrors();

    expect($agreement->deliverables()->pluck('status', 'title')->all())->toBe(['Master as WAV' => 'open', 'Artwork 3000 px' => 'open']);
});

test('the payee delivers a file for an item once everyone has signed, and it is filed with the release', function () {
    ['label' => $label, 'signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement, 'release' => $release] = signedAgreementWithDeliverables();
    $item = $agreement->deliverables()->where('title', 'Artwork 3000 px')->sole();
    $cover = ReleaseFile::factory()->create(['label_id' => $label->id, 'release_id' => $release->id, 'kind' => ReleaseFileKind::Cover]);

    $this->actingAs($payeeUser)
        ->post(route('my-agreements.deliver', [$agreement->id, $item->id]), ['file' => UploadedFile::fake()->image('artwork.png', 100, 100)])
        ->assertSessionHasNoErrors();

    $file = $item->refresh()->file;

    expect($item->status)->toBe('delivered')
        ->and($file->only(['label_id', 'release_id', 'user_id', 'original_name']))->toBe(['label_id' => $label->id, 'release_id' => $release->id, 'user_id' => $payeeUser->id, 'original_name' => 'artwork.png'])
        ->and($file->kind)->toBe(ReleaseFileKind::Artwork)
        ->and($cover->refresh()->kind)->toBe(ReleaseFileKind::Cover);
    Storage::disk($file->disk)->assertExists($file->path);

    $this->actingAs($payeeUser)
        ->get(route('my-agreements.show', $agreement->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('agreement.can_deliver', true)
            ->where('agreement.deliverables.1.status', 'delivered')
            ->where('agreement.deliverables.1.file', 'artwork.png'));
    $this->actingAs($admin)
        ->get(route('agreements.show', $agreement->id))
        ->assertInertia(fn (Assert $page) => $page->where('agreement.deliverables.1.file_id', $file->id));
});

test('nobody but the payee delivers, nothing is delivered before all have signed, and a program is refused', function () {
    ['signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = signedAgreementWithDeliverables();
    ['payeeUser' => $stranger] = agreementBetweenLabelAndPayee();
    $item = $agreement->deliverables()->firstOrFail();
    $file = ['file' => UploadedFile::fake()->create('master.wav', 10, 'audio/wav')];

    $this->actingAs($admin)->post(route('my-agreements.deliver', [$agreement->id, $item->id]), $file)->assertForbidden();
    $this->actingAs($stranger)->post(route('my-agreements.deliver', [$agreement->id, $item->id]), $file)->assertNotFound();
    $this->actingAs($payeeUser)->post(route('my-agreements.deliver', [$agreement->id, $item->id]), ['file' => UploadedFile::fake()->create('run.php', 1)])->assertSessionHasErrors('file');
    $this->actingAs($admin)->postJson(route('my-agreements.uploads.sign', $agreement->id), ['name' => 'master.wav'])->assertForbidden();
    $this->actingAs($payeeUser)->postJson(route('my-agreements.uploads.sign', $agreement->id), ['name' => 'master.wav'])->assertOk()->assertExactJson(['url' => null]);

    // An item of another agreement is not reached through this one.
    ['agreement' => $other] = signedAgreementWithDeliverables();
    $this->actingAs($payeeUser)->post(route('my-agreements.deliver', [$agreement->id, $other->deliverables()->firstOrFail()->id]), $file)->assertNotFound();

    ['label' => $label, 'payeeUser' => $early, 'agreement' => $unsigned] = agreementBetweenLabelAndPayee();
    $unsigned->update(['release_id' => Release::factory()->create(['label_id' => $label->id])->id, 'terms' => [...$unsigned->terms, 'deliverables' => 'Master']]);
    $unsigned->send();
    $this->actingAs($early)->post(route('my-agreements.deliver', [$unsigned->id, $unsigned->deliverables()->firstOrFail()->id]), $file)->assertForbidden();

    expect(ReleaseFile::query()->withoutGlobalScope('label')->count())->toBe(0);
});

test('the label accepts what was delivered or hands it back with a note, and the payee delivers again', function () {
    Notification::fake();
    ['signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = signedAgreementWithDeliverables();
    $item = $agreement->deliverables()->firstOrFail();
    $deliver = fn (string $name) => $this->actingAs($payeeUser)->post(route('my-agreements.deliver', [$agreement->id, $item->id]), ['file' => UploadedFile::fake()->create($name, 10, 'audio/wav')]);

    $this->actingAs($admin)->post(route('agreements.deliverables.accept', [$agreement->id, $item->id]))->assertStatus(409);

    $deliver('first.wav')->assertSessionHasNoErrors();
    $first = $item->refresh()->release_file_id;

    $this->actingAs($admin)->post(route('agreements.deliverables.return', [$agreement->id, $item->id]), ['returned_note' => ''])->assertSessionHasErrors('returned_note');
    $this->actingAs($admin)->post(route('agreements.deliverables.return', [$agreement->id, $item->id]), ['returned_note' => 'Clipping at 2:10.'])->assertSessionHasNoErrors();

    expect($item->refresh()->only(['status', 'returned_note']))->toBe(['status' => 'returned', 'returned_note' => 'Clipping at 2:10.']);
    Notification::assertSentTo($payeeUser, DeliverableReturned::class);
    Notification::assertNotSentTo($admin, DeliverableReturned::class);

    $deliver('second.wav')->assertSessionHasNoErrors();

    // The earlier file stays with the release; the item points at the new one.
    expect($item->refresh()->only(['status', 'returned_note']))->toBe(['status' => 'delivered', 'returned_note' => null])
        ->and($item->release_file_id)->not->toBe($first)
        ->and(ReleaseFile::query()->withoutGlobalScope('label')->whereKey($first)->exists())->toBeTrue();

    $this->actingAs($admin)->post(route('agreements.deliverables.accept', [$agreement->id, $item->id]))->assertSessionHasNoErrors();

    expect($item->refresh()->status)->toBe('accepted');
    $deliver('third.wav')->assertStatus(409);
});

test('a signed copy is filed by dropping it, named after its file and matched to the payee it names', function () {
    ['label' => $label, 'signatory' => $admin, 'payee' => $payee, 'payeeUser' => $payeeUser] = agreementBetweenLabelAndPayee();
    ['payeeUser' => $stranger] = agreementBetweenLabelAndPayee();

    $this->actingAs($admin)->post(route('agreements.filed'), ['file' => UploadedFile::fake()->create('signed.exe', 10)])->assertSessionHasErrors('file');
    $this->actingAs($admin)->post(route('agreements.filed'), ['file' => UploadedFile::fake()->create('Remix_deal - Example Remixer signed.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('agreements.filed'), ['file' => UploadedFile::fake()->create('scan 0042.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();

    $agreement = Agreement::query()->where('name', 'Remix deal Example Remixer signed')->sole();
    $unmatched = Agreement::query()->where('name', 'scan 0042')->sole();
    [$disk, $path] = explode(':', $agreement->signed_copy_path, 2);

    expect($agreement->only(['status', 'label_id', 'payee_id', 'body', 'signed_copy_name']))->toBe(['status' => 'signed', 'label_id' => $label->id, 'payee_id' => $payee->id, 'body' => null, 'signed_copy_name' => 'Remix_deal - Example Remixer signed.pdf'])
        ->and($unmatched->payee_id)->toBeNull()
        ->and($path)->toStartWith("agreements/{$label->id}/");
    Storage::disk($disk)->assertExists($path);

    $this->actingAs($admin)->get(route('agreements.index'))
        ->assertInertia(fn (Assert $page) => $page->where('agreements.data', fn ($rows) => collect($rows)->pluck('unmatched', 'name')->all() === ['scan 0042' => true, 'Remix deal Example Remixer signed' => false, 'Example remix deal' => false]));
    $this->actingAs($admin)->get(route('agreements.signed-copy', $agreement->id))->assertOk()->assertDownload('Remix_deal - Example Remixer signed.pdf');
    $this->actingAs($payeeUser)->get(route('my-agreements.signed-copy', $agreement->id))->assertOk();
    $this->actingAs($stranger)->get(route('my-agreements.signed-copy', $agreement->id))->assertNotFound();
    $this->actingAs($payeeUser)->get(route('my-agreements.show', $unmatched->id))->assertNotFound();
    $this->actingAs($admin)->get(route('agreements.pdf', $agreement->id))->assertNotFound();
    $this->actingAs($admin)->get(route('agreements.show', $unmatched->id))
        ->assertInertia(fn (Assert $page) => $page->where('agreement.has_text', false)->where('agreement.lines', [])->where('agreement.filed.payee_id', null)->has('payeeOptions')->has('contractOptions'));
});

test('a filed copy is matched to what exists or to a payee and a contract made on the spot', function () {
    ['label' => $label, 'signatory' => $admin, 'payee' => $payee, 'agreement' => $made] = agreementBetweenLabelAndPayee();
    $this->actingAs($admin)->post(route('agreements.filed'), ['file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf')]);
    $agreement = Agreement::query()->where('name', 'scan')->sole();
    $contract = Contract::factory()->create(['label_id' => $label->id]);
    $track = Track::factory()->create(['label_id' => $label->id]);

    $this->actingAs($admin)->put(route('agreements.match', $agreement->id), ['name' => 'Old remix deal', 'payee_id' => Payee::factory()->create()->id])->assertSessionHasErrors('payee_id');
    $this->actingAs($admin)->put(route('agreements.match', $made->id), ['name' => 'Taken over'])->assertStatus(409);

    $this->actingAs($admin)
        ->put(route('agreements.match', $agreement->id), ['name' => 'Old remix deal', 'signed_on' => '2023-11-07', 'payee_id' => $payee->id, 'contract_id' => $contract->id, 'track_id' => $track->id])
        ->assertSessionHasNoErrors();

    expect($agreement->refresh()->only(['name', 'payee_id', 'contract_id', 'track_id']))->toBe(['name' => 'Old remix deal', 'payee_id' => $payee->id, 'contract_id' => $contract->id, 'track_id' => $track->id])
        ->and($agreement->signed_at->toDateString())->toBe('2023-11-07');

    $this->actingAs($admin)
        ->put(route('agreements.match', $agreement->id), ['name' => 'Old remix deal', 'payee_id' => $payee->id, 'new_payee' => 'New Remixer', 'contract_id' => $contract->id, 'new_contract' => '1'])
        ->assertSessionHasNoErrors();

    $newPayee = Payee::query()->where('name', 'New Remixer')->sole();
    $newContract = Contract::query()->where('name', 'Old remix deal')->sole();

    expect($agreement->refresh()->only(['payee_id', 'contract_id', 'track_id']))->toBe(['payee_id' => $newPayee->id, 'contract_id' => $newContract->id, 'track_id' => null])
        ->and($newPayee->label_id)->toBe($label->id)
        ->and($newContract->splits()->count())->toBe(0);
});

test('a copy signed elsewhere closes an agreement that is out for signature', function () {
    ['signatory' => $admin, 'payeeUser' => $payeeUser, 'agreement' => $agreement] = agreementBetweenLabelAndPayee();
    $copy = fn () => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

    $this->actingAs($admin)->post(route('agreements.signed-copy.store', $agreement->id), ['file' => $copy(), 'confirmed' => '1'])->assertStatus(409);

    $agreement->send();
    signAgreement($agreement, $admin, 'label');

    $this->actingAs($admin)->post(route('agreements.signed-copy.store', $agreement->id), ['file' => $copy()])->assertSessionHasErrors('confirmed');
    $this->actingAs($admin)->post(route('agreements.signed-copy.store', $agreement->id), ['file' => $copy(), 'confirmed' => '1'])->assertSessionHasNoErrors();

    expect($agreement->refresh()->status)->toBe(Agreement::SIGNED)
        // Who signed here is shown; who signed on the copy is not listed as missing.
        ->and(array_column($agreement->shownLines(), 'capacity'))->toBe(['label']);
    signAgreement($agreement, $payeeUser, 'payee')->assertSessionHasErrors('capacity');
});
