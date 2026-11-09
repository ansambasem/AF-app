<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\BranchDomainContext;
use App\Support\LoginCredentials;
use App\Support\UserDeviceAccess;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials. A stopped
     * account cannot sign in, even with the right password. A branch's
     * link admits only those who may enter that branch, and with branch
     * links on, the central site only the Super Admin and the auditors:
     * anyone else is told the details are wrong, as for a wrong password.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $isStopped = false;
        $signedIn = DB::transaction(function () use (&$isStopped): bool {
            // As typed, then as a computer keyboard would have typed it (a phone's Arabic digits; see LoginCredentials).
            $signedIn = false;
            foreach (LoginCredentials::variants($this->string('username')->toString(), $this->string('password')->toString()) as $credentials) {
                if ($signedIn = $this->attemptWith($credentials, $isStopped)) {
                    break;
                }
            }
            if ($signedIn) {
                $this->session()->regenerate();
                app(UserDeviceAccess::class)->claimWeb($this, $this->user(), newLogin: true);
            }

            return $signedIn;
        });

        if (! $signedIn) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'username' => trans($isStopped ? 'auth.inactive' : 'auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Sign in with these credentials when they are right and the account
     * may enter here; `$isStopped` says when it was refused only for being
     * stopped.
     *
     * @param  array{username: string, password: string}  $credentials
     */
    private function attemptWith(array $credentials, bool &$isStopped): bool
    {
        return Auth::attemptWhen(
            $credentials,
            function (User $user) use (&$isStopped): bool {
                $isStopped = ! $user->is_active;

                $branch = BranchDomainContext::branch();

                if ($isStopped) {
                    return false;
                }

                $allowed = $branch !== null ? $user->canEnterBranch($branch)
                    : (! config('powercollect.branch_domains_enabled') || $user->canUseCentralSite());
                if ($allowed) {
                    app(UserDeviceAccess::class)->prepareWebLogin($user);
                }

                return $allowed;
            },
            $this->boolean('remember'),
        );
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('username')).'|'.$this->ip());
    }
}
