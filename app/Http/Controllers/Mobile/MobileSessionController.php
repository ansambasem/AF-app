<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\PaymentMethod;
use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\CurrencyRate;
use App\Models\MessageBatch;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\User;
use App\Support\UserDeviceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MobileSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $rateLimitKey = Str::transliterate(Str::lower($validated['username']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json(['message' => trans('auth.throttle', [
                'seconds' => RateLimiter::availableIn($rateLimitKey),
                'minutes' => ceil(RateLimiter::availableIn($rateLimitKey) / 60),
            ])], 429);
        }

        $user = User::query()->where('username', $validated['username'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey);

            return response()->json(['message' => trans('auth.failed')], 422);
        }

        RateLimiter::clear($rateLimitKey);

        abort_if($user->isAuditOnly(), 403);

        return response()->json([
            'user' => $this->userData($user),
            'token' => app(UserDeviceAccess::class)->issueMobile($user, $request->bearerToken(), $request->boolean('remember')),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userData($request->user())]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->attributes->get('mobile_access_token')->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * The banks and e-wallets are the same lists the website's payment form
     * offers (to receive into, and to transfer from), so the app records transfers under names the server accepts.
     *
     * @return array{id: int, name: string, username: string, branch_name: ?string, can_record_readings: bool, can_record_collections: bool, can_view_readings: bool, can_add_clearings: bool, transfer_banks: array<int, string>, sender_banks: array<int, string>}
     */
    private function userData(User $user): array
    {
        $user->loadMissing('branch');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'branch_name' => $user->branch?->name,
            'can_record_readings' => $user->hasPermission(PermissionKey::RecordMeterReadings),
            'can_distribute_readings' => $user->hasPermission(PermissionKey::ManageReadingAssignments),
            'can_approve_reading_assignments' => $user->hasPermission(PermissionKey::ApproveReadingAssignments),
            'can_view_readings' => $user->can('viewAny', MeterReading::class),
            'can_record_collections' => $user->can('recordAnyPayment', Subscription::class),
            'can_adjust_balances' => $user->hasAnyPermission(PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings),
            'can_add_clearings' => $user->hasPermission(PermissionKey::AddClearings),
            'can_view_subscriptions' => $user->can('viewAny', Subscription::class),
            'can_record_split_payments' => $user->can('recordSplitPayment', Subscription::class),
            'can_send_messages' => $user->can('create', MessageBatch::class),
            'can_view_messages' => $user->can('viewAny', MessageBatch::class),
            // The ways the collector may take a payment (each is its own permission): the server refuses the others.
            'payment_methods' => array_map(fn (PaymentMethod $method): string => $method->value, $user->allowedPaymentMethods()),
            'transfer_banks' => Bank::receiverNames(),
            'sender_banks' => Bank::senderNames(),
            'currencies' => CurrencyRate::options(),
        ];
    }
}
