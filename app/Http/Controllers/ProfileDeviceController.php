<?php

namespace App\Http\Controllers;

use App\Models\MobileAccessToken;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfileDeviceController extends Controller
{
    /** @return array<int, array{id: string, type: string, label: string, current: bool, lastActiveAt: string|null}> */
    public static function devices(Request $request): array
    {
        $sessions = config('session.driver') === 'database'
            ? self::sessions()->where('user_id', $request->user()->id)
                ->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)
                ->orderByDesc('last_activity')->get(['id', 'user_agent', 'last_activity'])
                ->map(fn (object $session) => [
                    'id' => hash('sha256', $session->id),
                    'type' => 'session',
                    'label' => self::browserLabel($session->user_agent ?? ''),
                    'current' => $session->id === $request->session()->getId(),
                    'lastActiveAt' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                ])
            : collect();

        if (! $sessions->contains('current', true)) {
            $sessions->prepend([
                'id' => hash('sha256', $request->session()->getId()),
                'type' => 'session',
                'label' => self::browserLabel($request->userAgent() ?? ''),
                'current' => true,
                'lastActiveAt' => now()->toIso8601String(),
            ]);
        }

        $mobile = MobileAccessToken::query()->where('user_id', $request->user()->id)->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')->get(['id', 'last_used_at', 'created_at'])
            ->map(fn (MobileAccessToken $token) => [
                'id' => (string) $token->id,
                'type' => 'mobile',
                'label' => 'تطبيق الميدان',
                'current' => false,
                'lastActiveAt' => ($token->last_used_at ?? $token->created_at)?->toIso8601String(),
            ]);

        return $sessions->merge($mobile)->values()->all();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('deviceLogout', [
            'password' => ['required', 'current_password'],
            'type' => ['required', 'in:all,session,mobile'],
            'id' => ['required_unless:type,all', 'nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();

        if ($validated['type'] === 'mobile') {
            $token = MobileAccessToken::query()->where('user_id', $user->id)->findOrFail($validated['id']);
            $token->delete();
        } else {
            abort_unless(config('session.driver') === 'database' || $validated['type'] === 'all', 404);

            if ($validated['type'] === 'session') {
                $session = self::sessions()->where('user_id', $user->id)->get(['id'])->first(
                    fn (object $session) => hash_equals(hash('sha256', $session->id), $validated['id']),
                );
                abort_unless($session !== null, 404);
                abort_if($session->id === $request->session()->getId(), 422, 'لا يمكن تسجيل خروج الجهاز الحالي من هنا.');
                self::sessions()->where('user_id', $user->id)->where('id', $session->id)->delete();
                DB::table('user_login_devices')->where('user_id', $user->id)->where('session_hash', hash('sha256', $session->id))->delete();
                // Retired sessions must not sign in again through an old remember-me cookie.
                $user->forceFill(['remember_token' => Str::random(60)])->save();
            } else {
                self::signOutOtherDevices($request);
            }
        }

        return to_route('profile.edit')->with('status', 'profile-devices-logged-out');
    }

    /**
     * End every session of the user but this one, and every mobile app
     * sign-in. Retired sessions must not sign in again through an old
     * remember-me cookie either.
     */
    public static function signOutOtherDevices(Request $request): void
    {
        $user = $request->user();

        if (config('session.driver') === 'database') {
            self::sessions()->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }

        MobileAccessToken::query()->where('user_id', $user->id)->delete();
        DB::table('user_login_devices')->where('user_id', $user->id)
            ->where('session_hash', '!=', hash('sha256', $request->session()->getId()))->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    private static function sessions(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table'));
    }

    private static function browserLabel(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg') => 'Edge',
            str_contains($agent, 'Firefox'), str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome'), str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'متصفح الموقع',
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system ? $browser.' على '.$system : $browser;
    }
}
