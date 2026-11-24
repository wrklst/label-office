<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Notifications\LoginCodeUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in without a password: ask for a one-time code by email, then
 * enter it. Neither step reveals whether an address has an account.
 */
class LoginCodeController extends Controller
{
    private const string SESSION_EMAIL = 'login_code.email';

    private const int THROTTLE_SECONDS = 900;

    private const int REQUESTS_PER_EMAIL = 5;

    private const int REQUESTS_PER_IP = 20;

    private const int FAILURES_PER_EMAIL = 10;

    /** Wrong codes for one address count for an hour: codes are short, so guessing has to be slow. */
    private const int FAILURE_SECONDS_PER_EMAIL = 3600;

    private const int FAILURES_PER_IP = 30;

    public function create(Request $request): Response
    {
        return Inertia::render('auth/login-code', [
            'email' => $request->string('email')->limit(255, '')->toString(),
        ]);
    }

    /**
     * Send a code when the address belongs to an account that may use
     * one. The answer is the same in every case.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = $request->string('email')->trim()->lower()->toString();

        $this->throttle('email', [
            'login-code-request:email:'.$this->throttleKey($email) => self::REQUESTS_PER_EMAIL,
            'login-code-request:ip:'.$request->ip() => self::REQUESTS_PER_IP,
        ]);

        $user = $this->findUser($email);

        if ($user?->hasEnabledTwoFactorAuthentication()) {
            $user->notify(new LoginCodeUnavailable);
        } elseif ($user) {
            $user->notify(new LoginCodeNotification(LoginCode::issueFor($user)));
        }

        $request->session()->put(self::SESSION_EMAIL, $email);

        return redirect()->route('login.code.verify')
            ->with('status', __('If an account exists for this address, we sent a code.'));
    }

    public function verify(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get(self::SESSION_EMAIL);

        if (! is_string($email)) {
            return redirect()->route('login.code.create');
        }

        return Inertia::render('auth/login-code-verify', [
            'email' => $email,
            'status' => $request->session()->get('status'),
            'minutesValid' => LoginCode::MINUTES_VALID,
        ]);
    }

    /**
     * Sign the user in when the code is right. A wrong, used, expired or
     * exhausted code and an unknown address all fail the same way.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        $email = $request->session()->get(self::SESSION_EMAIL);

        if (! is_string($email)) {
            return redirect()->route('login.code.create');
        }

        $request->merge(['code' => preg_replace('/\s+/', '', $request->string('code')->toString())]);
        $request->validate(['code' => ['required', 'string', 'digits:'.LoginCode::LENGTH]]);

        $limits = [
            'login-code-verify:email:'.$this->throttleKey($email) => self::FAILURES_PER_EMAIL,
            'login-code-verify:ip:'.$request->ip() => self::FAILURES_PER_IP,
        ];
        $this->throttle('code', $limits, hit: false);

        $user = $this->findUser($email);

        if (! $user || $user->hasEnabledTwoFactorAuthentication() || ! LoginCode::redeem($user, $request->string('code')->toString())) {
            foreach (array_keys($limits) as $position => $key) {
                RateLimiter::hit($key, $position === 0 ? self::FAILURE_SECONDS_PER_EMAIL : self::THROTTLE_SECONDS);
            }

            throw ValidationException::withMessages([
                'code' => __('This code is not valid or has expired. Check it or ask for a new one.'),
            ]);
        }

        RateLimiter::clear(array_key_first($limits));

        if (! $user->hasVerifiedEmail()) {
            // The code proves who owns the address; whoever registered the account did not have to.
            $user->forceFill(['password' => Str::password(32)])->save();
            $user->revokeAccessExceptPassword();
            $user->markEmailAsVerified();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_EMAIL);
        // The code just proved control of the mailbox, which is what resets a password: it stands in for confirming
        // one, so someone who never set a password can add a passkey under Settings → Security.
        $request->session()->passwordConfirmed();

        return redirect()->intended(config('fortify.home'));
    }

    private function findUser(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private function throttleKey(string $email): string
    {
        return Str::transliterate($email);
    }

    /**
     * Refuse the request when one of the limits is used up.
     *
     * @param  array<string, int>  $limits  Maximum attempts per rate limiter key.
     */
    private function throttle(string $field, array $limits, bool $hit = true): void
    {
        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    $field => __('Too many attempts. Please try again in :minutes minutes.', [
                        'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                    ]),
                ]);
            }
        }

        if ($hit) {
            foreach (array_keys($limits) as $key) {
                RateLimiter::hit($key, self::THROTTLE_SECONDS);
            }
        }
    }
}
