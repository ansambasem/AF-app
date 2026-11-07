<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\CashBox;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One branch's money from its first subscriber to the auditor's approval,
 * every step through the website, and the financial reports read after it
 * figure by figure: three subscribers registered with their fees, read and
 * billed for a week (one with a standing discount), paid in cash, dollars
 * and by transfer, charged, discounted and cleared; then every action the
 * statement offers is applied — an amount edited, a transfer's details
 * amended, a payment refunded and another deleted with its refund, a
 * discount cancelled and its cancellation deleted, a clearing deleted, a
 * reading corrected — before the week's boxes close and the auditor
 * approves them.
 *
 * The overview's flow, collections, days and readings, the lines a kind
 * narrows to, the CSV download, the debts report, the financial log and
 * the receivables page must all tell the same story, and a day already
 * past must read the same after the next day's corrections.
 */
class FinancialCycleReportsJourneyTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyThroughTheWebsite;

    public function test_a_branchs_week_of_money_reads_the_same_in_every_report_down_to_the_audit(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 09:00', 'Asia/Gaza'));
        $this->startFreshDeployment();
        $company = $this->setUpCompany();
        ['branch' => $branch, 'manager' => $manager, 'collector' => $collector, 'reader' => $reader, 'auditor' => $auditor] = $company;
        $this->actingAs($this->superAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [$manager->id => Permission::idsFor([...UserRole::BranchAdmin->starterPermissions(), PermissionKey::ForceDeleteTransactions])],
        ])->assertSessionHasNoErrors();

        // Day 1: three subscribers are registered; two are charged their fee.
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, [
            'full_name' => 'خالد يوسف', 'national_id' => '402345678', 'phone' => '0599234567', 'subscription_name' => 'محل الأمل', 'meter_box_id' => $company['market']->id,
            'tariff_id' => $company['commercial']->id, 'initial_reading' => 500, 'charge_subscription_fee' => false, 'subscription_fee' => null,
        ]))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, [
            'full_name' => 'منى سعيد', 'national_id' => '403456789', 'phone' => '0599345678', 'meter_box_id' => $company['schools2']->id, 'initial_reading' => 2000, 'subscription_fee' => '50',
        ]))->assertSessionHasNoErrors();
        [$salem, $khaled, $mona] = Subscription::query()->orderBy('id')->get()->all();
        $this->assertBalances([100.0, 0.0, 50.0], $salem, $khaled, $mona);

        // Day 2: the week of 16 October is read and approved, the shop with a standing 10% discount.
        $this->travelTo(Carbon::parse('2026-10-22 10:00', 'Asia/Gaza'));
        $this->actingAs($manager)->put(route('subscriptions.standing-discount.update', $khaled), ['method' => 'percentage', 'value' => '10', 'segment' => 'أسر محتاجة'])->assertSessionHasNoErrors();
        foreach ([[$salem, 1080], [$khaled, 620], [$mona, 2030]] as [$subscription, $reading]) {
            $this->actingAs($reader)->post(route('meter-readings.store'), ['subscription_id' => $subscription->id, 'week_start' => '2026-10-16', 'current_reading' => $reading])->assertSessionHasNoErrors();
        }
        $this->actingAs($reader)->post(route('meter-readings.approve'), ['reading_ids' => MeterReading::query()->pluck('id')->all()])->assertForbidden();
        $this->actingAs($manager)->post(route('meter-readings.approve'), ['reading_ids' => MeterReading::query()->pluck('id')->all()])->assertSessionHasNoErrors();
        $this->assertBalances([140.0, 86.4, 65.0], $salem, $khaled, $mona);

        // Day 2: payments in cash, by transfer and in dollars, and a discount.
        $salemCash = $this->pay($collector, $salem, ['amount' => '90', 'payment_method' => 'cash']);
        $khaledTransfer = $this->pay($collector, $khaled, ['amount' => '50', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'خالد', 'reference_number' => 'TRF-100']);
        $monaDollars = $this->pay($collector, $mona, ['amount' => '10', 'currency' => 'USD', 'exchange_rate' => '3.7', 'payment_method' => 'cash']);
        $this->actingAs($manager)->post(route('subscriptions.discounts.store', $salem), ['method' => 'shekel', 'value' => '5'])->assertSessionHasNoErrors();
        $salemDiscount = $salem->transactions()->where('type', SubscriptionTransaction::TYPE_DISCOUNT)->sole();
        $this->assertSame(['000001', null, '000002'], [$salemCash->printedVoucherNumber(), $khaledTransfer->printedVoucherNumber(), $monaDollars->printedVoucherNumber()]);
        $this->assertSame(['-37.00', '10.00'], [$monaDollars->amount, $monaDollars->currency_amount]);
        $this->assertBalances([45.0, 36.4, 28.0], $salem, $khaled, $mona);

        // Day 3, before anything else: the report of day 2, to read again once day 3 has corrected it.
        $this->travelTo(Carbon::parse('2026-10-23 09:00', 'Asia/Gaza'));
        $dayTwo = $this->report($manager, '2026-10-22', '2026-10-22');
        $this->assertSame(['150.00', '151.00', '191.60', '109.40'], [$dayTwo['flow']['opening'], $dayTwo['flow']['chargesTotal'], $dayTwo['flow']['creditsTotal'], $dayTwo['flow']['closing']]);

        // Day 3: every action the statement offers, each one where it applies.
        $this->actingAs($collector)->post(route('subscriptions.clearings.store', $khaled), ['amount' => '6.40', 'notes' => 'صيانة خط'])->assertSessionHasNoErrors();
        $clearing = $khaled->transactions()->where('type', SubscriptionTransaction::TYPE_CLEARING)->sole();
        $this->assertBalances([45.0, 30.0, 28.0], $salem, $khaled, $mona);
        $this->act($manager, $clearing, ['delete'], ['action' => 'delete', 'correction_notes' => 'المقاصة سُجّلت على الحساب الخطأ']);
        $this->act($manager, $khaledTransfer, ['edit_metadata', 'delete'], ['action' => 'edit_metadata', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'خالد يوسف', 'amendment_reason' => 'اسم المرسل كاملًا']);
        $this->assertSame('خالد يوسف', $khaledTransfer->fresh()->sender_name);

        $monaCash = $this->pay($collector, $mona, ['amount' => '30', 'payment_method' => 'cash']);
        $this->actingAs($manager)->post(route('subscriptions.charges.store', $mona), ['type' => 'penalty', 'amount' => '20', 'notes' => 'تأخر عن السداد'])->assertSessionHasNoErrors();
        $penalty = $mona->transactions()->where('type', 'penalty')->sole();
        $this->act($manager, $penalty, ['edit', 'delete'], ['action' => 'edit', 'amount' => '25', 'amendment_reason' => 'قيمة الغرامة المعتمدة']);
        $this->act($manager, $monaCash, ['edit_metadata', 'refund'], ['action' => 'refund']);
        $this->assertBalances([45.0, 36.4, 53.0], $salem, $khaled, $mona);
        $this->act($manager, $monaCash, ['delete_tree'], ['action' => 'delete_tree', 'correction_notes' => 'دفعة لم تُستلم']);
        $this->assertModelMissing($monaCash);
        $this->assertBalances([45.0, 36.4, 53.0], $salem, $khaled, $mona);

        $this->act($manager, $salemCash, ['edit_metadata', 'refund'], ['action' => 'refund']);
        $this->act($manager, $salemDiscount, ['cancel'], ['action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'خصم بلا موافقة']);
        $this->assertBalances([140.0, 36.4, 53.0], $salem, $khaled, $mona);
        $cancellation = $salem->transactions()->where('type', SubscriptionTransaction::TYPE_CANCELLATION)->sole();
        $this->act($manager, $cancellation, ['delete_reversal'], ['action' => 'delete_reversal', 'correction_notes' => 'الخصم كان بموافقة المدير']);
        $this->assertSame(SubscriptionTransaction::STATUS_ACTIVE, $salemDiscount->fresh()->status);
        $this->assertBalances([135.0, 36.4, 53.0], $salem, $khaled, $mona);

        $salemReading = MeterReading::query()->where('subscription_id', $salem->id)->sole();
        $this->actingAs($manager)->put(route('meter-readings.update', $salemReading), ['current_reading' => 1070, 'notes' => 'خطأ في قراءة العداد', 'approve' => true])->assertSessionHasNoErrors();
        $this->assertBalances([130.0, 36.4, 53.0], $salem, $khaled, $mona);
        $this->assertSame(['delete', 'delete_tree', 'delete_reversal'], TransactionDeletion::query()->orderBy('id')->pluck('action')->all());
        $this->actingAs($reader)->post(route('subscriptions.transactions.actions.store', [$mona, $penalty]), ['action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'ليس من عمله'])->assertSessionHasErrors('action');
        $this->assertBalances([130.0, 36.4, 53.0], $salem, $khaled, $mona);

        // A past day's money reads the same: day 3's refund, cancellation and reading correction count on day 3.
        $this->assertSame(Arr::except($dayTwo, 'readings'), Arr::except($this->report($manager, '2026-10-22', '2026-10-22'), 'readings'));

        // The days so far, in every report.
        $report = $this->report($manager, '2026-10-20', '2026-10-23');
        $lines = fn (array $side): array => array_map(fn (array $line): array => [$line['type'], $line['count'], $line['total']], $side);
        $this->assertSame([['meter_reading', 4, '186.00'], ['subscription_fee', 2, '150.00'], ['penalty', 1, '25.00']], $lines($report['flow']['charges']));
        $this->assertSame([['payment', 3, '177.00'], ['discount', 1, '5.00'], ['reading_discount', 1, '9.60']], $lines($report['flow']['credits']));
        $this->assertSame(
            ['opening' => '0.00', 'chargesTotal' => '361.00', 'creditsTotal' => '191.60', 'corrections' => ['count' => 2, 'total' => '50.00'], 'closing' => '219.40', 'change' => '219.40'],
            Arr::except($report['flow'], ['charges', 'credits']),
        );
        $this->assertSame(['count' => 3, 'total' => '177.00', 'cash' => '127.00', 'nonCash' => '50.00'], array_intersect_key($report['collections'], array_flip(['count', 'total', 'cash', 'nonCash'])));
        $this->assertSame([['cash', 2, '127.00'], ['بنك فلسطين', 1, '50.00']], array_map(fn (array $account): array => [$account['key'], $account['count'], $account['total']], $report['collections']['accounts']));
        $this->assertSame([['ILS', 2, '140.00', '140.00'], ['USD', 1, '37.00', '10.00']], array_map(fn (array $currency): array => [$currency['currency'], $currency['count'], $currency['total'], $currency['amount']], $report['collections']['currencies']));
        $this->assertSame([['محمد الجابي', 3, '177.00', '127.00']], array_map(fn (array $person): array => [$person['name'], $person['count'], $person['total'], $person['cash']], $report['collections']['collectors']));
        $this->assertSame([
            ['2026-10-20', '150.00', '0.00', '0.00', '0.00', '150.00'],
            ['2026-10-21', '0.00', '0.00', '0.00', '0.00', '150.00'],
            ['2026-10-22', '151.00', '177.00', '14.60', '0.00', '109.40'],
            ['2026-10-23', '60.00', '0.00', '0.00', '50.00', '219.40'],
        ], array_map(fn (array $day): array => [$day['day'], $day['charges'], $day['payments'], $day['discounts'], $day['corrections'], $day['balance']], $report['days']));
        $this->assertSame(['entered' => 3, 'consumption' => 220, 'approved' => 3, 'billed' => '136.40', 'pending' => 0], $report['readings']);
        $this->assertSame(14, $report['transactions']['total']);
        foreach (['payments' => 3, 'charges' => 7, 'discounts' => 2, 'corrections' => 4] as $kind => $lines) {
            $this->assertSame($lines, $this->report($manager, '2026-10-20', '2026-10-23', ['kind' => $kind])['transactions']['total'], "kind {$kind}");
        }
        $csv = $this->actingAs($manager)->get(route('reports.export', ['from' => '2026-10-20', 'to' => '2026-10-23']))->assertOk()->streamedContent();
        $this->assertCount(3 + 14, array_filter(explode("\n", trim($csv))));

        $this->actingAs($manager)->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('ledgerTotals', ['charged' => 321, 'debitCount' => 6, 'credited' => 101.6, 'creditCount' => 4, 'net' => 219.4, 'cancelled' => 130, 'cancelledCount' => 2])
            ->where('summary.collected', 87)
            ->where('entries.total', 14));
        $this->actingAs($manager)->get(route('receivables.index'))->assertInertia(fn ($page) => $page
            ->where('summary.total', 219.4)
            ->where('summary.count', 3));
        $debts = $this->actingAs($manager)->get(route('reports.index', ['section' => 'debts', 'from' => '2026-10-23', 'to' => '2026-10-23']))->assertOk()->inertiaProps('debts');
        $this->assertSame(['219.40', '0.00', 3], [$debts['total'], $debts['credit'], $debts['count']]);
        $this->actingAs($manager)->get(route('subscriptions.statement', $salem))->assertInertia(fn ($page) => $page
            ->where('summary.balance', '130.00')
            ->where('entries', function ($entries): bool {
                $this->assertSame(['subscription_fee', 'meter_reading', 'payment', 'discount', 'refund', 'reversal', 'meter_reading'], $entries->pluck('type')->all());
                $this->assertSame(['100.00', '140.00', '50.00', '45.00', '135.00', '95.00', '130.00'], $entries->pluck('balance')->all());

                return true;
            }));

        // The week closes at its cut-off; nothing in a closed box may change. The auditor finds the transfer 5 short and approves the week.
        $this->travelTo(Carbon::parse('2026-10-24 00:00', 'Asia/Gaza'));
        $this->artisan('closings:process')->assertSuccessful();
        $this->assertSame(0, CashBox::query()->where('branch_id', $branch->id)->whereIn('status', ['open', 'pending'])->whereHas('payments')->count());
        $this->assertSame([[], []], [$khaledTransfer->fresh()->availableActions($manager), $monaDollars->fresh()->availableActions($manager)]);
        // A discount is in no box, so the week's closing leaves it open to cancelling, on the day that is done.
        $this->assertSame(['cancel'], $salemDiscount->fresh()->availableActions($manager));
        $this->travelTo(Carbon::parse('2026-10-24 10:00', 'Asia/Gaza'));
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $khaledTransfer->fresh()->boxEntry), ['bank_amount' => '45', 'reason' => 'وصل أقل من المسجل'])->assertSessionHasNoErrors();
        $preview = $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'place' => 'closed', 'week' => '2026-10-17']))->inertiaProps('periodAudit');
        $this->actingAs($auditor)->post(route('payments.period-audits.store'), [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertBalances([130.0, 41.4, 53.0], $salem, $khaled, $mona);

        $audited = $this->report($manager, '2026-10-24', '2026-10-24');
        $this->assertSame(['219.40', '5.00', '0.00', '224.40'], [$audited['flow']['opening'], $audited['flow']['chargesTotal'], $audited['flow']['creditsTotal'], $audited['flow']['closing']]);
        $this->assertSame(['bank_audit_adjustment'], array_column($audited['flow']['charges'], 'type'));
        $this->assertSame('224.40', $this->report($manager, '2026-10-20', '2026-10-24')['flow']['closing']);
        $this->actingAs($manager)->get(route('receivables.index'))->assertInertia(fn ($page) => $page->where('summary.total', 224.4));
    }

    /**
     * The overview of the reports page for the branch's days.
     *
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    private function report(User $viewer, string $from, string $to, array $filters = []): array
    {
        $page = $this->actingAs($viewer)->get(route('reports.index', ['from' => $from, 'to' => $to, ...$filters]))->assertOk()->inertiaProps();

        return [...array_intersect_key($page, array_flip(['flow', 'collections', 'days', 'readings'])), 'transactions' => ['total' => $page['transactions']['total']]];
    }

    /**
     * Apply an action from the line's menu on the statement, which must offer
     * exactly `$offered`.
     *
     * @param  list<string>  $offered
     * @param  array<string, mixed>  $payload
     */
    private function act(User $actor, SubscriptionTransaction $line, array $offered, array $payload): void
    {
        $entries = collect($this->actingAs($actor)->get(route('subscriptions.statement', $line->subscription_id))->inertiaProps('entries'));
        $this->assertSame($offered, $entries->firstWhere('id', $line->id)['available_actions'], "the actions offered on {$line->type} {$line->id}");

        $this->post(route('subscriptions.transactions.actions.store', [$line->subscription_id, $line]), $payload)->assertSessionHasNoErrors();
    }

    /**
     * Take a payment the way the collector's form sends it, and return the payment line.
     *
     * @param  array<string, mixed>  $details
     */
    private function pay(User $collector, Subscription $subscription, array $details): SubscriptionTransaction
    {
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), ['currency' => 'ILS', ...$details])->assertSessionHasNoErrors();

        return $subscription->transactions()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->latest('id')->firstOrFail();
    }

    /**
     * @param  list<float>  $expected  each subscription's balance, in order
     */
    private function assertBalances(array $expected, Subscription ...$subscriptions): void
    {
        $this->assertSame($expected, array_map(fn (Subscription $subscription): float => $subscription->balance(), $subscriptions));
    }
}
