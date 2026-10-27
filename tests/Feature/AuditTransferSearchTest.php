<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\ClosingSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Support\BankTransferAuditing;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuditTransferSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['bank_asc'])]
    #[TestWith(['bank_desc'])]
    public function test_bank_arrival_sorting_is_independent_of_movement_dates_and_places_unknown_dates_last(string $sort): void
    {
        $older = $this->entry(attributes: ['bank_received_on' => '2026-10-10']);
        $newer = $this->entry($older->box, ['bank_received_on' => '2026-10-16']);
        $sameDay = $this->entry($older->box, ['bank_received_on' => '2026-10-10']);
        $unknown = $this->entry($older->box);
        $older->update(['occurred_at' => '2026-10-18 12:00:00']);
        $sameDay->update(['occurred_at' => '2026-10-18 12:00:00']);
        $newer->update(['occurred_at' => '2026-10-18 08:00:00']);
        $auditor = User::factory()->financialAuditor()->create();
        $expected = $sort === 'bank_asc' ? [$older->id, $sameDay->id, $newer->id] : [$newer->id, $sameDay->id, $older->id];

        $this->actingAs($auditor)->get($this->url($older, ['transfer_sort' => $sort]))->assertInertia(fn ($page) => $page
            ->where('transferSearch.sort', $sort)->where('transferSearch.results.total', 4)
            ->where('transferSearch.results.data.0.id', $expected[0])
            ->where('transferSearch.results.data.1.id', $expected[1])
            ->where('transferSearch.results.data.2.id', $expected[2])
            ->where('transferSearch.results.data.3.id', $unknown->id)
            ->where('transferSearch.summary.transfers', 4));
    }

    public function test_movement_date_sorting_can_show_oldest_first(): void
    {
        $older = $this->entry();
        $newer = $this->entry($older->box);
        $older->update(['occurred_at' => '2026-10-18 08:00:00']);
        $newer->update(['occurred_at' => '2026-10-18 12:00:00']);
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($auditor)->get($this->url($older, ['transfer_sort' => 'date_asc']))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.data.0.id', $older->id)
            ->where('transferSearch.results.data.1.id', $newer->id));
    }

    public function test_bank_arrival_filter_uses_only_the_optional_bank_date_and_leaves_recording_date_separate(): void
    {
        $entry = $this->entry(attributes: ['bank_received_on' => '2026-10-16']);
        $this->entry($entry->box, ['reference_number' => 'UNKNOWN-DATE', 'active_reference' => 'UNKNOWNDATE']);
        $auditor = User::factory()->financialAuditor()->create();
        $dates = ['transfer_from' => '2026-10-16', 'transfer_to' => '2026-10-16'];
        $this->actingAs($auditor)->get($this->url($entry, $dates))->assertInertia(fn ($page) => $page->where('transferSearch.results.total', 0));
        $this->get($this->url($entry, [...$dates, 'transfer_date_basis' => 'bank']))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', 1)
            ->where('transferSearch.results.data.0.id', $entry->id)
            ->where('transferSearch.results.data.0.ledger.bankReceivedOn', '2026-10-16')
            ->where('transferSearch.dateBasis', 'bank'));
    }

    public function test_auditor_can_filter_by_payment_date_without_confusing_it_with_recording_date(): void
    {
        $entry = $this->entry(attributes: ['paid_on' => '2026-10-10', 'late_reason' => 'تأخر إدخال الوصل']);
        $this->entry($entry->box, ['reference_number' => 'OTHER', 'active_reference' => 'OTHER']);
        $auditor = User::factory()->financialAuditor()->create();
        $dates = ['transfer_from' => '2026-10-10', 'transfer_to' => '2026-10-10'];
        $this->actingAs($auditor)->get($this->url($entry, $dates))->assertInertia(fn ($page) => $page->where('transferSearch.results.total', 0));
        $this->get($this->url($entry, [...$dates, 'transfer_date_basis' => 'paid']))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', 1)
            ->where('transferSearch.results.data.0.id', $entry->id)
            ->where('transferSearch.results.data.0.ledger.paidOn', '2026-10-10')
            ->where('transferSearch.results.data.0.ledger.lateReason', 'تأخر إدخال الوصل'));
    }

    private function entry(?CashBox $box = null, array $attributes = []): CashBoxPayment
    {
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(10, 0));
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        $box ??= CashBox::factory()->bankTransfer()->create(['status' => 'received', 'closed_at' => now(), 'received_at' => now()]);
        $subscriptionFactory = Subscription::factory();
        if ($existing = Subscription::query()->first()) {
            $subscriptionFactory = $subscriptionFactory->state(['circuit_breaker_id' => $existing->circuit_breaker_id]);
        }
        $subscription = $subscriptionFactory->create(['branch_id' => $box->branch_id]);
        $historical = array_intersect_key($attributes, array_flip(['status', 'cancelled_at']));
        $payment = SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => 'payment', 'amount' => '-100', 'currency_amount' => '100', 'currency' => 'ILS', 'exchange_rate' => '1',
            'payment_method' => 'bank_transfer', 'reference_number' => 'BANK-125', 'active_reference' => 'BANK125', ...array_diff_key($attributes, $historical),
        ]);

        $entry = $payment->boxEntry()->firstOrFail();
        $entry->update(['cash_box_id' => $box->id, 'method' => 'bank_transfer', 'status' => 'confirmed']);
        if ($historical !== []) {
            DB::table('subscription_transactions')->where('id', $payment->id)->update($historical);
        }

        return $entry->fresh();
    }

    private function url(CashBoxPayment $entry, array $query = []): string
    {
        return route('payments.cash-boxes', ['branch_id' => $entry->box->branch_id, 'place' => 'closed', 'week' => '2026-10-17', 'view' => 'transfers', ...$query]);
    }

    public function test_exact_normalized_reference_search_has_separate_results_for_duplicates_and_no_box_payload(): void
    {
        $first = $this->entry();
        $second = $this->entry($first->box);
        $this->entry($first->box, ['reference_number' => 'BANK-1259', 'active_reference' => 'BANK1259']);
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($auditor)->get($this->url($first, ['transfer_search' => ' bank / 125 ']))->assertInertia(fn ($page) => $page
            ->where('searchingTransfers', true)->has('boxes', 0)->missing('periodAudit')
            ->where('transferSearch.results.total', 2)->where('transferSearch.results.data.0.id', $second->id)
            ->where('transferSearch.results.data.1.id', $first->id)->where('transferSearch.results.data.0.canReview', true)
            ->where('transferSearch.results.data.0.box.number', $first->box->number)
            ->where('transferSearch.summary.transfers', 3)->where('transferSearch.summary.automaticMatches', 3));
    }

    #[TestWith(['manual_voucher_number', 'PAPER-125', 'PAPER-125'])]
    #[TestWith(['voucher_number', 125, '000125'])]
    #[TestWith(['voucher_number', 125, '٠٠٠١٢٥'])]
    #[TestWith(['reference_number', 'BANK-١٢٥', 'BANK-١٢٥'])]
    public function test_finds_a_transfer_by_its_paper_or_formatted_system_voucher(string $field, mixed $value, string $search): void
    {
        $entry = $this->entry(attributes: [$field => $value]);
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($auditor)->get($this->url($entry, ['transfer_search' => $search]))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', 1)->where('transferSearch.results.data.0.id', $entry->id));
    }

    public function test_scopes_search_to_the_selected_branch_week_and_live_closed_bank_payments(): void
    {
        $visible = $this->entry();
        $this->entry();
        $this->entry(CashBox::factory()->bankTransfer()->create(['branch_id' => $visible->box->branch_id, 'status' => 'open']));
        $this->entry(CashBox::factory()->bankTransfer()->create(['branch_id' => $visible->box->branch_id, 'status' => 'received', 'received_at' => now()->subWeek()]));
        $this->entry(CashBox::factory()->create(['branch_id' => $visible->box->branch_id, 'status' => 'received', 'received_at' => now()]));
        $this->entry($visible->box, ['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->entry($visible->box, ['status' => 'linked_cancellation']);
        $auditor = User::factory()->financialAuditor()->create();
        $auditor->audit_settings = ['scope' => 'selected', 'branch_ids' => [$visible->box->branch_id], 'actions' => ['review_transfers']];
        $auditor->save();

        $this->actingAs($auditor)->get($this->url($visible, ['transfer_search' => 'BANK-125']))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', 1)->where('transferSearch.results.data.0.id', $visible->id)->where('canApprovePeriod', false));
        $otherBranch = Branch::factory()->create();
        $this->get($this->url($visible, ['branch_id' => $otherBranch->id, 'transfer_search' => 'BANK-125']))->assertNotFound();
    }

    public function test_initial_search_loads_no_transactions_and_paginated_state_filter_keeps_the_full_period_summary(): void
    {
        $entry = $this->entry();
        for ($index = 0; $index < 21; $index++) {
            $this->entry($entry->box);
        }
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($auditor)->get($this->url($entry))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', 0)->where('transferSearch.summary.transfers', 22));
        $this->get($this->url($entry, ['transfer_state' => 'unreviewed']))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', 22)->has('transferSearch.results.data', 20)
            ->where('transferSearch.results.next_page_url', fn ($url): bool => str_contains($url, 'view=transfers') && str_contains($url, 'transfer_state=unreviewed')));
        $this->get($this->url($entry, ['transfer_state' => 'unreviewed', 'transfer_page' => 2]))->assertInertia(fn ($page) => $page
            ->has('transferSearch.results.data', 2)->where('transferSearch.summary.transfers', 22));
        $this->get($this->url($entry, ['transfer_sort' => 'bank_asc']))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', 22)
            ->where('transferSearch.results.next_page_url', fn ($url): bool => str_contains($url, 'transfer_sort=bank_asc')));
    }

    #[TestWith([null])]
    #[TestWith(['release-test-version'])]
    public function test_search_draft_survives_full_period_approval_and_final_results_are_locked(?string $assetVersion): void
    {
        $this->partialMock(HandleInertiaRequests::class, function (MockInterface $middleware) use ($assetVersion): void {
            $middleware->shouldReceive('version')->andReturn($assetVersion);
        });
        $entry = $this->entry();
        $matched = $this->entry($entry->box, ['reference_number' => 'BANK-126', 'active_reference' => 'BANK126']);
        $auditor = User::factory()->financialAuditor()->create();
        $url = $this->url($entry, ['transfer_search' => 'BANK-125']);

        $this->actingAs($auditor)->from($url)->post(route('payments.bank-audits.store', $entry), ['bank_amount' => '0', 'reason' => 'غير موجودة في البنك'])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_transactions', 2);
        $this->get($url)->assertInertia(fn ($page) => $page->where('transferSearch.results.data.0.audit.bank_amount', '0.00')
            ->where('transferSearch.summary.exceptions', 1)->where('transferSearch.summary.debit', '100.00')->where('transferSearch.summary.automaticMatches', 1));
        $version = app(HandleInertiaRequests::class)->version(request());
        $preview = $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Inertia-Partial-Component' => 'Payments/BranchCashBoxes', 'X-Inertia-Partial-Data' => 'periodAudit'])->assertOk()->json('props.periodAudit');
        $this->assertSame(2, $preview['transfers']);
        $this->assertSame(1, $preview['exceptions']);
        $this->post(route('payments.period-audits.store'), [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bank_transfer_audits', ['cash_box_payment_id' => $matched->id, 'outcome' => 'matched']);
        $this->assertDatabaseHas('subscription_transactions', ['reference_transaction_id' => $entry->transaction->id, 'type' => 'bank_audit_adjustment', 'amount' => '100.00']);
        $this->get($url)->assertInertia(fn ($page) => $page->where('transferSearch.results.data.0.finalized', true)
            ->where('transferSearch.results.data.0.canReview', false)->where('transferSearch.summary.transfers', 0));
        $this->post(route('payments.bank-audits.store', $entry), ['bank_amount' => '50', 'reason' => 'تغيير'])->assertSessionHasErrors('action');
        $this->assertSame('0.00', $entry->audit()->sole()->bank_amount);
    }

    #[TestWith(['differences', 3])]
    #[TestWith(['missing', 1])]
    #[TestWith(['short', 1])]
    #[TestWith(['over', 1])]
    #[TestWith(['matched', 1])]
    #[TestWith(['draft', 4])]
    public function test_audit_filters_show_saved_decisions_without_changing_full_period_totals(string $state, int $count): void
    {
        $entry = $this->entry();
        $auditor = User::factory()->financialAuditor()->create();
        foreach (['0', '50', '150', '100'] as $amount) {
            $current = $this->entry($entry->box);
            app(BankTransferAuditing::class)->record($current, $auditor, $amount, 'مراجعة');
        }

        $this->actingAs($auditor)->get($this->url($entry, ['transfer_state' => $state]))->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.total', $count)->where('transferSearch.summary.exceptions', 3)
            ->where('transferSearch.summary.debit', '150.00')->where('transferSearch.summary.credit', '50.00'));
    }

    public function test_search_requires_audit_access_and_honors_read_only_and_revoked_permissions(): void
    {
        $entry = $this->entry();
        $auditor = User::factory()->financialAuditor()->create();
        $this->get($this->url($entry))->assertRedirect(route('login'));
        $staff = User::factory()->branchAdmin()->create(['branch_id' => $entry->box->branch_id]);
        $this->actingAs($staff)->get($this->url($entry))->assertForbidden();
        $auditor->audit_settings = ['scope' => 'all', 'branch_ids' => [], 'actions' => []];
        $auditor->save();
        $result = $this->actingAs($auditor)->get($this->url($entry, ['transfer_search' => 'BANK-125']))->assertOk();
        $result->assertInertia(fn ($page) => $page
            ->where('transferSearch.results.data.0.canReview', false)->where('canApprovePeriod', false));
        $this->post(route('payments.bank-audits.store', $entry), ['bank_amount' => '0', 'reason' => 'اختبار'])->assertForbidden();
        $auditor->permissions()->detach();
        $this->actingAs($auditor->fresh())->get($this->url($entry))->assertForbidden();
        $this->assertDatabaseCount('bank_transfer_audits', 0);
    }

    #[TestWith(['transfer_search', ['bad']])]
    #[TestWith(['transfer_search', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])]
    #[TestWith(['transfer_state', 'unknown'])]
    #[TestWith(['transfer_sort', 'unknown'])]
    #[TestWith(['transfer_sort', ['bank_asc']])]
    public function test_invalid_search_input_is_rejected(string $key, mixed $value): void
    {
        $entry = $this->entry();
        $auditor = User::factory()->financialAuditor()->create();

        $this->actingAs($auditor)->get($this->url($entry, [$key => $value]))->assertSessionHasErrors($key);
    }
}
