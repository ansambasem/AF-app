<?php

namespace Tests\Feature\Ledger;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LedgerTest extends TestCase
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
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id, 'name' => 'Mohammed']);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad Nasser', 'phone' => '0591234567']);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('ledger.index'))->assertRedirect(route('login'));
    }

    public function test_the_log_takes_the_view_collections_permission(): void
    {
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($dataEntry)->get(route('ledger.index'))->assertForbidden();

        $dataEntry->permissions()->attach(Permission::idsFor([PermissionKey::ViewCollections]));
        $this->actingAs($dataEntry->fresh())->get(route('ledger.index'))->assertOk();
    }

    public function test_branch_staff_see_only_their_own_branchs_lines(): void
    {
        $this->line($this->subscription, 'subscription_fee', '50.00');
        $this->line(Subscription::factory()->create(['full_name' => 'Other Branch Subscription']), 'subscription_fee', '75.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Ledger/Index')
                ->where('scopeLabel', 'فرع الكرادة')
                ->where('entries.data', fn ($entries): bool => collect($entries)->pluck('subscriptionName')->all() === ['Ahmad Nasser'])
                ->where('summary.total', 50)
                ->where('filterOptions', fn ($groups): bool => ! collect($groups)->contains('key', 'branch_id')));
    }

    public function test_the_super_admin_sees_every_branch_and_can_narrow_to_one(): void
    {
        $otherBranch = Branch::factory()->create(['name' => 'فرع المنصور']);
        $this->line($this->subscription, 'subscription_fee', '50.00');
        $this->line(Subscription::factory()->create(['branch_id' => $otherBranch->id]), 'subscription_fee', '75.00');
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('ledger.index'))
            ->assertInertia(fn ($page) => $page
                ->where('scopeLabel', 'كل الفروع')
                ->where('summary.total', 125)
                ->where('branchTotals', [
                    ['id' => $otherBranch->id, 'name' => 'فرع المنصور', 'total' => 75, 'count' => 1],
                    ['id' => $this->branch->id, 'name' => 'فرع الكرادة', 'total' => 50, 'count' => 1],
                ]));

        $this->actingAs($superAdmin)
            ->get(route('ledger.index', ['filter' => ['branch_id' => $otherBranch->id]]))
            ->assertInertia(fn ($page) => $page->where('scopeLabel', 'فرع المنصور')->where('summary.total', 75));
    }

    public function test_the_headline_sums_the_charges_and_reports_what_was_collected_beside_them(): void
    {
        $this->line($this->subscription, 'meter_reading', '120.00');
        $this->line($this->subscription, 'subscription_fee', '30.00');
        $this->line($this->subscription, 'payment', '-100.00');
        $this->line($this->subscription, 'discount', '-10.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index'))
            ->assertInertia(fn ($page) => $page
                ->where('side', 'debit')
                ->where('entries.total', 4)
                ->where('summary.total', 150)
                ->where('summary.count', 2)
                ->where('summary.average', 75)
                ->where('summary.largest', 120)
                ->where('summary.collected', 100));
    }

    /**
     * @param  array<int, float>  $expected  total and count of the headline
     */
    #[TestWith(['payment', 'credit', [100, 1]])]
    #[TestWith(['credit', 'credit', [116, 3]])]
    #[TestWith(['debit', 'debit', [150, 2]])]
    #[TestWith(['meter_reading', 'debit', [120, 1]])]
    #[TestWith(['reading_discount', 'credit', [6, 1]])]
    public function test_the_type_filter_picks_the_lines_and_the_side_the_headline_sums(string $type, string $side, array $expected): void
    {
        $this->line($this->subscription, 'meter_reading', '120.00');
        $this->line($this->subscription, 'subscription_fee', '30.00');
        $this->line($this->subscription, 'payment', '-100.00');
        $this->line($this->subscription, 'discount', '-10.00');
        $this->line($this->subscription, 'reading_discount', '-6.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['filter' => ['type' => $type]]))
            ->assertInertia(fn ($page) => $page
                ->where('side', $side)
                ->where('summary.total', $expected[0])
                ->where('summary.count', $expected[1]));
    }

    public function test_the_period_counts_whole_business_days_and_compares_with_the_days_before(): void
    {
        $this->line($this->subscription, 'subscription_fee', '40.00', '2026-09-20 08:00:00');
        // 00:30 on 20 September in Gaza: today there, still yesterday in UTC.
        $this->line($this->subscription, 'subscription_fee', '10.00', '2026-09-19 21:30:00');
        $this->line($this->subscription, 'subscription_fee', '25.00', '2026-09-19 20:30:00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['period' => 'today']))
            ->assertInertia(fn ($page) => $page
                ->where('period', 'today')
                ->where('summary.total', 50)
                ->where('summary.previousTotal', 25)
                ->where('summary.changePct', 100)
                ->where('entries.data', fn ($entries): bool => collect($entries)->map(fn ($entry) => $entry['day'].' '.$entry['time'])->all()
                    === ['2026-09-20 11:00', '2026-09-20 00:30']));
    }

    public function test_an_unknown_period_falls_back_to_thirty_days_and_all_time_has_nothing_to_compare_with(): void
    {
        $this->line($this->subscription, 'subscription_fee', '40.00', '2026-08-01 10:00:00');
        $this->line($this->subscription, 'subscription_fee', '10.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['period' => 'forever']))
            ->assertInertia(fn ($page) => $page->where('period', '30')->where('summary.total', 10)->has('dailyTotals', 30));

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['period' => 'all']))
            ->assertInertia(fn ($page) => $page
                ->where('summary.total', 50)
                ->where('summary.previousTotal', null)
                ->where('summary.changePct', null));
    }

    public function test_the_daily_chart_counts_each_business_day_of_the_headline_side(): void
    {
        $this->line($this->subscription, 'meter_reading', '120.00', '2026-09-18 09:00:00');
        $this->line($this->subscription, 'subscription_fee', '30.00', '2026-09-18 10:00:00');
        $this->line($this->subscription, 'payment', '-100.00', '2026-09-18 11:00:00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['period' => '7']))
            ->assertInertia(fn ($page) => $page
                ->has('dailyTotals', 7)
                ->where('dailyTotals.4', ['date' => '2026-09-18', 'value' => 150, 'count' => 2])
                ->where('dailyTotals.6', ['date' => '2026-09-20', 'value' => 0, 'count' => 0]));
    }

    public function test_each_listed_day_carries_its_full_charges_and_credits(): void
    {
        $this->line($this->subscription, 'meter_reading', '120.00', '2026-09-20 09:00:00');
        $this->line($this->subscription, 'payment', '-100.00', '2026-09-20 10:00:00');
        $this->line($this->subscription, 'subscription_fee', '30.00', '2026-09-19 10:00:00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['per_page' => 15]))
            ->assertInertia(fn ($page) => $page
                ->where('today', '2026-09-20')
                ->where('dayTotals', [
                    '2026-09-20' => ['count' => 2, 'charged' => 120, 'credited' => 100],
                    '2026-09-19' => ['count' => 1, 'charged' => 30, 'credited' => 0],
                ]));
    }

    public function test_the_search_and_the_recorder_filter_narrow_the_lines(): void
    {
        $collector = User::factory()->collector()->create(['branch_id' => $this->branch->id, 'name' => 'Collector']);
        $this->line($this->subscription, 'payment', '-20.00', null, $collector);
        $this->line(Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Sara Khalil']), 'subscription_fee', '50.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['search' => '0591234']))
            ->assertInertia(fn ($page) => $page->where('entries.data', fn ($entries): bool => collect($entries)->pluck('subscriptionName')->all() === ['Ahmad Nasser']));

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['filter' => ['recorded_by' => $collector->id]]))
            ->assertInertia(fn ($page) => $page
                ->where('entries.data', fn ($entries): bool => collect($entries)->pluck('recordedByName')->all() === ['Collector'])
                ->where('filterOptions', fn ($groups): bool => collect(collect($groups)->firstWhere('key', 'recorded_by')['options'])->pluck('label')->contains('Collector')));
    }

    public function test_sorting_by_amount_goes_by_the_size_of_each_line_and_ungroups_the_days(): void
    {
        $this->line($this->subscription, 'subscription_fee', '30.00');
        $this->line($this->subscription, 'payment', '-100.00');
        $this->line($this->subscription, 'meter_reading', '60.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['sort' => 'amount', 'direction' => 'desc']))
            ->assertInertia(fn ($page) => $page
                ->where('entries.data', fn ($entries): bool => collect($entries)->pluck('amount')->all() === ['100.00', '60.00', '30.00'])
                ->where('dayTotals', []));
    }

    public function test_a_lines_subscription_statement_opens_over_the_log(): void
    {
        $this->line($this->subscription, 'subscription_fee', '50.00');

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['statement' => $this->subscription->id]))
            ->assertInertia(fn ($page) => $page->where('statement.subscription.fullName', 'Ahmad Nasser')->where('statement.summary.balance', '50.00'));
    }

    public function test_malformed_parameters_are_ignored(): void
    {
        $this->line($this->subscription, 'subscription_fee', '50.00');

        $this->actingAs($this->branchAdmin)
            ->get('/ledger?period[]=7&filter[type][]=payment&filter[recorded_by][]=1&sort=amount;drop&statement[]=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '30')->where('summary.total', 50)->where('statement', null));
    }

    public function test_only_the_super_admin_and_the_branchs_own_staff_see_a_branchs_figures(): void
    {
        $viewer = User::factory()->collector()->create(['branch_id' => $this->branch->id]);
        $viewer->permissions()->attach(Permission::idsFor([PermissionKey::ViewBranchPerformance]));
        $otherBranch = Branch::factory()->create();

        $this->assertTrue(User::factory()->superAdmin()->create()->can('viewForBranch', [SubscriptionTransaction::class, $otherBranch]));
        $this->assertTrue($viewer->can('viewForBranch', [SubscriptionTransaction::class, $this->branch]));
        $this->assertFalse($viewer->can('viewForBranch', [SubscriptionTransaction::class, $otherBranch]));
        $this->assertFalse(User::factory()->dataEntry()->create(['branch_id' => $this->branch->id])->can('viewForBranch', [SubscriptionTransaction::class, $this->branch]));
    }

    public function test_the_four_cards_keep_charges_credits_and_cancelled_originals_separate(): void
    {
        $this->line($this->subscription, 'subscription_fee', '100.00');
        $this->line($this->subscription, 'payment', '-30.00');
        $this->line($this->subscription, 'discount', '-10.00');
        $cancelled = $this->line($this->subscription, 'subscription_fee', '60.00');
        $cancelled->update(['cancelled_at' => now()]);
        $reversal = $this->line($this->subscription, 'reversal', '-60.00');
        $reversal->update(['reverses_id' => $cancelled->id]);

        $this->actingAs($this->branchAdmin)->get(route('ledger.index'))
            ->assertInertia(fn ($page) => $page->where('ledgerTotals', [
                'charged' => 100, 'debitCount' => 1, 'credited' => 40, 'creditCount' => 2,
                'net' => 60, 'cancelled' => 60, 'cancelledCount' => 1,
            ])->where('entries.total', 5));

        $this->get(route('ledger.index', ['filter' => ['show_cancelled' => '0']]))
            ->assertInertia(fn ($page) => $page->where('entries.total', 3)
                ->where('ledgerTotals.charged', 100)->where('ledgerTotals.credited', 40)
                ->where('ledgerTotals.cancelledCount', 0));
    }

    #[TestWith(['voucher_number', '314159'])]
    #[TestWith(['voucher_number', '000042'])]
    #[TestWith(['manual_voucher_number', 'R-2026-42'])]
    #[TestWith(['reference_number', 'FT-26272-42'])]
    public function test_receipt_and_reference_searches_cannot_expose_another_branch(string $field, string $search): void
    {
        $mine = $this->line($this->subscription, 'payment', '-20.00');
        $mine->update([$field => $search]);
        $other = $this->line(Subscription::factory()->create(), 'payment', '-80.00');
        $other->update([$field => $search.'0']);

        $this->actingAs($this->branchAdmin)->get(route('ledger.index', ['search' => $search]))
            ->assertInertia(fn ($page) => $page->where('entries.total', 1)
                ->where('entries.data.0.id', $mine->id)->where('ledgerTotals.credited', 20));
    }

    public function test_payment_details_and_filters_use_the_stored_transaction_fields(): void
    {
        $transfer = $this->line($this->subscription, 'payment', '-200.00');
        $transfer->update([
            'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين',
            'reference_number' => 'FT26272', 'manual_voucher_number' => 'R-42',
            'balance_after' => '-50.00', 'currency' => 'USD', 'currency_amount' => '50.00', 'exchange_rate' => '4.0000',
        ]);
        $cash = $this->line($this->subscription, 'payment', '-10.00');
        $cash->update(['payment_method' => 'cash']);
        $other = $this->line(Subscription::factory()->create(), 'payment', '-400.00');
        $other->update(['payment_method' => 'bank_transfer', 'bank_name' => 'Hidden Bank']);

        $this->actingAs($this->branchAdmin)
            ->get(route('ledger.index', ['filter' => ['payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين']]))
            ->assertInertia(fn ($page) => $page->where('entries.total', 1)
                ->where('entries.data.0.voucherNumber', 'R-42')->where('entries.data.0.isManualVoucher', true)
                ->where('entries.data.0.paymentMethod', 'bank_transfer')->where('entries.data.0.bankName', 'بنك فلسطين')
                ->where('entries.data.0.referenceNumber', 'FT26272')->where('entries.data.0.balanceAfter', '-50.00')
                ->where('entries.data.0.currency', 'USD')->where('ledgerTotals.credited', 200)
                ->where('filterOptions', fn ($groups): bool => collect($groups)->firstWhere('key', 'bank_name')['options'] === [['value' => 'بنك فلسطين', 'label' => 'بنك فلسطين']]));
    }

    #[TestWith(['yesterday', null, null, 20, '2026-09-19', '2026-09-19'])]
    #[TestWith(['month', null, null, 50, '2026-09-01', '2026-09-20'])]
    #[TestWith(['custom', '2026-09-19', '2026-09-19', 20, '2026-09-19', '2026-09-19'])]
    public function test_new_periods_include_whole_business_dates_and_exclude_the_next_day(string $period, ?string $from, ?string $to, int $total, string $expectedFrom, string $expectedTo): void
    {
        $this->line($this->subscription, 'subscription_fee', '40.00', '2026-08-31 20:59:59');
        $this->line($this->subscription, 'subscription_fee', '10.00', '2026-08-31 21:00:00');
        $this->line($this->subscription, 'subscription_fee', '15.00', '2026-09-18 21:00:00');
        $this->line($this->subscription, 'subscription_fee', '5.00', '2026-09-19 20:59:59');
        $this->line($this->subscription, 'subscription_fee', '20.00', '2026-09-19 21:00:00');
        $this->line($this->subscription, 'subscription_fee', '80.00', '2026-09-20 21:00:00');

        $this->actingAs($this->branchAdmin)->get(route('ledger.index', array_filter(['period' => $period, 'from' => $from, 'to' => $to])))
            ->assertInertia(fn ($page) => $page->where('period', $period)
                ->where('ledgerTotals.charged', $total)->where('dateRange', ['from' => $expectedFrom, 'to' => $expectedTo]));
    }

    #[TestWith([[], ['from', 'to']])]
    #[TestWith([['from' => '20/09/2026', 'to' => '2026-09-20'], ['from']])]
    #[TestWith([['from' => '2026-09-20', 'to' => '2026-09-19'], ['to']])]
    public function test_custom_dates_reject_missing_malformed_and_reversed_ranges(array $dates, array $errors): void
    {
        $this->actingAs($this->branchAdmin)->get(route('ledger.index', ['period' => 'custom', ...$dates]))
            ->assertRedirect()->assertSessionHasErrors($errors);
    }

    public function test_excel_csv_exports_all_matching_rows_with_scope_and_formula_protection(): void
    {
        $this->subscription->update(['full_name' => '=HYPERLINK("unsafe")']);
        for ($index = 0; $index < 26; $index++) {
            $line = $this->line($this->subscription, 'payment', '-20.00');
            $line->update(['payment_method' => 'cash']);
        }
        $this->line($this->subscription, 'subscription_fee', '60.00');
        $this->line(Subscription::factory()->create(['full_name' => 'Other branch']), 'payment', '-100.00');

        $response = $this->actingAs($this->branchAdmin)->get(route('ledger.index', ['format' => 'csv', 'per_page' => 15, 'filter' => ['payment_method' => 'cash']]));
        $response->assertOk()->assertDownload('ledger-2026-09-20.csv');
        $csv = $response->streamedContent();
        $rows = array_map(fn (string $row): array => str_getcsv($row), explode("\n", trim(substr($csv, 3))));
        $this->assertCount(27, $rows);
        $this->assertSame("'=HYPERLINK(\"unsafe\")", $rows[1][2]);
        $this->assertSame('20', $rows[1][12]);
        $this->assertSame(['الرصيد بعده', 'حالة الرصيد'], [$rows[0][13], $rows[0][14]]);
        $this->assertSame(['-520', 'له'], [$rows[1][13], $rows[1][14]]);
        $this->assertStringNotContainsString('Other branch', $csv);
    }

    public function test_csv_export_requires_the_same_ledger_permission(): void
    {
        $this->get(route('ledger.index', ['format' => 'csv']))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]))
            ->get(route('ledger.index', ['format' => 'csv']))->assertForbidden();
    }

    /**
     * A line on the subscription's account, recorded at `$at` (UTC; now when
     * null). Payments and discounts are negative, as the app stores them.
     */
    private function line(Subscription $subscription, string $type, string $amount, ?string $at = null, ?User $recordedBy = null): SubscriptionTransaction
    {
        return SubscriptionTransaction::factory()->for($subscription)->create([
            'type' => $type,
            'source_key' => $type.':'.Str::ulid(),
            'amount' => $amount,
            'currency_amount' => ltrim($amount, '-'),
            'recorded_by' => ($recordedBy ?? $this->branchAdmin)->id,
            'created_at' => $at ?? now(),
        ]);
    }
}
