<?php

use App\Enums\LabelRole;
use App\Models\Label;
use App\Models\LoginCode;
use App\Models\Payee;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Notifications\LoginCodeUnavailable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

const WRONG_CODE_MESSAGE = 'This code is not valid or has expired. Check it or ask for a new one.';

/**
 * Give the user a pending sign-in code, as if they had just asked for it.
 */
function pendingLoginCode(User $user, string $code = '123456'): LoginCode
{
    return LoginCode::factory()->code($code)->create(['user_id' => $user->id]);
}

test('the page to ask for a code prefills the email from the invitation link', function () {
    $this->get(route('login.code.create', ['email' => 'artist@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login-code')
            ->where('email', 'artist@example.com'));
});

test('asking for a code answers the same for a known and an unknown address', function () {
    $user = User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();

    $known = $this->post(route('login.code.store'), ['email' => 'artist@example.com']);
    $knownStatus = session('status');
    $this->flushSession();
    $unknown = $this->post(route('login.code.store'), ['email' => 'nobody@example.com']);

    $known->assertRedirect(route('login.code.verify'))->assertSessionHasNoErrors();
    $unknown->assertRedirect(route('login.code.verify'))->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'If an account exists for this address, we sent a code.');
    expect($knownStatus)->toBe('If an account exists for this address, we sent a code.')
        ->and($known->getStatusCode())->toBe($unknown->getStatusCode());
    Notification::assertSentTo($user, LoginCodeNotification::class);
    Notification::assertCount(1);
    $this->assertDatabaseCount('login_codes', 1);
    $this->assertDatabaseCount('users', 1);
});

test('the page to enter the code looks the same for a known and an unknown address', function (string $email) {
    User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();

    $this->post(route('login.code.store'), ['email' => $email]);

    $this->get(route('login.code.verify'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login-code-verify')
            ->where('email', $email)
            ->where('status', 'If an account exists for this address, we sent a code.')
            ->where('minutesValid', 15));
})->with(['known' => 'artist@example.com', 'unknown' => 'nobody@example.com']);

test('a code is found for an address typed in another letter case', function () {
    $user = User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();

    $this->post(route('login.code.store'), ['email' => ' Artist@Example.COM ']);

    Notification::assertSentTo($user, LoginCodeNotification::class);
});

test('the code is six digits, valid for 15 minutes and only stored as a hash', function () {
    $user = User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();
    $this->freezeTime();

    $this->post(route('login.code.store'), ['email' => 'artist@example.com']);

    $code = Notification::sent($user, LoginCodeNotification::class)->sole()->code;
    $stored = LoginCode::query()->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($stored->user_id)->toBe($user->id)
        ->and($stored->attempts)->toBe(0)
        ->and($stored->expires_at->toDateTimeString())->toBe(now()->addMinutes(15)->toDateTimeString())
        ->and($stored->code_hash)->toBe(LoginCode::hash($user->id, $code))
        ->and($stored->getAttributes())->not->toContain($code);
});

test('asking for a code needs a valid email address', function (string $email) {
    Notification::fake();

    $this->from(route('login.code.create'))
        ->post(route('login.code.store'), ['email' => $email])
        ->assertRedirect(route('login.code.create'))
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
})->with(['empty' => '', 'no address' => 'artist']);

test('the emailed code signs the user in and cannot be used a second time', function () {
    $user = User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();

    $this->post(route('login.code.store'), ['email' => 'artist@example.com']);
    $code = Notification::sent($user, LoginCodeNotification::class)->sole()->code;

    $this->post(route('login.code.authenticate'), ['code' => $code])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user, 'web');
    $this->assertDatabaseCount('login_codes', 0);

    $this->post(route('logout'));
    $this->withSession(['login_code.email' => 'artist@example.com'])
        ->post(route('login.code.authenticate'), ['code' => $code])
        ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);

    $this->assertGuest();
});

