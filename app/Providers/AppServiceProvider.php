<?php

namespace App\Providers;

use App\Models\SmsSetting;
use App\Models\SubscriptionTransaction;
use App\Support\BranchClosingWorkflow;
use App\Support\BranchDomainScope;
use App\Support\BranchSettings;
use App\Support\Messaging\HttpSmsGateway;
use App\Support\Messaging\LogSmsGateway;
use App\Support\Messaging\SmsGateway;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The reading and closing schedules are read by every week and period calculation, so loaded once per request or job.
        $this->app->scoped(BranchSettings::class);

        // SMS go out through the provider set up in the dashboard while it is on, else the gateway named in
        // services.sms.driver; until one is set up they're only logged.
        $this->app->bind(SmsGateway::class, function (): SmsGateway {
            $setting = SmsSetting::active();

            if ($setting !== null) {
                return new HttpSmsGateway($setting->gatewayConfig(), $setting->country_code);
            }

            return match (config('services.sms.driver')) {
                'http' => new HttpSmsGateway(config('services.sms.http'), (string) config('services.sms.country_code')),
                default => new LogSmsGateway,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        BranchDomainScope::register();
        SubscriptionTransaction::created(function (SubscriptionTransaction $payment): void {
            app(BranchClosingWorkflow::class)->capture($payment);
        });
        SubscriptionTransaction::updated(function (SubscriptionTransaction $payment): void {
            if ($payment->wasChanged(['currency', 'currency_amount', 'amount', 'payment_method', 'status'])) {
                app(BranchClosingWorkflow::class)->syncEditablePayment($payment);
            }
        });
        SubscriptionTransaction::deleting(function (SubscriptionTransaction $payment): void {
            app(BranchClosingWorkflow::class)->releaseEditablePayment($payment);
        });
        Password::defaults(fn (): Password => Password::min(8));
    }
}
