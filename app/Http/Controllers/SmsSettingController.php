<?php

namespace App\Http\Controllers;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Http\Requests\TestSmsSettingRequest;
use App\Http\Requests\UpdateSmsSettingRequest;
use App\Models\SmsSetting;
use App\Models\SubscriptionMessage;
use App\Notifications\ActionCompleted;
use App\Support\Messaging\HttpSmsGateway;
use App\Support\Messaging\SmsDeliveryFailed;
use App\Support\Messaging\SmsGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The company's SMS provider, set up from the dashboard by the Super Admin:
 * where and how messages are sent, the sender name and the credentials,
 * with a test message to check them before turning the provider on.
 */
class SmsSettingController extends Controller
{
    /**
     * Show the provider's settings. The password and token are never sent
     * back, only whether one is saved.
     */
    public function edit(Request $request, SmsGateway $gateway): InertiaResponse
    {
        $this->authorize('manage', SmsSetting::class);

        $setting = SmsSetting::current()->loadMissing('updatedBy');
        $timezone = config('app.business_timezone');
        $since = now()->subDays(7);
        $smsMessages = fn () => SubscriptionMessage::query()->whereHas('batch', fn ($batch) => $batch->where('channel', MessageChannel::Sms));

        return Inertia::render('Settings/Sms', [
            'setting' => [
                ...$setting->only(['enabled', 'provider_name', 'url', 'method', 'username_param', 'username', 'password_param', 'to_param', 'message_param', 'sender_param', 'sender', 'phone_format', 'country_code', 'extra_params', 'success_match']),
                'hasPassword' => filled($setting->password),
                'hasToken' => filled($setting->token),
                'isSaved' => $setting->exists,
                'updatedByName' => $setting->updatedBy?->name,
                'updatedAt' => $setting->updated_at?->timezone($timezone)->format('Y-m-d H:i'),
                'lastTest' => $setting->last_test_at ? [
                    'ok' => $setting->last_test_ok,
                    'at' => $setting->last_test_at->timezone($timezone)->format('Y-m-d H:i'),
                    'error' => $setting->last_test_error,
                ] : null,
            ],
            // Whether messages leave the app now, and through which set-up.
            'delivery' => [
                'delivers' => $gateway->deliversMessages(),
                'source' => SmsSetting::active() !== null ? 'dashboard' : ($gateway->deliversMessages() ? 'server' : 'none'),
            ],
            'week' => [
                'sent' => $smsMessages()->where('status', MessageStatus::Sent)->where('sent_at', '>=', $since)->count(),
                'failed' => $smsMessages()->where('status', MessageStatus::Failed)->where('updated_at', '>=', $since)->count(),
                'pending' => $smsMessages()->where('status', MessageStatus::Pending)->count(),
            ],
        ]);
    }

    /**
     * Save the provider's settings. A password or token left blank keeps the
     * one saved.
     */
    public function update(UpdateSmsSettingRequest $request): RedirectResponse
    {
        $setting = SmsSetting::query()->first() ?? new SmsSetting(SmsSetting::DEFAULTS);
        $setting->fill([...$request->settings($setting), 'updated_by' => $request->user()->id])->save();

        $request->user()->notify(new ActionCompleted($setting->enabled ? 'sms-settings-updated' : 'sms-settings-updated-off'));

        return redirect()->route('settings.sms.edit')->with('status', $setting->enabled ? 'sms-settings-updated' : 'sms-settings-updated-off');
    }

    /**
     * Send a test message with the settings as they are on the page, saved
     * or not, and say what the provider answered. The outcome is kept with
     * the saved settings, if any, as their last test.
     */
    public function test(TestSmsSettingRequest $request): RedirectResponse
    {
        $saved = SmsSetting::query()->first();
        $current = $saved ?? new SmsSetting(SmsSetting::DEFAULTS);
        $gateway = new HttpSmsGateway($request->gatewayConfig($current), $request->validated('country_code'));
        $error = null;

        try {
            $gateway->send($request->validated('test_phone'), $request->validated('test_message'));
        } catch (SmsDeliveryFailed $exception) {
            $error = Str::limit($exception->getMessage(), 250);
            Log::warning('SMS test failed', ['user_id' => $request->user()->id, 'error' => $error]);
        }

        // Kept beside the saved settings without counting as an edit of them.
        if ($saved !== null) {
            SmsSetting::query()->whereKey($saved->id)->toBase()->update(['last_test_at' => now(), 'last_test_ok' => $error === null, 'last_test_error' => $error]);
        }

        Inertia::flash('smsTestResult', [
            'ok' => $error === null,
            'phone' => $request->validated('test_phone'),
            'error' => $error,
        ]);

        // A refused test is shown on the page with the provider's answer, not as a saved action.
        return $error === null ? back()->with('status', 'sms-test-sent') : back();
    }
}
