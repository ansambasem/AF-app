<?php

namespace Tests\Feature;

use App\Models\BankTransferAudit;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ClosingSetting;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BranchDomainScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SplitTransferAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, User, SplitPayment, CashBox} */
    private function transfer(bool $separateBoxes = false, bool $closeSibling = true, bool $closeBox = true): array
    {
        $this->travelTo('2026-10-22 08:00:00');
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $branch = Branch::factory()->create();
        $head = User::factory()->branchAdmin()->for($branch)->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $auditor = User::factory()->financialAuditor()->create();
        $subscriptions = Subscription::factory()->for($branch)->count(2)->create();
        foreach ($subscriptions as $subscription) {
            SubscriptionTransaction::factory()->for($subscription)->create(['amount' => '100.00']);
        }
        $this->actingAs($collector)->post(route('payments.split.store'), [
            'total_amount' => '100', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'مرسل الفحص',
            'reference_number' => 'AUDIT-GROUP', 'parts' => [
                ['subscription_id' => $subscriptions[0]->id, 'amount' => '40'],
                ['subscription_id' => $subscriptions[1]->id, 'amount' => '60'],
            ],
        ])->assertSessionHasNoErrors();
        $split = SplitPayment::sole();
        $box = $split->payments[0]->boxEntry->box;
        if ($separateBoxes) {
            $sibling = CashBox::factory()->bankTransfer()->create(['branch_id' => $branch->id, 'closing_id' => $box->closing_id, 'user_id' => $head->id]);
            $split->payments[1]->boxEntry->update(['cash_box_id' => $sibling->id]);
            if ($closeSibling) {
                $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $sibling->id]), ['branch_id' => $branch->id])->assertSessionHasNoErrors();
            }
        }
        if ($closeBox) {
            $this->actingAs($head)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $branch->id])->assertSessionHasNoErrors();
        }

        return [$head, $auditor, $split->fresh('payments.boxEntry'), $box];
    }

    /** @return array<string, mixed> */
    private function review(User $auditor, SplitPayment $split, string $first = '0', string $second = '0'): array
    {
        $details = $this->actingAs($auditor)->getJson(route('payments.bank-audits.show', $split->payments[0]->boxEntry))->assertOk()->json('split');

        return ['bank_amount' => (float) $first + (float) $second, 'reason' => 'مطابقة كشف الحوالة كاملة', 'revision' => $details['revision'],
            'parts' => [
                ['transaction_id' => $split->payments[0]->id, 'bank_amount' => $first],
                ['transaction_id' => $split->payments[1]->id, 'bank_amount' => $second],
            ]];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_share_corrected_by_the_branch_head_stays_with_the_transfer_and_closes_and_audits_together(bool $approvePeriod): void
    {
        [$head, $auditor, $split, $box] = $this->transfer(closeBox: false);
        $original = $split->payments[0];

        $this->actingAs($head)->put(route('subscriptions.transactions.update', [$original->subscription, $original]), [
            'amount' => '30', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_name' => $split->bank_name, 'sender_name' => $split->sender_name, 'reference_number' => $split->reference_number,
            'correction_reason' => 'wrong_amount', 'correction_notes' => 'تصحيح حصة المشترك', 'confirm_overpayment' => true,
        ])->assertSessionHasNoErrors();

        $replacement = SubscriptionTransaction::query()->where('corrects_id', $original->id)->sole();
        $this->assertSame($head->id, $replacement->recorded_by);
        $this->assertSame($split->id, $replacement->split_payment_id);
        $this->assertSame($box->id, $replacement->boxEntry->cash_box_id);
        $this->assertSame('90.00', $box->fresh()->totals()['ILS']);

        $this->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $box->branch_id])->assertSessionHasNoErrors();
        $this->actingAs($auditor);
        if ($approvePeriod) {
            $preview = $this->get(route('payments.cash-boxes', ['branch_id' => $box->branch_id, 'place' => 'closed', 'week' => '2026-10-17']))->inertiaProps('periodAudit');
            $this->post(route('payments.period-audits.store'), [
                ...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true,
            ])->assertSessionHasNoErrors();
        } else {
            $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('cash_box_audits', 1);
        $this->assertSame(2, BankTransferAudit::query()->whereNotNull('posted_at')->count());
        $this->assertSame(70.0, $original->subscription->balance());
        $this->assertSame(40.0, $split->payments[1]->subscription->balance());
    }

    #[TestWith(['0', '0', 100.0, 100.0])]
    #[TestWith(['20', '30', 80.0, 70.0])]
    #[TestWith(['40', '60', 60.0, 40.0])]
    #[TestWith(['50', '75', 50.0, 25.0])]
    #[TestWith(['20', '80', 80.0, 20.0])]
    public function test_review_from_either_share_updates_all_drafts_and_approval_posts_each_effect_once(string $first, string $second, float $firstBalance, float $secondBalance): void
    {
        [, $auditor, $split, $box] = $this->transfer();
        $payload = $this->review($auditor, $split, $first, $second);
        $before = $split->payments->map(fn (SubscriptionTransaction $part): array => $part->getAttributes())->all();

        $this->post(route('payments.bank-audits.store', $split->payments[1]->boxEntry), $payload)->assertSessionHasNoErrors();
        $this->post(route('payments.bank-audits.store', $split->payments[0]->boxEntry), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('bank_transfer_audits', 2);
        $this->assertSame(60.0, $split->payments[0]->subscription->balance());
        $this->assertSame(40.0, $split->payments[1]->subscription->balance());
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->post(route('payments.bank-audits.store', $split->payments[1]->boxEntry), $payload)->assertSessionHasNoErrors();

        $this->assertSame($firstBalance, $split->payments[0]->subscription->balance());
        $this->assertSame($secondBalance, $split->payments[1]->subscription->balance());
        $this->assertSame(2, BankTransferAudit::query()->whereNotNull('posted_at')->count());
        $this->assertSame($before, $split->fresh('payments')->payments->map(fn (SubscriptionTransaction $part): array => $part->getAttributes())->all());
        $this->assertDatabaseCount('cash_box_audits', 1);
    }

    #[TestWith(['omitted'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['outsider'])]
    #[TestWith(['sum'])]
    #[TestWith(['negative'])]
    #[TestWith(['reason'])]
    public function test_invalid_group_allocations_save_no_partial_draft(string $invalid): void
    {
        [, $auditor, $split] = $this->transfer();
        $payload = $this->review($auditor, $split);
        if ($invalid === 'omitted') {
            array_pop($payload['parts']);
        } elseif ($invalid === 'duplicate') {
            $payload['parts'][1]['transaction_id'] = $payload['parts'][0]['transaction_id'];
        } elseif ($invalid === 'outsider') {
            $payload['parts'][1]['transaction_id'] = 999999;
        } elseif ($invalid === 'sum') {
            $payload['bank_amount'] = 1;
        } elseif ($invalid === 'negative') {
            $payload['parts'][1]['bank_amount'] = -1;
        } else {
            $payload['reason'] = '';
        }

        $this->post(route('payments.bank-audits.store', $split->payments[0]->boxEntry), $payload)->assertSessionHasErrors();

        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertDatabaseCount('subscription_transactions', 4);
    }

    public function test_old_single_share_requests_are_rejected_and_legacy_partial_review_blocks_period_approval(): void
    {
        [, $auditor, $split, $box] = $this->transfer();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $split->payments[0]->boxEntry), ['bank_amount' => '0', 'reason' => 'لم تصل'])->assertSessionHasErrors('parts');
        BankTransferAudit::factory()->create(['cash_box_payment_id' => $split->payments[0]->boxEntry->id, 'audited_by' => $auditor->id,
            'currency' => 'ILS', 'recorded_amount' => '40', 'bank_amount' => '0', 'difference' => '40', 'outcome' => 'missing', 'posted_at' => null, 'adjustment_transaction_id' => null]);
        $preview = $this->get(route('payments.cash-boxes', ['branch_id' => $box->branch_id, 'place' => 'closed', 'week' => '2026-10-17']))->inertiaProps('periodAudit');
        $approval = [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true];

        $this->post(route('payments.period-audits.store'), $approval)->assertSessionHasErrors('action');

        $this->assertDatabaseCount('cash_box_audits', 0);
        $this->assertSame(60.0, $split->payments[0]->subscription->balance());
        $this->assertSame(40.0, $split->payments[1]->subscription->balance());
        $this->assertSame(0, BankTransferAudit::query()->whereNotNull('posted_at')->count());
    }

    public function test_a_stale_review_cannot_overwrite_another_auditors_group_review(): void
    {
        [, $auditor, $split] = $this->transfer();
        $stale = $this->review($auditor, $split, '40', '60');
        $latest = $this->review($auditor, $split, '20', '30');
        $this->post(route('payments.bank-audits.store', $split->payments[0]->boxEntry), $latest)->assertSessionHasNoErrors();

        $this->post(route('payments.bank-audits.store', $split->payments[1]->boxEntry), $stale)->assertSessionHasErrors('revision');

        $this->assertSame(['20.00', '30.00'], BankTransferAudit::query()->orderBy('cash_box_payment_id')->pluck('bank_amount')->all());
    }

    public function test_approval_posts_all_shares_across_closed_boxes_and_refuses_later_group_changes(): void
    {
        [, $auditor, $split, $box] = $this->transfer(separateBoxes: true);
        $payload = $this->review($auditor, $split);
        $this->post(route('payments.bank-audits.store', $split->payments[0]->boxEntry), $payload)->assertSessionHasNoErrors();
        $changed = $this->review($auditor, $split, '40', '60');

        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->post(route('payments.bank-audits.store', $split->payments[1]->boxEntry), $changed)->assertSessionHasErrors('action');
        $this->post(route('payments.box-audits.store', $split->payments[1]->boxEntry->box), ['confirmed' => true])->assertSessionHasNoErrors();

        $this->assertSame(100.0, $split->payments[0]->subscription->balance());
        $this->assertSame(100.0, $split->payments[1]->subscription->balance());
        $this->assertSame(2, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT)->count());
        $this->assertDatabaseCount('cash_box_audits', 2);
    }

    public function test_an_open_sibling_blocks_review_and_approval_without_posting_any_effect(): void
    {
        [, $auditor, $split, $box] = $this->transfer(separateBoxes: true, closeSibling: false);

        $this->actingAs($auditor)->getJson(route('payments.bank-audits.show', $split->payments[0]->boxEntry))->assertUnprocessable();
        $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertSessionHasErrors('action');

        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertDatabaseCount('cash_box_audits', 0);
    }

    public function test_unauthorized_and_foreign_branch_users_cannot_load_the_distribution(): void
    {
        [$head, , $split] = $this->transfer();
        $url = route('payments.bank-audits.show', $split->payments[0]->boxEntry);
        $collector = User::factory()->collector()->create(['branch_id' => $head->branch_id]);
        $this->actingAs($collector)->getJson($url)->assertForbidden();
        $foreign = User::factory()->branchAdmin()->create();
        $this->actingAs($foreign)->getJson($url)->assertForbidden();
    }

    public function test_a_failure_on_the_second_share_rolls_back_the_entire_group_posting(): void
    {
        [, $auditor, $split, $box] = $this->transfer();
        $payload = $this->review($auditor, $split);
        $this->post(route('payments.bank-audits.store', $split->payments[0]->boxEntry), $payload)->assertSessionHasNoErrors();
        $second = $split->payments[1]->id;
        SubscriptionTransaction::creating(function (SubscriptionTransaction $line) use ($second): void {
            if ($line->type === SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT && $line->reference_transaction_id === $second) {
                throw new RuntimeException('Second share posting failed');
            }
        });

        try {
            $this->post(route('payments.box-audits.store', $box), ['confirmed' => true])->assertServerError();
        } finally {
            SubscriptionTransaction::flushEventListeners();
            SubscriptionTransaction::clearBootedModels();
        }

        $this->assertDatabaseCount('cash_box_audits', 0);
        $this->assertSame(0, BankTransferAudit::query()->whereNotNull('posted_at')->count());
        $this->assertDatabaseCount('subscription_transactions', 4);
        $this->assertSame(60.0, $split->payments[0]->subscription->balance());
        $this->assertSame(40.0, $split->payments[1]->subscription->balance());
    }

    public function test_a_branch_domain_cannot_hide_a_foreign_share_from_an_auditor(): void
    {
        [$head, $auditor, $split, $box] = $this->transfer();
        $foreign = Subscription::factory()->create();
        SubscriptionTransaction::recordPayment($foreign, User::factory()->collector()->create(['branch_id' => $foreign->branch_id]), [
            'amount' => '1', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين',
            'reference_number' => 'AUDIT-GROUP', 'split_payment_id' => $split->id,
        ]);
        config(['app.url' => 'https://admin.company.test', 'powercollect.branch_domains_enabled' => true]);
        Branch::findOrFail($head->branch_id)->forceFill(['domain' => 'north.company.test', 'domain_enabled' => true])->save();
        $url = 'https://north.company.test/payments/cash-boxes/entries/'.$split->payments[0]->boxEntry->id.'/audit';
        $this->actingAs($auditor)->getJson($url)->assertNotFound();
        $this->post('https://north.company.test'.route('payments.box-audits.store', $box, false), ['confirmed' => true])->assertNotFound();

        $this->assertSame(0, BankTransferAudit::withoutGlobalScope(BranchDomainScope::class)->count());
        $this->assertDatabaseCount('cash_box_audits', 0);
    }

    public function test_a_failure_saving_one_share_rolls_back_all_review_drafts(): void
    {
        [, $auditor, $split] = $this->transfer();
        $payload = $this->review($auditor, $split);
        $secondEntry = $split->payments[1]->boxEntry->id;
        BankTransferAudit::creating(function (BankTransferAudit $review) use ($secondEntry): void {
            if ($review->cash_box_payment_id === $secondEntry) {
                throw new RuntimeException('Review unavailable');
            }
        });

        try {
            $this->post(route('payments.bank-audits.store', $split->payments[0]->boxEntry), $payload)->assertServerError();
        } finally {
            BankTransferAudit::flushEventListeners();
            BankTransferAudit::clearBootedModels();
        }

        $this->assertDatabaseCount('bank_transfer_audits', 0);
        $this->assertDatabaseCount('subscription_transactions', 4);
    }
}
