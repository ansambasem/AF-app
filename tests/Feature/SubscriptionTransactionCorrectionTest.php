<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\DiscountMethod;
use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Closing;
use App\Models\ClosingPayment;
use App\Models\CurrencyRate;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SubscriptionTransactionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id, 'name' => 'Sami']);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad']);
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '200.00']);
    }

    public function test_correcting_a_payment_cancels_it_and_records_the_right_one_under_it(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);

        $this->correct($payment, [
            ...$this->transfer('100'),
            'correction_reason' => 'wrong_amount',
            'correction_notes' => 'المشترك دفع 100 وليس 80',
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'transaction-corrected')
            ->assertRedirect(route('subscriptions.statement', $this->subscription));

        $payment->refresh();
        $this->assertTrue($payment->isCancelled());
        $this->assertSame(['wrong_amount', 'المشترك دفع 100 وليس 80', $this->branchAdmin->id], [$payment->cancellation_reason->value, $payment->cancellation_notes, $payment->cancelled_by]);

        $reversal = SubscriptionTransaction::where('reverses_id', $payment->id)->sole();
        $this->assertSame(['reversal', '80.00', 'cash'], [$reversal->type, $reversal->amount, $reversal->payment_method->value]);

        $replacement = $payment->correction;
        $this->assertSame(['payment', '-100.00', 'bank_transfer', 'بنك فلسطين'], [$replacement->type, $replacement->amount, $replacement->payment_method->value, $replacement->bank_name]);
        // The right payment is a bank transfer, which has no voucher number.
        $this->assertNull($replacement->voucher_number);
        $this->assertSame(100.0, $this->subscription->balance());
        $this->assertSame(
            ['action' => 'transaction-corrected', 'subject' => 'Ahmad — دفعة 100 شيكل'],
            $this->branchAdmin->notifications()->latest('id')->first()->data,
        );

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.id', $payment->id)
                ->where('entries.1.cancellation.wasCorrected', true)
                ->where('entries.1.cancellation.correctionId', $replacement->id)
                ->where('entries.1.cancellation.correctionLineNumber', 4)
                ->where('entries.1.cancellation.reasonLabel', 'مبلغ خاطئ')
                ->where('entries.1.cancellation.byName', 'Sami')
                ->where('entries.1.canCorrect', false)
                ->where('entries.2.id', $reversal->id)
                ->where('entries.2.isFollowUp', true)
                ->where('entries.2.reverses.id', $payment->id)
                ->where('entries.2.reverses.lineNumber', 2)
                ->where('entries.2.isCredit', false)
                ->where('entries.2.description', 'إلغاء: دفعة نقدية · سند '.$payment->printedVoucherNumber())
                ->where('entries.2.balance', '200.00')
                ->where('entries.3.id', $replacement->id)
                ->where('entries.3.isCorrection', true)
                ->where('entries.3.corrects.lineNumber', 2)
                ->where('entries.3.canCorrect', true)
                ->where('entries.3.balance', '100.00')
                ->where('summary.paid', '100.00')
                ->where('summary.paymentsCount', 1));
    }

    public function test_a_corrected_cash_payment_keeps_its_voucher_without_finalizing_its_open_box_number(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash', 'manual_voucher_number' => '00412', 'cash_box' => '4554']);

        $this->correct($payment, [
            'amount' => '100',
            'currency' => 'ILS',
            'payment_method' => 'cash',
            'manual_voucher_number' => '00413',
            'cash_box' => '4554',
            'correction_reason' => 'wrong_amount',
            'correction_notes' => 'x',
        ])->assertSessionHasNoErrors();

        $fee = $this->subscription->transactions()->oldest('id')->first();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.groupId', $fee->id)
                ->where('entries.1.groupId', $payment->id)
                ->where('entries.2.groupId', $payment->id)
                ->where('entries.2.isReversal', true)
                ->where('entries.2.voucherNumber', '00412')
                ->where('entries.2.systemVoucherNumber', $payment->printedVoucherNumber())
                ->where('entries.2.manualVoucherNumber', '00412')
                ->where('entries.2.cashBox', null)
                ->where('entries.3.groupId', $payment->correction->id)
                ->where('entries.3.corrects.id', $payment->id)
                ->where('entries.3.voucherNumber', '00413')
                ->where('entries.3.manualVoucherNumber', '00413')
                ->where('entries.3.recorded.manual_voucher_number', '00413')
                ->where('entries.3.recorded.cash_box', '4554'));
    }

    public function test_a_foreign_payment_can_be_corrected_in_its_original_currency(): void
    {
        $payment = $this->recordPayment(['amount' => '20', 'currency' => 'USD', 'exchange_rate' => '3.7', 'payment_method' => 'cash']);
        CurrencyRate::query()->where('currency', 'USD')->update(['rate' => '4.0000']);

        $this->correct($payment, ['amount' => '22', 'currency' => 'USD', 'exchange_rate' => '3.7', 'payment_method' => 'cash', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'اختبار'])
            ->assertSessionHasNoErrors();

        $replacement = SubscriptionTransaction::where('corrects_id', $payment->id)->sole();
        $this->assertSame(['-88.00', 'USD', '22.00', '4.0000'], [$replacement->amount, $replacement->currency->value, $replacement->currency_amount, $replacement->exchange_rate]);
        $this->assertSame('3.7000', $payment->fresh()->exchange_rate);
        $this->assertTrue($payment->fresh()->isCancelled());
    }

    public function test_the_reversal_stays_under_its_line_and_the_correction_comes_last_after_the_lines_between(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $charge = SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '20', null);

        $this->correct($payment, [...$this->transfer('80'), 'correction_reason' => 'wrong_payment_method', 'correction_notes' => 'حوّلها عبر البنك'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.id', $payment->id)
                ->where('entries.2.id', $charge->id)
                ->where('entries.3.type', 'reversal')
                ->where('entries.3.isFollowUp', true)
                ->where('entries.4.isCorrection', true)
                ->where('entries.4.isFollowUp', false)
                ->where('entries.4.corrects.id', $payment->id)
                ->where('entries.4.paymentMethod', 'bank_transfer')
                ->where('entries.4.balance', '140.00')
                ->where('summary.balance', '140.00'));
    }

    public function test_correcting_a_transfer_preserves_the_original_bank_pair_and_prefills_the_replacement_pair(): void
    {
        $payment = $this->recordPayment([
            ...$this->transfer('25'),
            'sender_bank_name' => 'جوال باي',
        ]);

        $this->correct($payment, [
            ...$this->transfer('30'),
            'bank_name' => 'البنك الإسلامي الفلسطيني',
            'sender_bank_name' => 'البنك الإسلامي العربي',
            'correction_reason' => 'wrong_amount',
            'correction_notes' => 'تصحيح المبلغ والبنك',
        ])->assertSessionHasNoErrors();

        $this->assertSame('جوال باي', $payment->fresh()->sender_bank_name);
        $this->assertSame('البنك الإسلامي العربي', $payment->fresh()->correction->sender_bank_name);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.bankName', 'بنك فلسطين')
                ->where('entries.1.senderBankName', 'جوال باي')
                ->where('entries.2.isReversal', true)
                ->where('entries.2.bankName', 'بنك فلسطين')
                ->where('entries.2.senderBankName', 'جوال باي')
                ->where('entries.3.bankName', 'البنك الإسلامي الفلسطيني')
                ->where('entries.3.senderBankName', 'البنك الإسلامي العربي')
                ->where('entries.3.recorded.bank_name', 'البنك الإسلامي الفلسطيني')
                ->where('entries.3.recorded.sender_bank_name', 'البنك الإسلامي العربي'));
    }

    public function test_amending_payment_details_keeps_the_financial_line_and_records_every_change(): void
    {
        $payment = $this->recordPayment([
            ...$this->transfer('25'),
            'sender_bank_name' => 'جوال باي',
            'notes' => 'البيان الأول',
        ]);
        $balance = $this->subscription->balance();
        $recordedAt = $payment->created_at->toJSON();
        $financialFields = $payment->only([
            'subscription_id',
            'type',
            'amount',
            'currency',
            'currency_amount',
            'exchange_rate',
            'payment_method',
            'voucher_number',
        ]);

        $this->amend($payment, [
            'bank_name' => 'البنك الإسلامي الفلسطيني',
            'sender_bank_name' => 'البنك الإسلامي العربي',
            'sender_name' => 'أحمد محمد',
            'notes' => 'تم تدقيق الحوالة',
            'amendment_reason' => 'اختير البنك الخطأ عند التسجيل',
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'transaction-amended');

        $payment->refresh();
        $this->assertSame($financialFields, $payment->only(array_keys($financialFields)));
        $this->assertSame($recordedAt, $payment->created_at->toJSON());
        $this->assertSame($balance, $this->subscription->balance());
        $this->assertSame(
            ['البنك الإسلامي الفلسطيني', 'البنك الإسلامي العربي', 'أحمد محمد', 'TR-1', 'تم تدقيق الحوالة'],
            [$payment->bank_name, $payment->sender_bank_name, $payment->sender_name, $payment->reference_number, $payment->notes],
        );

        $firstAmendment = $payment->amendments()->sole();
        $this->assertSame($this->branchAdmin->id, $firstAmendment->user_id);
        $this->assertSame('اختير البنك الخطأ عند التسجيل', $firstAmendment->reason);
        $this->assertSame(['بنك فلسطين', 'البنك الإسلامي الفلسطيني'], $firstAmendment->changes['bank_name']);
        $this->assertArrayNotHasKey('reference_number', $firstAmendment->changes);

        $this->amend($payment, [
            'bank_name' => 'البنك الإسلامي الفلسطيني',
            'sender_bank_name' => 'البنك الإسلامي العربي',
            'sender_name' => 'أحمد محمد',
            'reference_number' => 'TR-201',
            'notes' => 'تم تدقيق الحوالة',
            'amendment_reason' => 'تصحيح الرقم المرجعي',
        ])->assertSessionHasNoErrors();
        $this->assertSame('TR-201', $payment->fresh()->reference_number);
        $this->assertSame('TR201', $payment->fresh()->active_reference);

        $this->amend($payment, [
            'bank_name' => 'البنك الإسلامي الفلسطيني',
            'sender_bank_name' => 'البنك الإسلامي العربي',
            'sender_name' => 'أحمد محمد',
            'notes' => 'ملاحظة جديدة',
            'amendment_reason' => 'تحديث الملاحظة',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.id', $payment->id)
                ->where('entries.1.isAmended', true)
                ->where('entries.1.canAmend', true)
                ->where('entries.1.amendUnavailableReason', null)
                ->has('entries.1.amendments', 3)
                ->where('entries.1.amendments.0.userName', 'Sami')
                ->where('entries.1.amendments.0.reason', 'اختير البنك الخطأ عند التسجيل')
                ->where('entries.1.amendments.0.changes.0.label', 'البنك المستلم')
                ->where('entries.1.amendments.0.changes.0.from', 'بنك فلسطين')
                ->where('entries.1.amendments.0.changes.0.to', 'البنك الإسلامي الفلسطيني')
                ->where('entries.1.amendments.1.reason', 'تصحيح الرقم المرجعي')
                ->where('entries.1.amendments.2.reason', 'تحديث الملاحظة')
                ->where('entries.1.amendments.2.changes.0.label', 'الملاحظات')
                ->where('entries.1.amendments.2.changes.0.to', 'ملاحظة جديدة')
                ->where('entries.1.recorded.reference_number', 'TR-201')
                ->where('entries.1.balance', '175.00')
                ->where('summary.balance', '175.00'));
    }

    public function test_amending_details_rejects_financial_fields_and_a_no_op(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash', 'notes' => 'نقد']);

        $this->amend($payment, [
            'notes' => 'نقد مصحح',
            'amount' => '100',
            'payment_method' => 'bank_transfer',
            'amendment_reason' => 'محاولة تغيير مالي',
        ])->assertSessionHasErrors(['amount', 'payment_method']);

        $this->assertSame(['-80.00', 'cash', 'نقد'], [$payment->fresh()->amount, $payment->payment_method->value, $payment->notes]);
        $this->assertDatabaseCount('transaction_amendments', 0);

        $this->amend($payment, ['notes' => 'نقد', 'amendment_reason' => 'لا يوجد تغيير'])
            ->assertSessionHasErrors('details');
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_payments_in_submitted_or_approved_closings_cannot_be_amended(): void
    {
        $submittedPayment = $this->recordPayment(['amount' => '40', 'payment_method' => 'cash']);
        $approvedPayment = $this->recordPayment(['amount' => '60', 'payment_method' => 'cash']);
        $submitted = Closing::factory()->submitted()->forDay('2026-10-01')->create(['branch_id' => $this->branch->id]);
        $approved = Closing::factory()->approved()->forDay('2026-10-02')->create(['branch_id' => $this->branch->id]);
        ClosingPayment::create(['closing_id' => $submitted->id, 'subscription_transaction_id' => $submittedPayment->id]);
        ClosingPayment::create(['closing_id' => $approved->id, 'subscription_transaction_id' => $approvedPayment->id]);

        $this->amend($submittedPayment, ['notes' => 'بعد الإغلاق', 'amendment_reason' => 'x'])->assertForbidden();
        $this->amend($approvedPayment, ['notes' => 'بعد الاعتماد', 'amendment_reason' => 'x'])->assertForbidden();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.canAmend', false)
                ->where('entries.1.amendUnavailableReason', 'بعد إغلاق اليوم')
                ->where('entries.2.canAmend', false)
                ->where('entries.2.amendUnavailableReason', 'بعد إغلاق اليوم'));
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_amending_payment_details_requires_the_correction_permission_and_only_applies_to_payments(): void
    {
        $fee = $this->subscription->transactions()->sole();
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions, PermissionKey::RecordCollections]));

        $this->actingAs($collector)
            ->patch(route('subscriptions.transactions.amend', [$this->subscription, $payment]), ['notes' => 'x', 'amendment_reason' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->branchAdmin)
            ->patch(route('subscriptions.transactions.amend', [$this->subscription, $fee]), ['notes' => 'x', 'amendment_reason' => 'x'])
            ->assertForbidden();

        $this->actingAs($collector)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.1.canAmend', false)
                ->where('entries.1.amendUnavailableReason', null));
        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.canAmend', false)
                ->where('entries.0.amendUnavailableReason', 'متاح للدفعات فقط'));
        $this->assertDatabaseCount('transaction_amendments', 0);
    }

    public function test_correcting_a_charge_a_discount_and_a_clearing_records_the_right_ones(): void
    {
        $charge = SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '50', 'تأخير');
        $discount = SubscriptionTransaction::recordDiscount($this->subscription, $this->branchAdmin, DiscountMethod::Shekel, '30', null);
        $clearing = SubscriptionTransaction::recordClearing($this->subscription, $this->branchAdmin, '60', 'صيانة المولد');

        $this->correct($charge, ['type' => 'disconnection_fee', 'amount' => '40', 'notes' => 'فصل', 'correction_reason' => 'wrong_type', 'correction_notes' => 'كانت رسوم قطع'])
            ->assertSessionHasNoErrors();
        $this->correct($discount, ['method' => 'shekel', 'value' => '35', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'الخصم 35'])
            ->assertSessionHasNoErrors();
        $this->correct($clearing, ['amount' => '70', 'notes' => 'صيانة المولد والكوابل', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'قيمة الخدمة 70'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['disconnection_fee', '40.00', 'فصل'], [$charge->correction->type, $charge->correction->amount, $charge->correction->notes]);
        $this->assertSame(['discount', '-35.00'], [$discount->correction->type, $discount->correction->amount]);
        $this->assertSame(['clearing', '-70.00', 'صيانة المولد والكوابل'], [$clearing->correction->type, $clearing->correction->amount, $clearing->correction->notes]);
        $this->assertSame(135.0, $this->subscription->balance());
    }

    public function test_a_corrected_clearing_is_checked_against_the_balance_without_the_one_it_replaces(): void
    {
        $clearing = SubscriptionTransaction::recordClearing($this->subscription, $this->branchAdmin, '60', 'صيانة المولد');

        // Without it the subscription owes 200: a clearing of 201 needs confirming, 200 is the whole debt.
        $this->correct($clearing, ['amount' => '201', 'notes' => 'صيانة', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'اختبار'])
            ->assertSessionHasErrors('confirm_over_balance');
        $this->assertFalse($clearing->fresh()->isCancelled());

        $this->correct($clearing, ['amount' => '200', 'notes' => 'صيانة', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'اختبار'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->subscription->balance());
    }

    public function test_a_subscription_fee_recorded_later_can_be_corrected_with_an_audit_trail(): void
    {
        $charge = SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::SubscriptionFee, '50', null);

        $this->correct($charge, ['type' => 'subscription_fee', 'amount' => '40', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'الرسوم 40'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'transaction-corrected');

        $this->assertTrue($charge->fresh()->isCancelled());
        $this->assertDatabaseHas('subscription_transactions', ['corrects_id' => $charge->id, 'type' => 'subscription_fee', 'amount' => '40.00']);
        $this->assertDatabaseHas('subscription_transactions', ['reverses_id' => $charge->id, 'type' => 'reversal', 'amount' => '-50.00']);
        $this->assertSame(240.0, $this->subscription->balance());
    }

    public function test_a_subscription_fee_recorded_by_hand_uses_the_regular_charge_deletion_reasons(): void
    {
        $charge = SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::SubscriptionFee, '50', null);

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $charge]), ['correction_reason' => 'fee_cancelled', 'correction_notes' => 'x'])
            ->assertSessionHasErrors('correction_reason');
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $charge]), ['correction_reason' => 'duplicate', 'correction_notes' => 'سُجّلت مرتين'])
            ->assertSessionHasNoErrors();

        $this->assertSame('duplicate', $charge->refresh()->cancellation_reason->value);
    }

    public function test_a_corrected_discount_is_checked_against_the_balance_without_the_one_it_replaces(): void
    {
        $this->subscription->transactions()->delete();
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '50.00']);
        $discount = SubscriptionTransaction::recordDiscount($this->subscription, $this->branchAdmin, DiscountMethod::Shekel, '50', null);

        $this->correct($discount, ['method' => 'shekel', 'value' => '45', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'الخصم 45'])
            ->assertSessionHasNoErrors();
        $this->correct($discount->correction, ['method' => 'shekel', 'value' => '60', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'الخصم 60'])
            ->assertSessionHasErrors(['value' => 'لا يمكن أن يزيد الخصم (60 شيكل) عن الرصيد المستحق (50 شيكل).']);
    }

    public function test_deleting_a_line_cancels_it_with_a_reversal_and_keeps_it_on_the_statement(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);

        $this->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'duplicate', 'correction_notes' => 'سُجّلت مرتين'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'transaction-deleted');

        $this->assertTrue($payment->refresh()->isCancelled());
        $this->assertNull($payment->correction);
        $this->assertSame(200.0, $this->subscription->balance());

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->has('entries', 3)
                ->where('entries.1.cancellation.wasCorrected', false)
                ->where('entries.1.cancellation.reasonLabel', 'حركة مكررة')
                ->where('entries.1.canDelete', false)
                ->where('entries.2.type', 'reversal')
                ->where('summary.paid', '0.00')
                ->where('summary.paymentsCount', 0));
    }

    public function test_a_correction_needs_a_reason_that_fits_and_an_explanation(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);

        $this->correct($payment, $this->transfer('100'))
            ->assertSessionHasErrors(['correction_reason', 'correction_notes']);
        $this->correct($payment, [...$this->transfer('100'), 'correction_reason' => 'duplicate', 'correction_notes' => 'x'])
            ->assertSessionHasErrors('correction_reason');
        $this->correct($payment, ['amount' => '0', 'currency' => 'ILS', 'payment_method' => 'cash', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'x'])
            ->assertSessionHasErrors('amount');

        $this->assertFalse($payment->refresh()->isCancelled());
        $this->assertDatabaseCount('subscription_transactions', 2);
    }

    public function test_correcting_and_deleting_take_their_own_permissions_within_the_branch(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $collector->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions, PermissionKey::RecordCollections, PermissionKey::CorrectTransactions]));
        $deletion = ['correction_reason' => 'duplicate', 'correction_notes' => 'x'];

        $this->actingAs($collector)->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), $deletion)->assertForbidden();
        $this->actingAs(User::factory()->branchAdmin()->create())
            ->put(route('subscriptions.transactions.update', [$this->subscription, $payment]), [...$this->transfer('100'), 'correction_reason' => 'wrong_amount', 'correction_notes' => 'x'])
            ->assertForbidden();
        $this->assertFalse($payment->refresh()->isCancelled());

        $this->actingAs($collector)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('entries.1.canCorrect', true)->where('entries.1.canDelete', false));
    }

    public function test_a_registration_fee_can_be_deleted_with_a_reason_but_not_corrected(): void
    {
        $fee = $this->subscription->transactions()->sole();

        $this->actingAs($this->branchAdmin)
            ->put(route('subscriptions.transactions.update', [$this->subscription, $fee]), ['correction_reason' => 'wrong_amount', 'correction_notes' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $fee]), ['correction_reason' => 'payment_refunded', 'correction_notes' => 'x'])
            ->assertSessionHasErrors('correction_reason');
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $fee]), ['correction_reason' => 'fee_cancelled', 'correction_notes' => 'الرسوم أُعفي منها'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['fee_cancelled', 'الرسوم أُعفي منها'], [$fee->refresh()->cancellation_reason->value, $fee->cancellation_notes]);
        $this->assertSame(0.0, $this->subscription->balance());
    }

    public function test_a_payment_can_be_deleted_as_refunded(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'payment_refunded', 'correction_notes' => 'استرجع المبلغ'])
            ->assertSessionHasNoErrors();

        $this->assertSame('payment_refunded', $payment->refresh()->cancellation_reason->value);
    }

    public function test_erasing_a_line_for_good_takes_its_own_permission(): void
    {
        $fee = $this->subscription->transactions()->sole();
        $erasure = ['correction_notes' => 'سُجّلت بالخطأ'];

        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $fee]), $erasure)->assertForbidden();
        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('entries.0.canDelete', true)->where('entries.0.canForceDelete', false));

        $this->grantPermanentDeletionTo($this->branchAdmin);

        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $fee]), ['correction_notes' => ''])->assertSessionHasErrors('correction_notes');
        Log::spy();

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $fee]), $erasure)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'transaction-erased');

        $this->assertDatabaseMissing('subscription_transactions', ['id' => $fee->id]);
        $this->assertSame(0.0, $this->subscription->balance());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Transaction erased for good'
            && $context['transactions'][0]['id'] === $fee->id
            && $context['transactions'][0]['source_key'] === $fee->source_key
            && $context['erased_by']['id'] === $this->branchAdmin->id
            && $context['reason'] === 'سُجّلت بالخطأ');

        $this->assertDatabaseHas('transaction_deletions', [
            'subscription_id' => $this->subscription->id,
            'user_id' => $this->branchAdmin->id,
            'action' => 'erase',
            'reason' => 'سُجّلت بالخطأ',
        ]);
    }

    public function test_permanent_deletion_permission_also_offers_normal_deletion_for_the_last_line(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $this->branchAdmin->permissions()->detach(Permission::idsFor([PermissionKey::DeleteTransactions]));
        $this->grantPermanentDeletionTo($this->branchAdmin);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.canDelete', false)
                ->where('entries.0.canForceDelete', false)
                ->where('entries.1.canDelete', true)
                ->where('entries.1.canForceDelete', true));

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), [
                'correction_reason' => 'payment_refunded',
                'correction_notes' => 'استرجع المبلغ',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'transaction-deleted');

        $this->assertTrue($payment->refresh()->isCancelled());
        $this->assertDatabaseHas('subscription_transactions', ['reverses_id' => $payment->id]);
    }

    public function test_only_the_last_line_of_the_statement_can_be_erased(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $fee = $this->subscription->transactions()->sole();
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);

        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $fee]), ['correction_notes' => 'x'])->assertForbidden();
        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('entries.0.canForceDelete', false)->where('entries.1.canForceDelete', true));

        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $payment]), ['correction_notes' => 'x'])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('subscription_transactions', ['id' => $payment->id]);
        $this->assertDatabaseHas('subscription_transactions', ['id' => $fee->id]);
    }

    public function test_erasing_a_last_reversal_takes_the_cancelled_line_with_it_and_keeps_the_balance(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'duplicate', 'correction_notes' => 'x']);
        $reversal = SubscriptionTransaction::where('reverses_id', $payment->id)->sole();

        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $payment]), ['correction_notes' => 'x'])->assertForbidden();
        Log::spy();
        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $reversal]), ['correction_notes' => 'x'])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('subscription_transactions', ['id' => $payment->id]);
        $this->assertDatabaseMissing('subscription_transactions', ['id' => $reversal->id]);
        $this->assertSame(200.0, $this->subscription->balance());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Transaction erased for good'
            && collect($context['transactions'])->pluck('id')->all() === [$payment->id, $reversal->id]);
    }

    public function test_a_reversal_grouped_above_a_newer_line_is_not_the_last_line_of_the_statement(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $charge = SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '20', null);
        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), [
            'correction_reason' => 'duplicate',
            'correction_notes' => 'x',
        ]);
        $reversal = SubscriptionTransaction::where('reverses_id', $payment->id)->sole();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.2.id', $charge->id)
                ->where('entries.2.canForceDelete', true)
                ->where('entries.3.id', $reversal->id)
                ->where('entries.3.canForceDelete', false));
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $reversal]), ['correction_notes' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $charge]), ['correction_notes' => 'x'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subscription_transactions', ['id' => $reversal->id]);
        $this->assertDatabaseMissing('subscription_transactions', ['id' => $charge->id]);
        $this->assertSame(200.0, $this->subscription->balance());
    }

    public function test_a_permanently_erased_payment_voucher_number_is_never_reused(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $erased = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $erased]), ['correction_notes' => 'سند ملغى'])
            ->assertSessionHasNoErrors();
        $next = $this->recordPayment(['amount' => '90', 'payment_method' => 'cash']);

        $this->assertSame($erased->voucher_number + 1, $next->voucher_number);
        $this->assertDatabaseMissing('subscription_transactions', ['id' => $erased->id]);
    }

    public function test_a_payment_counted_in_a_closing_cannot_be_permanently_erased(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $closing = Closing::factory()->create(['branch_id' => $this->branch->id]);
        ClosingPayment::create(['closing_id' => $closing->id, 'subscription_transaction_id' => $payment->id]);

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $payment]), ['correction_notes' => 'x'])
            ->assertForbidden();

        $this->assertDatabaseHas('subscription_transactions', ['id' => $payment->id]);
    }

    public function test_permanent_deletion_is_limited_to_the_users_branch_but_a_super_admin_can_use_it_in_any_branch(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $otherBranch = Branch::factory()->create();
        $otherSubscription = Subscription::factory()->create(['branch_id' => $otherBranch->id]);
        $fee = SubscriptionTransaction::factory()->for($otherSubscription)->create();

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$otherSubscription, $fee]), ['correction_notes' => 'x'])
            ->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('subscriptions.transactions.force-destroy', [$otherSubscription, $fee]), ['correction_notes' => 'x'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('subscription_transactions', ['id' => $fee->id]);
    }

    public function test_erasing_a_corrections_replacement_brings_the_corrected_payment_back(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        [$original, $replacement, $reversal] = $this->correctedPayment();
        $this->assertSame(50.0, $this->subscription->balance());

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $replacement]), ['correction_notes' => 'التصحيح كان خطأ'])
            ->assertSessionHasNoErrors();

        $this->assertCorrectionTakenBack($original, $replacement, $reversal);
    }

    public function test_deleting_a_corrections_replacement_from_the_statement_brings_the_corrected_payment_back(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        [$original, $replacement, $reversal] = $this->correctedPayment();

        $this->actingAs($this->branchAdmin)
            ->post(route('subscriptions.transactions.actions.store', [$this->subscription, $replacement]), ['action' => 'delete', 'correction_notes' => 'التصحيح كان خطأ'])
            ->assertSessionHasNoErrors();

        $this->assertCorrectionTakenBack($original, $replacement, $reversal);
    }

    public function test_the_statement_previews_the_balance_a_correction_goes_back_to(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        [, $replacement] = $this->correctedPayment();

        $this->actingAs($this->branchAdmin)->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('entries', fn ($entries): bool => collect($entries)->firstWhere('id', $replacement->id)['actionEffects']['delete'] === '-50.00'
                && collect($entries)->firstWhere('id', $replacement->id)['balance'] === '50.00'));
    }

    public function test_a_correction_is_not_taken_back_when_the_original_transfer_reference_is_used_elsewhere(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $original = $this->recordPayment($this->transfer('100'));
        $this->correct($original, [...$this->transfer('150'), 'reference_number' => 'TR-2', 'correction_reason' => 'wrong_amount', 'correction_notes' => 'x']);
        $replacement = $original->refresh()->correction;
        $this->recordPaymentOn(Subscription::factory()->create(['branch_id' => $this->branch->id]), $this->transfer('40'));

        $this->actingAs($this->branchAdmin)
            ->post(route('subscriptions.transactions.actions.store', [$this->subscription, $replacement]), ['action' => 'delete', 'correction_notes' => 'x'])
            ->assertSessionHasErrors('action');

        $this->assertModelExists($replacement);
        $this->assertTrue($original->refresh()->isCancelled());
    }

    public function test_erasing_a_weekly_reading_charge_leaves_its_reading_approved(): void
    {
        $this->grantPermanentDeletionTo($this->branchAdmin);
        $reading = MeterReading::factory()->for($this->subscription)->create([
            'branch_id' => $this->branch->id,
            'recorded_by' => $this->branchAdmin->id,
            'previous_reading' => 1000,
            'current_reading' => 1010,
            'consumption' => 10,
            'unit_price' => '3.00',
            'reading_fee' => '30.00',
            'minimum_payment' => '0.00',
            'discount_amount' => '0.00',
            'amount_due' => '30.00',
        ]);
        $reading->approve($this->branchAdmin);
        $charge = SubscriptionTransaction::where('meter_reading_id', $reading->id)->where('type', 'meter_reading')->sole();

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.force-destroy', [$this->subscription, $charge]), ['correction_notes' => 'قراءة أضيفت بالخطأ'])
            ->assertSessionHasNoErrors();

        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $this->assertDatabaseMissing('subscription_transactions', ['id' => $charge->id]);
        $this->assertSame(200.0, $this->subscription->balance());
    }

    public function test_a_weekly_reading_charge_and_standing_discount_can_be_deleted_without_reopening_or_rebilling_the_reading(): void
    {
        $reading = MeterReading::factory()->for($this->subscription)->create([
            'branch_id' => $this->branch->id,
            'recorded_by' => $this->branchAdmin->id,
            'previous_reading' => 1000,
            'current_reading' => 1010,
            'consumption' => 10,
            'unit_price' => '3.00',
            'reading_fee' => '30.00',
            'minimum_payment' => '0.00',
            'discount_method' => DiscountMethod::Shekel,
            'discount_value' => '0.50',
            'discount_segment' => 'عائلات محتاجة',
            'discount_amount' => '5.00',
            'amount_due' => '25.00',
        ]);
        $reading->approve($this->branchAdmin);

        $charge = SubscriptionTransaction::where('meter_reading_id', $reading->id)->where('type', 'meter_reading')->sole();
        $discount = SubscriptionTransaction::where('meter_reading_id', $reading->id)->where('type', 'reading_discount')->sole();

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $charge]), ['correction_reason' => 'wrong_reading', 'correction_notes' => 'تم تحميل قراءة غير صحيحة'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $discount]), ['correction_reason' => 'wrong_subscription', 'correction_notes' => 'الخصم لمشترك آخر'])
            ->assertSessionHasNoErrors();

        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $this->assertSame($reading->chargeSourceKey(), $charge->refresh()->source_key);
        $this->assertSame($reading->discountSourceKey(), $discount->refresh()->source_key);
        $this->assertDatabaseHas('subscription_transactions', ['reverses_id' => $charge->id, 'amount' => '-30.00']);
        $this->assertDatabaseHas('subscription_transactions', ['reverses_id' => $discount->id, 'amount' => '5.00']);
        $this->assertSame(200.0, $this->subscription->balance());

        $this->assertTrue($reading->correct(1012, 'تصحيح لاحق', $this->branchAdmin));
        $reading->approve($this->branchAdmin);

        $this->assertSame(MeterReadingStatus::Approved, $reading->fresh()->status);
        $this->assertSame(0, SubscriptionTransaction::query()
            ->where('meter_reading_id', $reading->id)
            ->whereIn('type', ['meter_reading', 'reading_discount'])
            ->whereNull('cancelled_at')
            ->count());
        $this->assertSame(200.0, $this->subscription->balance());
    }

    public function test_reversals_and_cancelled_lines_cannot_be_deleted_again(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $deletion = ['correction_reason' => 'duplicate', 'correction_notes' => 'x'];

        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), $deletion)->assertSessionHasNoErrors();
        $reversal = SubscriptionTransaction::where('reverses_id', $payment->id)->sole();

        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), $deletion)->assertForbidden();
        $this->actingAs($this->branchAdmin)->delete(route('subscriptions.transactions.destroy', [$this->subscription, $reversal]), $deletion)->assertForbidden();
        $this->assertSame(200.0, $this->subscription->balance());
    }

    public function test_deletion_is_limited_to_the_users_branch_but_a_super_admin_can_delete_in_any_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherSubscription = Subscription::factory()->create(['branch_id' => $otherBranch->id]);
        $fee = SubscriptionTransaction::factory()->for($otherSubscription)->create();
        $deletion = ['correction_reason' => 'fee_cancelled', 'correction_notes' => 'أُلغيت الرسوم'];

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$otherSubscription, $fee]), $deletion)
            ->assertForbidden();
        $this->assertFalse($fee->refresh()->isCancelled());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete(route('subscriptions.transactions.destroy', [$otherSubscription, $fee]), $deletion)
            ->assertSessionHasNoErrors();
        $this->assertTrue($fee->refresh()->isCancelled());
    }

    public function test_a_line_of_another_subscription_is_not_found(): void
    {
        $other = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $payment = SubscriptionTransaction::recordPayment($other, $this->branchAdmin, ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), ['correction_reason' => 'duplicate', 'correction_notes' => 'x'])
            ->assertNotFound();
    }

    public function test_the_financial_log_lists_cancelled_lines_but_leaves_them_out_of_its_figures(): void
    {
        $payment = $this->recordPayment(['amount' => '80', 'payment_method' => 'cash']);
        $this->correct($payment, [...$this->transfer('100'), 'correction_reason' => 'wrong_amount', 'correction_notes' => 'x']);

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['filter' => ['type' => 'credit']]))
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 1)
                ->where('summary.total', 100)
                ->where('summary.collected', 100));

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index'))
            ->assertInertia(fn ($page) => $page
                ->where('entries.total', 4)
                ->where('summary.total', 200)
                ->where('summary.collected', 100));
    }

    /**
     * A cash payment of 100 corrected to 150: the original, then its
     * replacement, with the original's reversal between them.
     *
     * @return array{0: SubscriptionTransaction, 1: SubscriptionTransaction, 2: SubscriptionTransaction}
     */
    private function correctedPayment(): array
    {
        $original = $this->recordPayment(['amount' => '100', 'payment_method' => 'cash']);

        $this->correct($original, [
            'amount' => '150',
            'currency' => 'ILS',
            'payment_method' => 'cash',
            'correction_reason' => 'wrong_amount',
            'correction_notes' => 'المشترك دفع 150',
        ])->assertSessionHasNoErrors();

        return [$original->refresh(), $original->correction, SubscriptionTransaction::where('reverses_id', $original->id)->sole()];
    }

    private function assertCorrectionTakenBack(SubscriptionTransaction $original, SubscriptionTransaction $replacement, SubscriptionTransaction $reversal): void
    {
        $original->refresh();

        $this->assertModelMissing($replacement);
        $this->assertModelMissing($reversal);
        $this->assertFalse($original->isCancelled());
        $this->assertSame([SubscriptionTransaction::STATUS_ACTIVE, null, null, null], [$original->status, $original->cancelled_at, $original->cancelled_by, $original->cancellation_reason]);
        // What the subscriber paid is back on the account: 200 owed less the 100 paid.
        $this->assertSame(100.0, $this->subscription->balance());
        $this->assertSame('100.00', $original->balance_after);
        $this->assertSame(['-100.00'], SubscriptionTransaction::where('subscription_id', $this->subscription->id)->where('type', 'payment')->pluck('amount')->all());
        $this->assertEqualsCanonicalizing([$replacement->id, $reversal->id], array_column(TransactionDeletion::query()->sole()->transactions, 'id'));
    }

    /**
     * @param  array<string, string>  $details
     */
    private function recordPaymentOn(Subscription $subscription, array $details): SubscriptionTransaction
    {
        return SubscriptionTransaction::recordPayment($subscription, $this->branchAdmin, ['currency' => 'ILS', ...$details]);
    }

    /**
     * @param  array<string, string>  $details
     */
    private function recordPayment(array $details): SubscriptionTransaction
    {
        return SubscriptionTransaction::recordPayment($this->subscription, $this->branchAdmin, ['currency' => 'ILS', ...$details]);
    }

    /**
     * @return array<string, string>
     */
    private function transfer(string $amount): array
    {
        return ['amount' => $amount, 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'TR-1'];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function correct(SubscriptionTransaction $line, array $data): TestResponse
    {
        return $this->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->put(route('subscriptions.transactions.update', [$this->subscription, $line]), $data);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function amend(SubscriptionTransaction $line, array $data): TestResponse
    {
        return $this->actingAs($this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->patch(route('subscriptions.transactions.amend', [$this->subscription, $line]), $data);
    }

    private function grantPermanentDeletionTo(User $user): void
    {
        $user->permissions()->syncWithoutDetaching(Permission::idsFor([PermissionKey::ForceDeleteTransactions]));
        $user->unsetRelation('permissions');
    }
}
