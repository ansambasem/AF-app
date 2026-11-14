<?php

namespace Database\Seeders;

use App\Enums\DiscountMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ClosingSetting;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchClosingWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinancialFlowSeeder extends Seeder
{
    public const COLLECTOR_USERNAME = 'finance_demo';

    public const SOURCE_PREFIX = 'sample-financial-flow:';

    /** Add a repeatable local walkthrough through the real payment and box workflow. */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Financial sample data is only available locally or in tests.');
        }

        DB::transaction(function (): void {
            $this->call(TeamTestingSeeder::class);
            $branch = Branch::query()->where('seed_key', 'camp-two')->lockForUpdate()->sole();

            if (SubscriptionTransaction::query()->where('source_key', self::SOURCE_PREFIX.'invoice:0')->exists()) {
                $this->command?->info('Financial sample data already exists; transactions and box progress were kept.');

                return;
            }

            if (User::query()->where('username', self::COLLECTOR_USERNAME)->exists()) {
                throw new RuntimeException('The financial demo username is already in use.');
            }

            DB::table('payment_voucher_sequences')->insertOrIgnore(['id' => 1, 'last_number' => 0]);
            $counter = DB::table('payment_voucher_sequences')->where('id', 1);
            $lastNumber = (int) $counter->lockForUpdate()->value('last_number');
            $counter->update(['last_number' => max($lastNumber, (int) SubscriptionTransaction::query()->max('voucher_number'))]);

            $collector = User::query()->create([
                'name' => 'محصّل تجريبي للتدفق المالي',
                'username' => self::COLLECTOR_USERNAME,
                'password' => 'password',
                'role' => UserRole::Collector,
                'branch_id' => $branch->id,
                'is_active' => true,
            ]);
            $collector->permissions()->sync(Permission::idsFor(UserRole::Collector->starterPermissions()));
            $manager = User::query()->where('username', 'muhammad')->sole();
            $workflow = app(BranchClosingWorkflow::class);

            if (! ClosingSetting::ownFor($branch)?->workflow_started_at) {
                $workflow->start($branch->id, $manager);
            } else {
                $workflow->refresh($branch->id);
            }

            $subscriptions = Subscription::query()->where('branch_id', $branch->id)
                ->where('legacy_number', 'like', CampTwoSubscribersSeeder::LEGACY_PREFIX.'%')->orderBy('id')->get();

            foreach ($subscriptions as $index => $subscription) {
                $subscription->transactions()->create([
                    'recorded_by' => $manager->id,
                    'type' => SubscriptionTransaction::TYPE_INVOICE,
                    'source_key' => self::SOURCE_PREFIX.'invoice:'.$index,
                    'amount' => 300 + $index * 10,
                    'notes' => 'فاتورة تجريبية لاختبار كشف الحساب والتحصيل',
                ]);

                $isTransfer = $index % 2 === 1;
                $payment = SubscriptionTransaction::recordPayment($subscription, $collector, [
                    'amount' => match ($index) {
                        2 => 20, 3 => 10, 19 => 550, default => 60 + $index * 5
                    },
                    'currency' => match ($index) {
                        2 => 'USD', 3 => 'JOD', default => 'ILS'
                    },
                    'exchange_rate' => match ($index) {
                        2 => 3.5, 3 => 5, default => 1
                    },
                    'payment_method' => $isTransfer ? 'bank_transfer' : 'cash',
                    'bank_name' => $isTransfer ? config('powercollect.transfer_banks')[0] : null,
                    'sender_bank_name' => $isTransfer ? config('powercollect.transfer_banks')[0] : null,
                    'sender_name' => $isTransfer ? 'مرسل تجريبي '.($index + 1) : null,
                    'reference_number' => $isTransfer ? 'DEMO-FLOW-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT) : null,
                    'notes' => 'دفعة تجريبية لاختبار التدفق المالي',
                ]);
                $payment->update(['source_key' => self::SOURCE_PREFIX.'payment:'.$index]);

                if (in_array($index, [0, 7, 14], true)) {
                    $clearing = SubscriptionTransaction::recordClearing($subscription, $collector, 25, 'خدمة صيانة تجريبية مقابل مقاصة');
                    $clearing->update(['source_key' => self::SOURCE_PREFIX.'clearing:'.$index]);
                }

                if ($index < 2) {
                    $discount = SubscriptionTransaction::recordDiscount($subscription, $manager, DiscountMethod::Shekel, 10, 'خصم تجريبي');
                    $discount->update(['source_key' => self::SOURCE_PREFIX.'discount:'.$index]);
                }

                if (in_array($index, [6, 13], true)) {
                    $boxes = CashBox::query()->where('user_id', $collector->id)->where('status', 'open')->get();
                    foreach ($boxes as $box) {
                        $workflow->receive($box, $manager);
                    }
                }
            }
        });

        $this->command?->info('Financial walkthrough ready: 20 invoices, 20 payments, 3 clearings, 2 discounts, and 9 boxes.');
    }
}