test('signing in with a code starts a fresh session', function () {
    $user = User::factory()->create();
    pendingLoginCode($user);
    $this->withSession(['login_code.email' => $user->email]);
    $this->get(route('login.code.verify'));
    $sessionId = session()->getId();

    $this->post(route('login.code.authenticate'), ['code' => '123456'])->assertRedirect();

    expect(session()->getId())->not->toBe($sessionId)
        ->and(session()->has('login_code.email'))->toBeFalse();
});

test('a code typed with spaces is accepted', function () {
    $user = User::factory()->create();
    pendingLoginCode($user);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => ' 123 456 '])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a code that is not six digits is rejected without counting as an attempt', function (string $code) {
    $user = User::factory()->create();
    pendingLoginCode($user);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => $code])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    $this->assertDatabaseHas('login_codes', ['user_id' => $user->id, 'attempts' => 0]);
})->with(['empty' => '', 'too short' => '12345', 'too long' => '1234567', 'letters' => '12345a']);

test('a code stops working 15 minutes after it was requested', function () {
    $user = User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();
    $this->freezeTime();
    $this->post(route('login.code.store'), ['email' => 'artist@example.com']);
    $code = Notification::sent($user, LoginCodeNotification::class)->sole()->code;

    $this->travel(15)->minutes();
    $this->travel(1)->seconds();

    $this->post(route('login.code.authenticate'), ['code' => $code])
        ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);

    $this->assertGuest();
});

