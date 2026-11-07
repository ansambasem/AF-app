<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Enums\TransactionAction;
use App\Models\BankTransferAudit;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ClosingSetting;
use App\Models\CurrencyRate;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinancialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function pay(User $collector, Subscription $subscription, string $amount, string $method = 'cash', string $reference = 'BANK-LIFECYCLE', string $currency = 'ILS'): SubscriptionTransaction
    {
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), [
            'amount' => $amount, 'currency' => $currency, 'payment_method' => $method,
            'bank_name' => 'بنك فلسطين', 'sender_name' => 'صاحب الحساب', 'reference_number' => $reference,
        ])->assertSessionHasNoErrors();

        return $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->latest('id')->firstOrFail();
    }

    private function closeBox(User $actor, CashBox $box): void
    {
        $this->actingAs($actor)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $box->branch_id])->assertSessionHasNoErrors();
        $this->assertSame('received', $box->fresh()->status);
    }

    #[TestWith(['0', '850.00', '150.00', 'missing'])]
    #[TestWith(['400', '450.00', '550.00', 'short'])]
    #[TestWith(['550', '300.00', '700.00', 'matched'])]
    #[TestWith(['650', '200.00', '800.00', 'over'])]
    #[TestWith(['1200', '-350.00', '1350.00', 'over'])]
    public function test_mixed_subscriber_history_stays_consistent_from_collection_through_final_period_audit(string $verified, string $finalBalance, string $netPaid, string $outcome): void
    {
        $this->travelTo('2026-10-18 10:00:00');
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $closer = User::factory()->accountant()->for($branch)->withPermissions([PermissionKey::CloseCashBoxes])->create();
        $collector = User::factory()->collector()->for($branch)->withPermissions([PermissionKey::AddClearings])->create();
        $secondCollector = User::factory()->collector()->for($branch)->create();
        $auditor = User::factory()->financialAuditor()->create();
        $subscription = Subscription::factory()->for($branch)->create();
        $other = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '1000.00']);
        SubscriptionTransaction::factory()->for($other)->create(['amount' => '200.00']);
        $this->actingAs($head)->post(route('subscriptions.charges.store', $subscription), ['type' => 'penalty', 'amount' => '100', 'notes' => 'غرامة اختبار'])->assertSessionHasNoErrors();
        $this->post(route('subscriptions.discounts.store', $subscription), ['method' => 'shekel', 'value' => '50', 'notes' => 'خصم اختبار'])->assertSessionHasNoErrors();
        $cash = $this->pay($collector, $subscription, '100');
        $bank = $this->pay($collector, $subscription, '550', 'bank_transfer', 'BANK-MAIN');
        $this->actingAs($collector)->post(route('subscriptions.clearings.store', $subscription), ['amount' => '50', 'notes' => 'خدمة صيانة'])->assertSessionHasNoErrors();
        $clearing = $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_CLEARING)->sole();
        $otherBank = $this->pay($secondCollector, $other, '100', 'bank_transfer', 'BANK-OTHER');
        $this->assertSame(350.0, $subscription->balance());
        $this->assertSame(100.0, $other->balance());
        $originals = [$cash, $bank, $clearing, $otherBank];
        $boxes = [];
        $originalAttributes = [];
        $entryAttributes = [];
        foreach ($originals as $original) {
            $box = $original->boxEntry->box;
            $this->assertSame('open', $box->status);
            $this->closeBox($closer, $box);
            $boxes[$box->id] = $box->fresh()->getAttributes();
            $originalAttributes[$original->id] = $original->fresh()->getAttributes();
            $entryAttributes[$original->boxEntry->id] = $original->boxEntry->fresh()->getAttributes();
        }
        foreach ([$cash, $bank, $clearing] as $original) {
            foreach (TransactionAction::cases() as $action) {
                $this->actingAs($head)->post(route('subscriptions.transactions.actions.store', [$subscription, $original]), [
                    'action' => $action->value, 'amendment_reason' => 'محاولة بعد الإغلاق', 'correction_notes' => 'محاولة بعد الإغلاق',
                    ...($action === TransactionAction::Edit ? ['amount' => '1'] : []),
                    ...($action === TransactionAction::EditMetadata && $original->payment_method?->value === 'bank_transfer' ? ['bank_name' => 'بنك فلسطين'] : []),
                ])->assertSessionHasErrors('action');
            }
        }
        $this->actingAs($collector)->patch(route('subscriptions.transactions.amend', [$subscription, $bank]), ['notes' => 'تعديل بعد الإغلاق', 'amendment_reason' => 'اختبار'])->assertForbidden();
        $newCash = $this->pay($collector, $subscription, '25');
        $newBank = $this->pay($collector, $subscription, '25', 'bank_transfer', 'BANK-NEXT-BOX');
        $this->assertNotSame($cash->boxEntry->cash_box_id, $newCash->boxEntry->cash_box_id);
        $this->assertNotSame($bank->boxEntry->cash_box_id, $newBank->boxEntry->cash_box_id);
        $this->assertSame(300.0, $subscription->balance());
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $newBank->boxEntry), ['bank_amount' => '0', 'reason' => 'صندوق مفتوح'])->assertSessionHasErrors('action');
        $this->post(route('payments.bank-audits.store', $bank->boxEntry), ['bank_amount' => $verified, 'reason' => 'مطابقة الكشف الخارجي'])->assertSessionHasNoErrors();
        $this->assertSame(300.0, $subscription->balance());
        $this->assertSame(0, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT)->count());
        $preview = $this->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'place' => 'closed', 'week' => '2026-10-17']))->inertiaProps('periodAudit');
        $this->assertSame(4, $preview['boxes']);
        $this->assertSame(2, $preview['transfers']);
        $payload = [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true];

        $this->post(route('payments.period-audits.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('payments.period-audits.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $bank->boxEntry->box), ['confirmed' => true])->assertSessionHasNoErrors();

        $audit = $bank->boxEntry->audit()->sole();
        $this->assertSame($outcome, $audit->outcome);
        $this->assertNotNull($audit->posted_at);
        $this->assertSame((float) $finalBalance, $subscription->balance());
        $this->assertSame(100.0, $other->balance());
        $this->assertSame($outcome === 'matched' ? 0 : 1, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT)->count());
        $this->assertDatabaseCount('cash_box_audits', 4);
        $this->assertDatabaseCount('bank_transfer_audits', 2);
        $this->assertDatabaseHas('bank_transfer_audits', ['cash_box_payment_id' => $otherBank->boxEntry->id, 'outcome' => 'matched']);
        foreach ($originals as $original) {
            $this->assertSame($originalAttributes[$original->id], $original->fresh()->getAttributes());
            $this->assertSame($entryAttributes[$original->boxEntry->id], $original->boxEntry->fresh()->getAttributes());
        }
        foreach ($boxes as $id => $attributes) {
            $this->assertSame($attributes, CashBox::query()->findOrFail($id)->getAttributes());
        }
        foreach ([$newCash, $newBank] as $openPayment) {
            $this->assertSame('open', $openPayment->boxEntry->box->fresh()->status);
            $this->assertNull($openPayment->boxEntry->box->audit()->first());
        }
        $this->post(route('payments.bank-audits.store', $bank->boxEntry), ['bank_amount' => $verified === '0' ? '1' : '0', 'reason' => 'محاولة تغيير قرار نهائي'])->assertSessionHasErrors('action');
        $this->post(route('subscriptions.payments.store', $subscription), ['amount' => '1', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($head)->get(route('subscriptions.statement', $subscription))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.balance', $finalBalance)->where('summary.charged', '1100.00')
            ->where('summary.discounted', '50.00')->where('summary.cleared', '50.00')
            ->where('summary.paid', $netPaid)->where('summary.paymentsCount', 4)
            ->where('entries', function ($entries) use ($finalBalance, $outcome): bool {
                $this->assertSame($outcome === 'matched' ? 8 : 9, $entries->count());
                $expected = ['1000.00', '1100.00', '1050.00', '950.00', '400.00', '350.00', '325.00', '300.00'];
                if ($outcome !== 'matched') {
                    $expected[] = $finalBalance;
                }
                $this->assertSame($expected, $entries->pluck('balance')->all());
                $this->assertSame([], $entries[4]['available_actions']);
                if ($outcome !== 'matched') {
                    $this->assertSame('حركة تصحيح', $entries[8]['typeLabel']);
                    $this->assertSame([], $entries[8]['available_actions']);
                }

                return true;
            }));
        $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'place' => 'closed']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('periods.0.reviewed', 4)->where('periods.0.count', 4));
    }

    #[TestWith(['branchAdmin'])]
    #[TestWith(['permission'])]
    #[TestWith(['receiver'])]
    #[TestWith(['approver'])]
    #[TestWith(['superAdmin'])]
    public function test_each_supported_closing_authority_closes_collector_boxes_through_the_real_endpoint(string $authority): void
    {
        $this->travelTo('2026-10-18 10:00:00');
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '200.00']);
        $payment = $this->pay($collector, $subscription, '100');
        $actor = match ($authority) {
            'branchAdmin' => User::factory()->branchAdmin()->for($branch)->create(),
            'superAdmin' => User::factory()->superAdmin()->create(),
            'permission' => User::factory()->accountant()->for($branch)->withPermissions([PermissionKey::CloseCashBoxes])->create(),
            default => User::factory()->accountant()->for($branch)->create(),
        };
        if (in_array($authority, ['receiver', 'approver'], true)) {
            ClosingSetting::ownFor($branch)->update([$authority === 'receiver' ? 'receivers' : 'approvers' => [$actor->id]]);
        }

        $this->closeBox($actor, $payment->boxEntry->box);

        $this->assertSame(100.0, $subscription->balance());
        $this->assertDatabaseHas('cash_boxes', ['id' => $payment->boxEntry->cash_box_id, 'received_by' => $actor->id]);
        $this->assertSame([], $payment->fresh()->availableActions($actor));
    }

    #[TestWith(['collector'])]
    #[TestWith(['unprivileged_accountant'])]
    #[TestWith(['foreign_branch_admin'])]
    #[TestWith(['foreign_permission'])]
    #[TestWith(['auditor'])]
    public function test_users_outside_the_closing_scope_cannot_close_or_change_a_collectors_box(string $kind): void
    {
        $this->travelTo('2026-10-18 10:00:00');
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '200.00']);
        $payment = $this->pay($collector, $subscription, '100');
        $actor = match ($kind) {
            'collector' => $collector,
            'unprivileged_accountant' => User::factory()->accountant()->for($branch)->create(),
            'foreign_branch_admin' => User::factory()->branchAdmin()->create(),
            'foreign_permission' => User::factory()->accountant()->withPermissions([PermissionKey::CloseCashBoxes])->create(),
            default => User::factory()->financialAuditor()->create(),
        };
        $before = $payment->boxEntry->box->fresh()->getAttributes();

        $response = $this->actingAs($actor)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $payment->boxEntry->cash_box_id]), ['branch_id' => $branch->id]);
        if (str_starts_with($kind, 'foreign_') || $kind === 'auditor') {
            $response->assertNotFound();
        } else {
            $response->assertForbidden();
        }

        $this->assertSame($before, $payment->boxEntry->box->fresh()->getAttributes());
        $this->assertSame(100.0, $subscription->balance());
    }

    #[TestWith(['USD', '3.7000', '100', '50', '185.00', 815.0])]
    #[TestWith(['JOD', '5.0000', '100', '150', '-250.00', 250.0])]
    #[TestWith(['EUR', '4.2500', '100', '0', '425.00', 1000.0])]
    #[TestWith(['USD', '3.3333', '0.03', '0.02', '0.03', 999.93])]
    public function test_audited_foreign_transfers_keep_the_original_rate_and_cent_precision_after_rate_changes(string $currency, string $rate, string $recorded, string $verified, string $difference, float $balance): void
    {
        $this->travelTo('2026-10-18 10:00:00');
        CurrencyRate::query()->where('currency', $currency)->update(['rate' => $rate]);
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $auditor = User::factory()->financialAuditor()->create();
        $subscription = Subscription::factory()->for($branch)->create();
        SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '1000.00']);
        $payment = $this->pay($collector, $subscription, $recorded, 'bank_transfer', 'BANK-FX', $currency);
        $this->closeBox($head, $payment->boxEntry->box);
        $before = $payment->fresh()->getAttributes();
        CurrencyRate::query()->where('currency', $currency)->update(['rate' => '9.0000']);
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $payment->boxEntry), ['bank_amount' => $verified, 'reason' => 'تدقيق العملة الأصلية'])->assertSessionHasNoErrors();

        $this->post(route('payments.box-audits.store', $payment->boxEntry->box), ['confirmed' => true])->assertSessionHasNoErrors();

        $audit = BankTransferAudit::query()->sole();
        $this->assertSame($difference, $audit->difference);
        $this->assertSame($rate, $audit->exchange_rate);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame($balance, $subscription->balance());
        $this->assertSame($currency, $audit->adjustment->currency->value);
        $this->assertSame($rate, $audit->adjustment->exchange_rate);
    }
}
