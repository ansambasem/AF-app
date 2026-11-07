<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\DebtAging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        // 15:00 on Sunday 20 September in Gaza (UTC+3), the business's time zone.
        $this->travelTo('2026-09-20 12:00:00');
        $this->branch = Branch::factory()->create(['name' => 'فرع الكرادة']);
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id]);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad Nasser']);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('receivables.index'))->assertRedirect(route('login'));
    }

    public function test_the_report_takes_its_own_view_debt_aging_permission(): void
    {
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($dataEntry)->get(route('receivables.index'))->assertForbidden();

        $dataEntry->permissions()->attach(Permission::idsFor([PermissionKey::ViewCollections]));
        $this->actingAs($dataEntry->fresh())->get(route('receivables.index'))->assertForbidden();

        $dataEntry->permissions()->attach(Permission::idsFor([PermissionKey::ViewDebtAging]));
        $this->actingAs($dataEntry->fresh())->get(route('receivables.index'))->assertOk();
    }

    public function test_payments_settle_the_oldest_charges_first_so_the_debt_is_the_newest_charges(): void
    {
        $this->line($this->subscription, 'penalty', '100.00', '2026-05-23 12:00:00');
        $this->line($this->subscription, 'penalty', '50.00', '2026-08-06 12:00:00');
        $this->line($this->subscription, 'penalty', '30.00', '2026-09-10 12:00:00');
        $this->line($this->subscription, 'payment', '-120.00', '2026-09-15 12:00:00');

        $this->actingAs($this->branchAdmin)
            ->get(route('receivables.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Receivables/Index')
                ->has('debtors.data', 1)
                ->where('debtors.data.0.name', 'Ahmad Nasser')
                ->where('debtors.data.0.balance', 60)
                ->where('debtors.data.0.buckets', ['current' => 30, 'days_60' => 30, 'days_90' => 0, 'older' => 0])
                ->where('debtors.data.0.oldestDate', '2026-08-06')
                ->where('debtors.data.0.oldestDays', 45)
                ->where('debtors.data.0.lastPaymentDate', '2026-09-15')
                ->where('debtors.data.0.lastPaymentDays', 5)
                ->where('summary.total', 60)
                ->where('summary.count', 1)
                ->where('summary.buckets.current', ['amount' => 30, 'count' => 1, 'share' => 50])
                ->where('summary.buckets.days_60', ['amount' => 30, 'count' => 1, 'share' => 50]));
    }

    public function test_a_cancelled_charge_is_never_aged_and_a_partial_refund_brings_back_the_old_debt_it_paid(): void
    {
        $this->travelTo('2026-05-23 12:00:00');
        SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '100', null);
        $this->travelTo('2026-06-12 12:00:00');
        $payment = SubscriptionTransaction::recordPayment($this->subscription, $this->branchAdmin, ['amount' => '100', 'currency' => 'ILS', 'payment_method' => 'cash']);
        $this->travelTo('2026-07-12 12:00:00');
        $charge = SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '20', null);
        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $charge]), ['correction_reason' => 'duplicate', 'correction_notes' => 'مكررة'])
            ->assertSessionHasNoErrors();
        $this->travelTo('2026-09-15 12:00:00');
        // A partial refund, as one was recorded before refunds became whole-payment only.
        $this->subscription->transactions()->create([
            'recorded_by' => $this->branchAdmin->id,
            'reverses_id' => $payment->id,
            'reference_transaction_id' => $payment->id,
            'type' => SubscriptionTransaction::TYPE_REFUND,
            'source_key' => 'refund:'.$payment->id.':legacy',
            'amount' => '40.00',
            'currency_amount' => '40.00',
        ]);
        $this->travelTo('2026-09-20 12:00:00');

        $this->actingAs($this->branchAdmin)
            ->get(route('receivables.index'))
            ->assertInertia(fn ($page) => $page
                ->where('debtors.data.0.balance', 40)
                ->where('debtors.data.0.buckets', ['current' => 0, 'days_60' => 0, 'days_90' => 0, 'older' => 40])
                ->where('debtors.data.0.oldestDate', '2026-05-23')
                ->where('debtors.data.0.lastPaymentDate', '2026-06-12'));
    }

    public function test_settled_and_in_credit_subscriptions_are_not_listed(): void
    {
        $settled = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $this->line($settled, 'penalty', '50.00');
        $this->line($settled, 'payment', '-50.00');
        $inCredit = Subscription::factory()->create(['branch_id' => $this->branch->id]);
        $this->line($inCredit, 'payment', '-20.00');
        $this->line($this->subscription, 'penalty', '10.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('receivables.index'))
            ->assertInertia(fn ($page) => $page
                ->has('debtors.data', 1)
                ->where('debtors.data.0.id', $this->subscription->id)
                ->where('debtors.data.0.lastPaymentDate', null));
    }

    public function test_branch_staff_see_only_their_own_branchs_debtors_and_the_super_admin_can_pick_a_branch(): void
    {
        $otherBranch = Branch::factory()->create(['name' => 'فرع المنصور']);
        $otherDebtor = Subscription::factory()->create(['branch_id' => $otherBranch->id]);
        $this->line($this->subscription, 'penalty', '10.00');
        $this->line($otherDebtor, 'penalty', '75.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('receivables.index', ['filter' => ['branch_id' => $otherBranch->id]]))
            ->assertInertia(fn ($page) => $page
                ->where('scopeLabel', 'فرع الكرادة')
                ->has('debtors.data', 1)
                ->where('debtors.data.0.id', $this->subscription->id));

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('receivables.index'))
            ->assertInertia(fn ($page) => $page->where('scopeLabel', 'كل الفروع')->where('summary.total', 85));

        $this->actingAs($superAdmin)
            ->get(route('receivables.index', ['filter' => ['branch_id' => $otherBranch->id]]))
            ->assertInertia(fn ($page) => $page
                ->where('scopeLabel', 'فرع المنصور')
                ->has('debtors.data', 1)
                ->where('debtors.data.0.id', $otherDebtor->id));
    }

    public function test_the_age_filter_keeps_only_debtors_owing_something_older_and_the_table_sorts_by_a_bucket(): void
    {
        $recentDebtor = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Recent']);
        $this->line($recentDebtor, 'penalty', '500.00', '2026-09-18 12:00:00');
        $smallOldDebtor = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Small old']);
        $this->line($smallOldDebtor, 'penalty', '20.00', '2026-05-01 12:00:00');
        $this->line($this->subscription, 'penalty', '90.00', '2026-04-01 12:00:00');

        $this->actingAs($this->branchAdmin)
            ->get(route('receivables.index', ['filter' => ['age' => '90'], 'sort' => 'older', 'direction' => 'desc']))
            ->assertInertia(fn ($page) => $page
                ->has('debtors.data', 2)
                ->where('debtors.data.0.id', $this->subscription->id)
                ->where('debtors.data.1.id', $smallOldDebtor->id)
                ->where('summary.total', 110));

        $this->actingAs($this->branchAdmin)
            ->get(route('receivables.index'))
            ->assertInertia(fn ($page) => $page
                ->where('debtors.data.0.id', $recentDebtor->id)
                ->where('summary.total', 610));
    }

    public function test_malformed_query_values_fall_back_to_the_defaults(): void
    {
        $this->line($this->subscription, 'penalty', '10.00');

        $this->actingAs($this->branchAdmin)
            ->get('/receivables?filter[age][]=90&filter[status][]=active&filter[branch_id][]=1&sort=balance;drop&statement[]=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('debtors.data', 1)->where('statement', null));
    }

    #[TestWith([0, 'current'])]
    #[TestWith([30, 'current'])]
    #[TestWith([31, 'days_60'])]
    #[TestWith([60, 'days_60'])]
    #[TestWith([61, 'days_90'])]
    #[TestWith([90, 'days_90'])]
    #[TestWith([91, 'older'])]
    public function test_a_debt_falls_in_the_bucket_of_its_age_in_days(int $days, string $bucket): void
    {
        $this->assertSame($bucket, DebtAging::bucketFor($days));
    }

    private function line(Subscription $subscription, string $type, string $amount, ?string $at = null): SubscriptionTransaction
    {
        return SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => $type,
            'source_key' => $type.':'.Str::ulid(),
            'amount' => $amount,
            'currency_amount' => ltrim($amount, '-'),
            'recorded_by' => $this->branchAdmin->id,
            'created_at' => $at ?? now(),
        ]);
    }
}
