<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CampTwoReadingSamplesSeeder extends Seeder
{
    /** @var list<array{account: string, consumption: int}> */
    private const SAMPLES = [
        ['account' => '202600021', 'consumption' => 18],
        ['account' => '202600022', 'consumption' => 12],
        ['account' => '202600001', 'consumption' => 35],
        ['account' => '202600012', 'consumption' => 20],
        ['account' => '202600015', 'consumption' => 30],
    ];

    /** Prepare consecutive pending readings without replacing the tester's work. */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Sample readings are only available locally or in tests.');
        }

        DB::transaction(function (): void {
            $branch = Branch::query()->where('seed_key', 'camp-two')->lockForUpdate()->sole();
            $recorder = User::query()->where('role', UserRole::SuperAdmin)->where('is_active', true)->orderBy('id')->firstOrFail();
            $currentWeek = MeterReading::latestEndedWeekStart($branch);
            $previousWeek = MeterReading::weekStartFor($currentWeek->copy()->subDay(), $branch);
            $subscriptions = Subscription::query()->where('branch_id', $branch->id)
                ->where('status', SubscriptionStatus::Active)->whereNull('archived_at')
                ->whereIn('account_number', array_column(self::SAMPLES, 'account'))
                ->with(['tariff', 'circuitBreaker', 'standingDiscount'])->get()->keyBy('account_number');
            $created = 0;

            foreach (self::SAMPLES as $sample) {
                $subscription = $subscriptions->get($sample['account']);

                if ($subscription === null || $subscription->meterReadings()->whereDate('week_start', '>=', $currentWeek->toDateString())->exists()) {
                    continue;
                }

                if (! $subscription->meterReadings()->whereDate('week_start', $previousWeek->toDateString())->exists()) {
                    MeterReading::recordFor($subscription, $previousWeek, $subscription->previousReadingBefore($currentWeek), $recorder,
                        'قراءة تجريبية لاستكمال الأسبوع السابق مع الحفاظ على قيمة البداية');
                }

                MeterReading::recordFor($subscription, $currentWeek, $subscription->previousReadingBefore($currentWeek) + $sample['consumption'], $recorder,
                    'قراءة حالية تجريبية لاختبار تعديل القراءة السابقة');
                $created++;
            }

            $this->command?->info('Prepared current readings for '.$created.' subscribers. Existing readings and approvals were kept.');
        });
    }
}