test('a code still works just before the 15 minutes are over', function () {
    $user = User::factory()->create();
    $this->freezeTime();
    pendingLoginCode($user);

    $this->travel(14)->minutes();

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a wrong code is counted and leaves the right code usable', function () {
    $user = User::factory()->create();
    pendingLoginCode($user);
    $this->withSession(['login_code.email' => $user->email]);

    $this->post(route('login.code.authenticate'), ['code' => '654321'])
        ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);

    $this->assertGuest();
    $this->assertDatabaseHas('login_codes', ['user_id' => $user->id, 'attempts' => 1]);

    $this->post(route('login.code.authenticate'), ['code' => '123456'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('five wrong attempts void the code, even for the right one', function () {
    $user = User::factory()->create();
    pendingLoginCode($user);
    $this->withSession(['login_code.email' => $user->email]);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.code.authenticate'), ['code' => '000000'])->assertSessionHasErrors('code');
    }

    $this->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);

    $this->assertGuest();
    $this->assertDatabaseHas('login_codes', ['user_id' => $user->id, 'attempts' => 5]);
});

test('the fifth attempt still succeeds with the right code', function () {
    $user = User::factory()->create();
    pendingLoginCode($user)->update(['attempts' => 4]);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('asking for a new code invalidates the earlier one', function () {
    $user = User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();

    $this->post(route('login.code.store'), ['email' => 'artist@example.com']);
    $first = Notification::sent($user, LoginCodeNotification::class)->sole()->code;
    $firstHash = LoginCode::query()->sole()->code_hash;
    $this->post(route('login.code.store'), ['email' => 'artist@example.com']);

    $this->assertDatabaseCount('login_codes', 1);
    $this->assertDatabaseMissing('login_codes', ['code_hash' => $firstHash]);

    $second = Notification::sent($user, LoginCodeNotification::class)->last()->code;

    if ($first !== $second) {
        $this->post(route('login.code.authenticate'), ['code' => $first])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    $this->post(route('login.code.authenticate'), ['code' => $second])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('the code of one user does not sign in another', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    pendingLoginCode($owner, '123456');
    pendingLoginCode($other, '654321');

    $this->withSession(['login_code.email' => $other->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);

    $this->assertGuest();
});

test('an unknown address fails like a wrong code', function () {
    $this->withSession(['login_code.email' => 'nobody@example.com'])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);

    $this->assertGuest();
});

test('a code cannot be entered before one was requested in this browser', function () {
    $user = User::factory()->create();
    pendingLoginCode($user);

    $this->get(route('login.code.verify'))->assertRedirect(route('login.code.create'));
    $this->post(route('login.code.authenticate'), ['code' => '123456', 'email' => $user->email])
        ->assertRedirect(route('login.code.create'));

    $this->assertGuest();
    $this->assertDatabaseHas('login_codes', ['user_id' => $user->id, 'attempts' => 0]);
});

test('code requests for one address are limited to five per 15 minutes', function (string $email) {
    User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();
    $this->freezeTime();

    foreach (range(1, 5) as $request) {
        $this->post(route('login.code.store'), ['email' => $email])->assertSessionHasNoErrors();
    }
    $sent = Notification::sentNotifications();

    $this->post(route('login.code.store'), ['email' => $email])
        ->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in 15 minutes.']);

    expect(Notification::sentNotifications())->toBe($sent);

    $this->travel(15)->minutes();
    $this->travel(1)->seconds();

    $this->post(route('login.code.store'), ['email' => $email])->assertSessionHasNoErrors();
})->with(['known address' => 'artist@example.com', 'unknown address' => 'nobody@example.com']);

test('code requests from one ip address are limited across addresses', function () {
    $user = User::factory()->create(['email' => 'artist@example.com']);
    Notification::fake();

    foreach (range(1, 20) as $number) {
        $this->post(route('login.code.store'), ['email' => "person{$number}@example.com"])->assertSessionHasNoErrors();
    }

    $this->post(route('login.code.store'), ['email' => 'artist@example.com'])->assertSessionHasErrors('email');
    Notification::assertNothingSentTo($user);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post(route('login.code.store'), ['email' => 'artist@example.com'])
        ->assertSessionHasNoErrors();
    Notification::assertSentTo($user, LoginCodeNotification::class);
});

test('after ten failed codes for an address even the right code is refused for a while', function () {
    $user = User::factory()->create();
    $this->withSession(['login_code.email' => $user->email]);
    $this->freezeTime();

    foreach (range(1, 2) as $round) {
        LoginCode::query()->delete();
        pendingLoginCode($user);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.code.authenticate'), ['code' => '000000'])
                ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);
        }
    }

    LoginCode::query()->delete();
    pendingLoginCode($user);

    $this->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Please try again in 60 minutes.']);
    $this->assertGuest();
    $this->assertDatabaseHas('login_codes', ['user_id' => $user->id, 'attempts' => 0]);

    $this->travel(60)->minutes();
    $this->travel(1)->seconds();
    LoginCode::query()->delete();
    pendingLoginCode($user);

    $this->post(route('login.code.authenticate'), ['code' => '123456'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('failed codes from one ip address are limited across addresses', function () {
    $user = User::factory()->create();
    pendingLoginCode($user);

    foreach (range(1, 30) as $number) {
        $this->withSession(['login_code.email' => "person{$number}@example.com"])
            ->post(route('login.code.authenticate'), ['code' => '000000'])
            ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);
    }

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Please try again in 15 minutes.']);

    $this->assertGuest();
});

test('a user with two-factor authentication gets no code but the same answer', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'admin@example.com']);
    Notification::fake();

    $this->post(route('login.code.store'), ['email' => 'admin@example.com'])
        ->assertRedirect(route('login.code.verify'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'If an account exists for this address, we sent a code.');

    Notification::assertNotSentTo($user, LoginCodeNotification::class);
    Notification::assertSentTo($user, LoginCodeUnavailable::class);
    Notification::assertCount(1);
    $this->assertDatabaseCount('login_codes', 0);
});

test('a code cannot bypass two-factor authentication that was enabled after it was sent', function () {
    $user = User::factory()->withTwoFactor()->create();
    pendingLoginCode($user);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertSessionHasErrors(['code' => WRONG_CODE_MESSAGE]);

    $this->assertGuest();
});

test('signing in with a code verifies the email address', function () {
    $user = User::factory()->unverified()->create();
    pendingLoginCode($user);
    $this->travelTo('2026-11-18 12:00:00');

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->email_verified_at?->toDateTimeString())->toBe('2026-11-18 12:00:00');
});

test('signing in with a code to an unverified account shuts out whoever registered it', function () {
    $user = User::factory()->unverified()->create(['password' => 'chosen-by-someone-else', 'remember_token' => 'kept-by-someone-else']);
    pendingLoginCode($user);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect(Hash::check('chosen-by-someone-else', $user->password))->toBeFalse()
        ->and($user->remember_token)->not->toBe('kept-by-someone-else');
});

test('signing in with a code keeps the password of a verified account', function () {
    $user = User::factory()->create(['password' => 'chosen-by-the-owner']);
    pendingLoginCode($user);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    expect(Hash::check('chosen-by-the-owner', $user->refresh()->password))->toBeTrue();
});

test('a wrong code does not verify the email address', function () {
    $user = User::factory()->unverified()->create();
    pendingLoginCode($user);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($user->refresh()->email_verified_at)->toBeNull();
});

test('a payee who signs in with a code lands on the portal', function () {
    $label = Label::factory()->create();
    $payee = Payee::factory()->create(['label_id' => $label->id]);
    $user = User::factory()->create();
    $label->users()->attach($user, ['role' => LabelRole::Payee->value, 'payee_id' => $payee->id]);
    pendingLoginCode($user);

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertRedirect(route('portal.index'));
    $this->get(route('portal.index'))->assertOk();
});

test('signing in with a code returns to the page that asked for a login', function () {
    $label = Label::factory()->create();
    $user = labelAdmin($label);
    pendingLoginCode($user);

    $this->get(route('periods.index'))->assertRedirect(route('login'));

    $this->withSession(['login_code.email' => $user->email])
        ->post(route('login.code.authenticate'), ['code' => '123456'])
        ->assertRedirect(route('periods.index'));
});

test('someone who is signed in is sent away from the code pages', function () {
    $user = labelAdmin(Label::factory()->create());

    $this->actingAs($user)->get(route('login.code.create'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)->get(route('login.code.verify'))->assertRedirect(route('dashboard'));
    $this->actingAs($user)->post(route('login.code.store'), ['email' => 'artist@example.com'])->assertRedirect(route('dashboard'));
    $this->actingAs($user)->post(route('login.code.authenticate'), ['code' => '123456'])->assertRedirect(route('dashboard'));
});

test('the email states the code, its lifetime and that it can be ignored', function () {
    $user = User::factory()->create();

    $mail = (new LoginCodeNotification('048213'))->toMail($user);
    $content = (string) $mail->render();

    expect($mail->subject)->toBe('Your sign-in code: 048213')
        ->and($content)->toContain('048213')
        ->toContain('expires in 15 minutes')
        ->toContain('If you did not ask for a code, you can ignore this email.');
});

test('the email about disabled codes points to the password login', function () {
    $user = User::factory()->withTwoFactor()->create();

    $content = (string) (new LoginCodeUnavailable)->toMail($user)->render();

    expect($content)->toContain('two-factor authentication')
        ->toContain(route('login'));
});

test('a payee who signed in with an emailed code reaches the passkeys without a password, anyone else is asked for one', function () {
    $label = Label::factory()->create();
    $payeeUser = User::factory()->create(['email' => 'artist@example.com']);
    $label->users()->attach($payeeUser, ['role' => LabelRole::Payee->value, 'payee_id' => Payee::factory()->create(['label_id' => $label->id])->id]);

    $this->actingAs($payeeUser)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    auth()->logout();
    Notification::fake();

    $this->post(route('login.code.store'), ['email' => 'artist@example.com']);
    $this->post(route('login.code.authenticate'), ['code' => Notification::sent($payeeUser, LoginCodeNotification::class)->sole()->code])
        ->assertSessionHasNoErrors();

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/security')->where('canManagePasskeys', true));
});
