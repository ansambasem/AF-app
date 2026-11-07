<?php

namespace Tests\Feature\Settings;

use App\Enums\MessageStatus;
use App\Jobs\SendSubscriptionMessage;
use App\Models\SmsSetting;
use App\Models\SubscriptionMessage;
use App\Models\User;
use App\Support\Messaging\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * The SMS provider set up from the dashboard: only the Super Admin sees and
 * changes it, its secrets stay encrypted and never reach the page, and once
 * on it is the gateway every message goes out through.
 */
class SmsSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
        config(['services.sms.driver' => 'log']);
        Http::preventStrayRequests();
    }

    public function test_only_the_super_admin_sets_up_the_provider(): void
    {
        $branchAdmin = User::factory()->branchAdmin()->create();

        $this->actingAs($branchAdmin)->get(route('settings.sms.edit'))->assertForbidden();
        $this->actingAs($branchAdmin)->put(route('settings.sms.update'), $this->form())->assertForbidden();
        $this->actingAs($branchAdmin)->post(route('settings.sms.test'), $this->form(['test_phone' => '0599123456', 'test_message' => 'x']))->assertForbidden();

        $this->actingAs($this->superAdmin)->get(route('settings.sms.edit'))
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Sms')
                ->where('setting.isSaved', false)
                ->where('setting.to_param', 'to')
                ->where('delivery.delivers', false)
                ->where('delivery.source', 'none'));
    }

    public function test_a_local_vite_preview_does_not_send_ssr_requests_during_provider_tests(): void
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'sms-ssr-');
        file_put_contents($hotFile, 'http://127.0.0.1:5176');
        Vite::useHotFile($hotFile);

        try {
            $this->actingAs($this->superAdmin)->get(route('settings.sms.edit'))
                ->assertInertia(fn ($page) => $page->component('Settings/Sms'));
            Http::assertNothingSent();
        } finally {
            unlink($hotFile);
        }
    }

    public function test_the_password_is_kept_encrypted_and_never_sent_back_to_the_page(): void
    {
        $this->actingAs($this->superAdmin)->put(route('settings.sms.update'), $this->form(['password' => 'top-secret']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'sms-settings-updated');

        $this->assertSame('top-secret', SmsSetting::query()->sole()->password);
        $this->assertStringNotContainsString('top-secret', (string) DB::table('sms_settings')->value('password'));

        // Left blank, the saved password stays; the page only learns that one is saved.
        $this->put(route('settings.sms.update'), $this->form(['sender' => 'AF-New']))->assertSessionHasNoErrors();
        $this->assertSame(['top-secret', 'AF-New'], [SmsSetting::query()->sole()->password, SmsSetting::query()->sole()->sender]);

        $response = $this->get(route('settings.sms.edit'))
            ->assertInertia(fn ($page) => $page->where('setting.hasPassword', true)->where('setting.updatedByName', $this->superAdmin->name)->missing('setting.password'));
        $this->assertStringNotContainsString('top-secret', $response->getContent());

        $this->put(route('settings.sms.update'), $this->form(['clear_password' => true]))->assertSessionHasNoErrors();
        $this->assertNull(SmsSetting::query()->sole()->password);
    }

    public function test_the_provider_is_turned_on_only_with_its_address_and_valid_field_names(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('settings.sms.update'), $this->form(['url' => '', 'sender_param' => 'from name', 'message_param' => 'to']))
            ->assertSessionHasErrors(['url', 'sender_param', 'message_param']);

        $this->put(route('settings.sms.update'), $this->form(['enabled' => false, 'url' => '']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'sms-settings-updated-off');
    }

    public function test_messages_go_out_through_the_dashboards_provider_while_it_is_on(): void
    {
        SmsSetting::factory()->create(['success_match' => 'OK', 'extra_params' => 'type=unicode']);
        Http::fake(['https://sms.example.ps/api/send' => Http::response('OK:1')]);
        $message = SubscriptionMessage::factory()->create(['phone' => '0599123456', 'body' => 'مرحبا']);

        $this->assertTrue(app(SmsGateway::class)->deliversMessages());
        SendSubscriptionMessage::dispatchSync($message);

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        Http::assertSent(fn ($request) => $request['to'] === '970599123456'
            && $request['message'] === 'مرحبا'
            && $request['sender'] === 'AF-Power'
            && $request['user'] === 'af-power'
            && $request['pass'] === 'secret-password'
            && $request['type'] === 'unicode');

        SmsSetting::query()->update(['enabled' => false]);
        $this->assertFalse(app(SmsGateway::class)->deliversMessages());
    }

    public function test_a_test_message_uses_the_page_settings_with_the_saved_password_and_records_the_answer(): void
    {
        $setting = SmsSetting::factory()->disabled()->create(['updated_at' => now()->subDay()]);
        $updatedAt = $setting->updated_at->toDateTimeString();
        Http::fake([
            'https://sms.new.ps/send*' => Http::sequence()->push('accepted')->push('Insufficient balance', 402),
        ]);
        $form = $this->form(['url' => 'https://sms.new.ps/send', 'method' => 'get', 'phone_format' => 'local', 'test_phone' => '0599 123 456', 'test_message' => 'تجربة']);

        $this->actingAs($this->superAdmin)->post(route('settings.sms.test'), $form)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'sms-test-sent')
            ->assertSessionHas('inertia.flash_data.smsTestResult', fn (array $result): bool => $result['ok'] === true && $result['error'] === null);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['to'] === '0599123456' && $request['pass'] === 'secret-password' && $request['message'] === 'تجربة');

        $this->post(route('settings.sms.test'), $form)
            ->assertSessionMissing('status')
            ->assertSessionHas('inertia.flash_data.smsTestResult', fn (array $result): bool => $result['ok'] === false && str_contains($result['error'], 'Insufficient balance'));

        $setting->refresh();
        $this->assertFalse($setting->last_test_ok);
        $this->assertStringContainsString('402', $setting->last_test_error);
        // The test leaves the settings as they were saved: still off, at the old address, not marked as edited.
        $this->assertSame([false, 'https://sms.example.ps/api/send', $updatedAt], [$setting->enabled, $setting->url, $setting->updated_at->toDateTimeString()]);
    }

    /**
     * The page's form: the factory's provider, as the page sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'provider_name' => 'جوال',
            'url' => 'https://sms.example.ps/api/send',
            'method' => 'post',
            'username_param' => 'user',
            'username' => 'af-power',
            'password_param' => 'pass',
            'password' => '',
            'token' => '',
            'to_param' => 'to',
            'message_param' => 'message',
            'sender_param' => 'sender',
            'sender' => 'AF-Power',
            'phone_format' => 'international',
            'country_code' => '970',
            'extra_params' => '',
            'success_match' => '',
            ...$overrides,
        ];
    }
}
