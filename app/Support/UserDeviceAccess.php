<?php

namespace App\Support;

use App\Models\MobileAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserDeviceAccess
{
    public const LIMIT_MESSAGE = 'انتهت جلسة هذا الجهاز بعد تسجيل الدخول من جهاز آخر. سجّل الدخول مجددًا للمتابعة.';

    public function prepareWebLogin(User $user): void
    {
        $lockedUser = User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $this->makeRoom($lockedUser);
        $user->setRememberToken($lockedUser->getRememberToken());
    }

    public function claimWeb(Request $request, User $user, bool $newLogin = false): void
    {
        DB::transaction(function () use ($request, $user, $newLogin): void {
            $user = User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $identity = ['user_id' => $user->id, 'session_hash' => hash('sha256', $request->session()->getId())];
            $device = DB::table('user_login_devices')->where($identity)->first();
            if (! $newLogin && ! Auth::guard('web')->viaRemember() && $request->session()->get('device_access_user') === $user->id && $device === null) {
                throw ValidationException::withMessages(['username' => 'أُنهِيت جلسة هذا الجهاز. سجّل الدخول مجددًا.']);
            }
            if ($device === null || CarbonImmutable::parse($device->expires_at)->lessThanOrEqualTo(now())) {
                $this->assertCapacity($user);
            }
            $timestamps = [
                'created_at' => $device?->created_at ?? now(), 'updated_at' => now(),
                'expires_at' => now()->addMinutes(config('session.lifetime')),
            ];
            if ($device === null) {
                $id = DB::table('user_login_devices')->insertGetId([...$identity, ...$timestamps]);
            } else {
                $id = $device->id;
                DB::table('user_login_devices')->where($identity)->update($timestamps);
            }
            if (! $this->allowed($user, 'web:'.$id)) {
                throw ValidationException::withMessages(['username' => self::LIMIT_MESSAGE]);
            }
            $request->session()->put('device_access_user', $user->id);
        });
    }

    public function issueMobile(User $user, ?string $existingToken, bool $remember = false): string
    {
        return DB::transaction(function () use ($user, $existingToken, $remember): string {
            $user = User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $token = $existingToken === null ? null : MobileAccessToken::query()->where('user_id', $user->id)
                ->where('token_hash', hash('sha256', $existingToken))->where('expires_at', '>', now())->first();
            if ($token !== null && $this->allowed($user, 'mobile:'.$token->id)) {
                $token->update(['expires_at' => $remember ? now()->addDays(30) : now()->addHours(12)]);

                return $existingToken;
            }
            $this->makeRoom($user);

            return MobileAccessToken::issue($user, $remember);
        });
    }

    public function activeCount(User $user): int
    {
        return count($this->activeDevices($user));
    }

    public function allowed(User $user, string $identity): bool
    {
        return in_array($identity, array_column(array_slice($this->activeDevices($user), 0, $user->max_devices ?? 1), 'identity'), true);
    }

    public function releaseWeb(Request $request): void
    {
        DB::table('user_login_devices')->where('user_id', $request->user()?->id)
            ->where('session_hash', hash('sha256', $request->session()->getId()))->delete();
    }

    public function revokeAll(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            DB::table('user_login_devices')->where('user_id', $user->id)->delete();
            MobileAccessToken::query()->where('user_id', $user->id)->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        });
    }

    private function assertCapacity(User $user): void
    {
        if ($this->activeCount($user) >= ($user->max_devices ?? 1)) {
            throw ValidationException::withMessages(['username' => self::LIMIT_MESSAGE]);
        }
    }

    private function makeRoom(User $user): void
    {
        $devices = $this->activeDevices($user);
        $rotateRememberToken = ($user->max_devices ?? 1) === 1;
        usort($devices, fn (array $first, array $second): int => strcmp($first['issued_at'], $second['issued_at']) ?: strnatcmp($first['identity'], $second['identity']));
        $removeCount = max(0, count($devices) - ($user->max_devices ?? 1) + 1);
        foreach (array_slice($devices, 0, $removeCount) as $device) {
            [$type, $id] = explode(':', $device['identity'], 2);
            if ($type === 'mobile') {
                MobileAccessToken::query()->where('user_id', $user->id)->whereKey($id)->delete();

                continue;
            }
            $registration = DB::table('user_login_devices')->where('user_id', $user->id)->where('id', $id)->first();
            if ($registration === null) {
                continue;
            }
            DB::table('user_login_devices')->where('user_id', $user->id)->where('id', $id)->delete();
            if (config('session.driver') === 'database') {
                $sessions = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
                $sessionIds = (clone $sessions)->where('user_id', $user->id)->pluck('id')
                    ->filter(fn (string $sessionId): bool => hash_equals($registration->session_hash, hash('sha256', $sessionId)))->all();
                $sessions->where('user_id', $user->id)->whereIn('id', $sessionIds)->delete();
            }
            $rotateRememberToken = true;
        }
        if ($rotateRememberToken) {
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        }
    }

    /** @return array<int, array{identity: string, created_at: string, issued_at: string}> */
    private function activeDevices(User $user): array
    {
        $devices = DB::table('user_login_devices')->where('user_id', $user->id)->where('expires_at', '>', now())->get(['id', 'created_at'])
            ->map(fn (\stdClass $device): array => ['identity' => 'web:'.$device->id, 'created_at' => (string) $device->created_at, 'issued_at' => (string) $device->created_at])->all();
        foreach (MobileAccessToken::query()->where('user_id', $user->id)->where('expires_at', '>', now())->get(['id', 'created_at', 'last_used_at']) as $token) {
            $devices[] = ['identity' => 'mobile:'.$token->id, 'created_at' => ($token->last_used_at ?? $token->created_at)->toDateTimeString(), 'issued_at' => $token->created_at->toDateTimeString()];
        }
        usort($devices, fn (array $first, array $second): int => strcmp($second['created_at'], $first['created_at']) ?: strnatcmp($first['identity'], $second['identity']));

        return $devices;
    }
}
