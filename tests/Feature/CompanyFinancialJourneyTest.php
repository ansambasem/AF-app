<?php

namespace Tests\Feature;

use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ClosingSetting;
use App\Models\MeterReading;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CompanyFinancialJourneyTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([false, '0', '0', '100.00', '100.00'])]
    #[TestWith([true, '0', '0', '100.00', '100.00'])]
    #[TestWith([false, '20', '30', '80.00', '70.00'])]
    #[TestWith([true, '20', '30', '80.00', '70.00'])]
    #[TestWith([false, '40', '60', '60.00', '40.00'])]
    #[TestWith([true, '40', '60', '60.00', '40.00'])]
    #[TestWith([false, '50', '75', '50.00', '25.00'])]
    #[TestWith([true, '50', '75', '50.00', '25.00'])]
    public function test_new_subscriber_billing_and_mixed_collections_reconcile_once_after_manual_or_automatic_closure(bool $automatic, string $firstReceived, string $secondReceived, string $firstBalance, string $secondBalance): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->withPermissions([
            PermissionKey::RecordLatePayments, PermissionKey::AddClearings,
        ])->create();
        $auditor = User::factory()->financialAuditor()->create();
        $tariff = Tariff::factory()->residential()->create(['rate' => '0.50']);
        $this->actingAs($head)->post(route('subscriptions.store'), [
            'full_name' => 'مشترك دورة الفحص', 'national_id' => '012345678',
            'phone' => '0590000000', 'tariff_id' => $tariff->id, 'status' => 'active',
            'initial_reading' => 100, 'minimum_charge' => 20,
            'subscription_fee' => '100', 'charge_subscription_fee' => true,
        ])->assertSessionHasNoErrors();
        $subscription = Subscription::sole();
        $this->assertSame(100.0, $subscription->balance());

        $this->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => '2026-10-16', 'current_reading' => 200,
        ])->assertSessionHasNoErrors();
        $reading = MeterReading::sole();
        $this->assertSame(100.0, $subscription->balance());
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasNoErrors();
        $this->post(route('meter-readings.approve'), ['reading_ids' => [$reading->id]])->assertSessionHasErrors('reading_ids');
        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $this->assertSame('50.00', $reading->fresh()->amount_due);
        $this->assertSame(150.0, $subscription->balance());

        $this->post(route('subscriptions.charges.store', $subscription), ['type' => 'penalty', 'amount' => '40', 'notes' => 'تحميل اختبار'])->assertSessionHasNoErrors();
        $this->post(route('subscriptions.discounts.store', $subscription), ['method' => 'shekel', 'value' => '10', 'notes' => 'خصم اختبار'])->assertSessionHasNoErrors();
        $this->actingAs($collector)->post(route('subscriptions.clearings.store', $subscription), ['amount' => '20', 'notes' => 'مقاصة خدمة'])->assertSessionHasNoErrors();
        $this->post(route('subscriptions.payments.store', $subscription), ['amount' => '30', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $late = ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash',
            'late_payment' => true, 'paid_on' => '2026-10-21', 'late_reason' => 'نسيان تسجيل السند',
            'late_payment_token' => Str::uuid()->toString()];
        $this->post(route('subscriptions.payments.store', $subscription), $late)->assertSessionHasErrors('late_payment');
        $this->post(route('subscriptions.payments.store', $subscription), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(120.0, $subscription->balance());
        $other = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($other)->create(['amount' => '100.00']);
        $this->post(route('payments.split.store'), [
            'total_amount' => '100', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'مرسل الحوالة',
            'reference_number' => 'JOURNEY-SPLIT', 'parts' => [
                ['subscription_id' => $subscription->id, 'amount' => '40'],
                ['subscription_id' => $other->id, 'amount' => '60'],
            ],
        ])->assertSessionHasNoErrors();
        $this->post(route('subscriptions.payments.store', $subscription), [
            'amount' => '20', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'مرسل آخر', 'reference_number' => 'JOURNEY-NORMAL',
        ])->assertSessionHasNoErrors();
        $this->assertSame(60.0, $subscription->balance());
        $this->assertSame(40.0, $other->balance());
        $splitParts = SplitPayment::sole()->payments;
        $originals = SubscriptionTransaction::query()->get()->mapWithKeys(fn (SubscriptionTransaction $line): array => [$line->id => $line->getAttributes()]);
        $boxes = CashBox::query()->get();
        $this->assertCount(3, $boxes);

        if ($automatic) {
            $boundary = $boxes->first()->closing->coverage_until;
            $this->assertSame('2026-10-24 00:00:00', $boundary->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i:s'));
            $this->travelTo($boundary->subSecond());
            $this->artisan('closings:process')->assertSuccessful();
            $this->assertSame(3, CashBox::query()->where('status', 'open')->count());
            $this->travelTo($boundary);
            $this->artisan('closings:process')->assertSuccessful();
            $this->artisan('closings:process')->assertSuccessful();
            $this->assertSame(3, CashBox::query()->where('close_reason', CashBox::CLOSE_REASON_PERIOD_END)->count());
        } else {
            foreach ($boxes as $box) {
                $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $branch->id])->assertSessionHasNoErrors();
            }
        }
        $this->assertSame(3, CashBox::query()->where('status', 'received')->count());
        $closed = $boxes->mapWithKeys(fn (CashBox $box): array => [$box->id => $box->fresh()->getAttributes()]);

        $entry = $splitParts[0]->boxEntry;
        $review = $this->actingAs($auditor)->getJson(route('payments.bank-audits.show', $entry))->assertOk()->json('split');
        $this->post(route('payments.bank-audits.store', $entry), [
            'bank_amount' => (string) ((float) $firstReceived + (float) $secondReceived),
            'reason' => 'توزيع المبلغ الواصل للحوالة المقسمة', 'revision' => $review['revision'],
            'parts' => [
                ['transaction_id' => $splitParts[0]->id, 'bank_amount' => $firstReceived],
                ['transaction_id' => $splitParts[1]->id, 'bank_amount' => $secondReceived],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame(60.0, $subscription->balance());
        $this->assertSame(40.0, $other->balance());
        $preview = $this->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'place' => 'closed', 'week' => '2026-10-17']))->inertiaProps('periodAudit');
        $this->assertSame(3, $preview['boxes']);
        $this->assertSame(3, $preview['transfers']);
        $approval = [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true];
        $this->post(route('payments.period-audits.store'), $approval)->assertSessionHasNoErrors();
        $this->post(route('payments.period-audits.store'), $approval)->assertSessionHasNoErrors();
        $this->assertSame((float) $firstBalance, $subscription->balance());
        $this->assertSame((float) $secondBalance, $other->balance());
        $this->assertDatabaseCount('cash_box_audits', 3);
        $this->assertDatabaseCount('bank_transfer_audits', 3);
        $this->assertSame($firstReceived === '40' ? 0 : 2, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT)->count());
        foreach ($originals as $id => $attributes) {
            $this->assertSame($attributes, SubscriptionTransaction::findOrFail($id)->getAttributes());
        }
        foreach ($closed as $id => $attributes) {
            $this->assertSame($attributes, CashBox::findOrFail($id)->getAttributes());
        }
        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn (AssertableInertia $page) => $page->where('summary.balance', $firstBalance));

        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['amount' => '5', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(1, CashBox::query()->where('status', 'open')->count());
        $this->assertSame((float) $firstBalance - 5, $subscription->balance());
        $this->assertDatabaseCount('cash_box_audits', 3);
    }
}
