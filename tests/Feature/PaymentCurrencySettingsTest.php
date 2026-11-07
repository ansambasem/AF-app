<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Models\Bank;
use App\Models\CurrencyRate;
use App\Models\MobileAccessToken;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentCurrencySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_manages_rates_and_existing_payments_keep_their_rate(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $rate = CurrencyRate::query()->where('currency', 'EUR')->sole();
        $subscription = Subscription::factory()->create();
        $payment = SubscriptionTransaction::recordPayment($subscription, $admin, ['amount' => '20', 'currency' => 'EUR', 'exchange_rate' => '4.1', 'payment_method' => 'cash']);

        $this->actingAs($admin)->put(route('settings.currencies.update', $rate), ['rate' => '4.2500'])->assertSessionHasNoErrors()->assertRedirect(route('settings.payments.index'));

        $this->assertDatabaseHas('currency_rates', ['id' => $rate->id, 'rate' => '4.2500', 'updated_by' => $admin->id]);
        $this->assertSame(['-82.00', '20.00', '4.1000'], [$payment->fresh()->amount, $payment->fresh()->currency_amount, $payment->fresh()->exchange_rate]);
        $this->get(route('settings.payments.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Payments')->has('currencies', 4)->has('banks', 6)
            ->where('currencies.3.rate', '4.2500')->where('storeUrl', route('settings.banks.store'))->where('can.managePaymentSettings', true));
        $this->get(route('subscriptions.payments.receipt', [$subscription, $payment]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('receipt.currencyLabel', 'يورو')->where('receipt.inShekels', '82'));
    }

    #[TestWith(['ILS', '1', '-20.00'])]
    #[TestWith(['USD', '3.7', '-74.00'])]
    #[TestWith(['JOD', '5.215', '-104.30'])]
    #[TestWith(['EUR', '4.25', '-85.00'])]
    public function test_payments_in_each_currency_keep_the_original_amount_and_credit_shekels(string $currency, string $rate, string $expected): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();
        CurrencyRate::query()->where('currency', $currency)->update(['rate' => $rate]);

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => '20', 'currency' => $currency, 'exchange_rate' => $rate, 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $payment = $subscription->transactions()->where('type', 'payment')->sole();
        $this->assertSame([$expected, $currency, '20.00'], [$payment->amount, $payment->currency->value, $payment->currency_amount]);
        $this->assertDatabaseHas('cash_box_payments', ['subscription_transaction_id' => $payment->id, 'currency' => 'ILS', 'amount' => ltrim($expected, '-')]);
    }

    public function test_new_payments_can_use_the_managed_exchange_rate(): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();
        CurrencyRate::query()->where('currency', 'EUR')->update(['rate' => '4.2500']);

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => '20', 'currency' => 'EUR', 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subscription_transactions', ['subscription_id' => $subscription->id, 'currency' => 'EUR', 'currency_amount' => '20.00', 'exchange_rate' => '4.2500', 'amount' => '-85.00']);
    }

    public function test_mobile_euro_payment_keeps_its_currency_and_exchange_rate(): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();
        CurrencyRate::query()->where('currency', 'EUR')->update(['rate' => '4.2500']);

        $data = [
            'mobile_operation_id' => (string) Str::uuid(), 'collector_confirmed' => true,
            'subscription_id' => $subscription->id, 'amount' => '20', 'currency' => 'EUR', 'exchange_rate' => '0.01', 'payment_method' => 'cash',
        ];
        $response = $this->withToken(MobileAccessToken::issue($collector))->postJson(route('mobile.collections.store'), $data)
            ->assertCreated()->assertJsonPath('amount', '20.00')->assertJsonPath('currency', 'EUR')->assertJsonPath('amount_in_shekels', '85.00');

        $this->assertDatabaseHas('subscription_transactions', ['subscription_id' => $subscription->id, 'currency' => 'EUR', 'currency_amount' => '20.00', 'exchange_rate' => '4.2500', 'amount' => '-85.00']);
        CurrencyRate::query()->where('currency', 'EUR')->update(['rate' => '5.0000']);
        $this->postJson(route('mobile.collections.store'), $data)->assertCreated()->assertJsonPath('id', $response->json('id'))->assertJsonPath('amount_in_shekels', '85.00');
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    #[TestWith(['0.01', '0.0001'])]
    #[TestWith(['1000000', '10000'])]
    public function test_converted_amount_must_fit_the_payment_limits(string $amount, string $rate): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();
        CurrencyRate::query()->where('currency', 'EUR')->update(['rate' => $rate]);

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => $amount, 'currency' => 'EUR', 'exchange_rate' => $rate, 'payment_method' => 'cash', 'confirm_overpayment' => true])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['4.12345'])]
    #[TestWith(['10001'])]
    #[TestWith(['wrong'])]
    public function test_submitted_exchange_rates_cannot_override_the_dashboard_rate(string $rate): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();
        CurrencyRate::query()->where('currency', 'EUR')->update(['rate' => '4.2500']);

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => '20', 'currency' => 'EUR', 'exchange_rate' => $rate, 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subscription_transactions', ['subscription_id' => $subscription->id, 'currency' => 'EUR', 'exchange_rate' => '4.2500', 'amount' => '-85.00']);
    }

    public function test_a_custom_rate_cannot_be_used_when_the_dashboard_rate_is_missing(): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => '20', 'currency' => 'EUR', 'exchange_rate' => '4.25', 'payment_method' => 'cash'])->assertSessionHasErrors('exchange_rate');

        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->assertDatabaseCount('cash_box_payments', 0);
    }

    public function test_overpayment_confirmation_uses_the_converted_amount(): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '100']);
        $data = ['amount' => '200', 'currency' => 'EUR', 'exchange_rate' => '4', 'payment_method' => 'cash'];
        CurrencyRate::query()->where('currency', 'EUR')->update(['rate' => '4.0000']);

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), $data)->assertSessionHasErrors('confirm_overpayment');

        $this->assertDatabaseCount('subscription_transactions', 1);
        $this->post(route('subscriptions.payments.store', $subscription), [...$data, 'confirm_overpayment' => true])->assertSessionHasNoErrors();
        $this->assertSame(-700.0, $subscription->fresh()->balance());
    }

    public function test_bank_names_icons_and_sender_receiver_roles_are_managed_and_offered_to_collectors(): void
    {
        Storage::fake('public');
        $admin = User::factory()->superAdmin()->create();
        $data = ['name' => 'بنك تجريبي', 'is_receiver' => true, 'is_sender' => false, 'is_active' => true];

        $this->actingAs($admin)->post(route('settings.banks.store'), [...$data, 'logo' => UploadedFile::fake()->image('bank.png')])->assertSessionHasNoErrors()->assertRedirect(route('settings.payments.index'));

        $bank = Bank::query()->where('name', 'بنك تجريبي')->sole();
        Storage::disk('public')->assertExists($bank->logo_path);
        $this->assertContains($bank->name, Bank::receiverNames());
        $this->assertNotContains($bank->name, Bank::senderNames());
        $this->get(route('settings.payments.index'))->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Payments')->has('currencies', 4)->has('banks', 7));
        $this->get(route('payments.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('transferBanks', fn ($banks): bool => collect($banks)->contains($bank->name))
            ->where('paymentOptions.banks', fn ($banks): bool => collect($banks)->contains(fn ($option): bool => $option['name'] === $bank->name && $option['logo'] === $bank->logoUrl())));

        $this->put(route('settings.banks.update', $bank), [...$data, 'is_receiver' => false, 'is_sender' => true])->assertSessionHasNoErrors();
        $this->assertNotContains($bank->name, Bank::receiverNames());
        $this->assertContains($bank->name, Bank::senderNames());
        $this->put(route('settings.banks.update', $bank), [...$data, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertNotContains($bank->name, Bank::receiverNames());
        Storage::disk('public')->assertExists($bank->fresh()->logo_path);
        $oldLogo = $bank->logo_path;
        $this->post(route('settings.banks.update', $bank), [...$data, '_method' => 'put', 'logo' => UploadedFile::fake()->image('replacement.png')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($oldLogo);
        Storage::disk('public')->assertExists($bank->fresh()->logo_path);
    }

    public function test_transfer_validation_respects_managed_bank_roles(): void
    {
        $collector = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($collector->branch)->create();
        $receiver = Bank::factory()->create(['is_sender' => false]);
        $sender = Bank::factory()->create(['is_receiver' => false]);
        $data = ['amount' => '20', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'sender_name' => 'أحمد', 'bank_name' => $sender->name, 'sender_bank_name' => $receiver->name];

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), $data)->assertSessionHasErrors(['bank_name', 'sender_bank_name']);

        $this->assertDatabaseCount('subscription_transactions', 0);
        $this->post(route('subscriptions.payments.store', $subscription), [...$data, 'bank_name' => $receiver->name, 'sender_bank_name' => $sender->name])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscription_transactions', ['bank_name' => $receiver->name, 'sender_bank_name' => $sender->name]);
    }

    public function test_only_company_admins_can_manage_payment_settings(): void
    {
        $rate = CurrencyRate::query()->where('currency', 'EUR')->sole();
        $this->get(route('settings.payments.index'))->assertRedirect(route('login'));
        $this->get(route('settings.currencies.index'))->assertRedirect(route('login'));
        $this->get(route('settings.banks.index'))->assertRedirect(route('login'));
        $staff = User::factory()->branchAdmin()->create();

        $this->actingAs($staff)->get(route('settings.payments.index'))->assertForbidden();
        $this->put(route('settings.currencies.update', $rate), ['rate' => '4'])->assertForbidden();
        $this->post(route('settings.banks.store'), ['name' => 'غير مسموح'])->assertForbidden();
        $this->put(route('settings.banks.update', Bank::query()->firstOrFail()), ['name' => 'غير مسموح'])->assertForbidden();

        $this->assertNull($rate->fresh()->rate);
        $this->assertDatabaseMissing('banks', ['name' => 'غير مسموح']);
    }

    #[TestWith(['settings.currencies.index'])]
    #[TestWith(['settings.banks.index'])]
    public function test_previous_settings_links_redirect_to_the_combined_page(string $route): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get(route($route))->assertRedirect(route('settings.payments.index'));
    }

    public function test_base_currency_invalid_bank_roles_and_unsafe_logos_are_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->superAdmin()->create();
        $shekel = CurrencyRate::query()->where('currency', Currency::Shekel)->sole();
        $data = ['name' => 'بنك اختباري', 'is_receiver' => false, 'is_sender' => false, 'is_active' => true, 'logo' => UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')];

        $this->actingAs($admin)->put(route('settings.currencies.update', $shekel), ['rate' => '2'])->assertSessionHasErrors('rate');
        $this->post(route('settings.banks.store'), $data)->assertSessionHasErrors(['is_receiver', 'logo']);

        $this->assertSame('1.0000', $shekel->fresh()->rate);
        $this->assertDatabaseMissing('banks', ['name' => 'بنك اختباري']);
        Storage::disk('public')->assertDirectoryEmpty('bank-logos');
    }
}
