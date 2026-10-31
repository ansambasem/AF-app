<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\CorrectionReason;
use App\Models\Branch;
use App\Models\MeterReading;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubscriptionStatementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $branchAdmin;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:15:00');
        $this->branch = Branch::factory()->create();
        $this->branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $this->branch->id, 'name' => 'Mohammed']);
        $this->subscription = Subscription::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'Ahmad']);
    }

    public function test_the_statement_lists_charges_and_payments_oldest_first_with_the_balance_after_each(): void
    {
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '50.00', 'recorded_by' => $this->branchAdmin->id]);

        $this->travelTo('2026-08-28 10:02:00');
        MeterReading::factory()->create([
            'subscription_id' => $this->subscription->id,
            'week_start' => '2026-08-21',
            'week_end' => '2026-08-27',
            'previous_reading' => 1000,
            'current_reading' => 1179,
            'consumption' => 179,
            'amount_due' => '53.70',
            'notes' => 'قراءة من الطبلون',
        ])->approve($this->branchAdmin);

        $this->travelTo('2026-08-30 12:40:00');
        $this->recordPayment(['amount' => '74', 'cash_box' => '3', 'manual_voucher_number' => '4471'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/Statement')
                ->where('subscription.fullName', 'Ahmad')
                ->where('entries', function ($entries): bool {
                    $this->assertSame(
                        ['رسوم اشتراك جديد', 'قراءة أسبوعية من 21/08/2026 إلى 27/08/2026 · 179 كيلو', 'دفعة نقدية'],
                        collect($entries)->pluck('description')->all(),
                    );
                    $this->assertSame(['50.00', '103.70', '29.70'], collect($entries)->pluck('balance')->all());
                    $this->assertSame(['رسوم اشتراك', 'قراءة أسبوعية', 'دفعة'], collect($entries)->pluck('typeLabel')->all());
                    $this->assertSame('قراءة من الطبلون', $entries[1]['details']);
                    $this->assertSame([
                        // Recorded at 12:40 UTC: the statement shows the business's clock (Gaza, UTC+3).
                        'date' => '2026-08-30 15:40',
                        'voucherNumber' => '4471',
                        'systemVoucherNumber' => '000001',
                        'manualVoucherNumber' => '4471',
                        'isCredit' => true,
                        'amount' => '74.00',
                        'currencyLabel' => 'شيكل',
                        'exchangeRate' => '1',
                        'paymentMethodLabel' => 'نقد',
                        'cashBox' => null,
                        'recordedByName' => 'Mohammed',
                    ], collect($entries[2])->only([
                        'date', 'voucherNumber', 'systemVoucherNumber', 'manualVoucherNumber', 'isCredit', 'amount', 'currencyLabel', 'exchangeRate', 'paymentMethodLabel', 'cashBox', 'recordedByName',
                    ])->all());

                    return true;
                })
                ->where('summary', [
                    'balance' => '29.70',
                    'charged' => '103.70',
                    'paid' => '74.00',
                    'paymentsCount' => 1,
                    'discounted' => '0.00',
                    'discountsCount' => 0,
                    'cleared' => '0.00',
                    'clearingsCount' => 0,
                ])
                ->where('canRecordPayment', true));
    }

    public function test_a_statement_opened_from_a_cash_box_entry_points_out_its_transaction(): void
    {
        $transaction = SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '50.00']);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', [$this->subscription, 'transaction' => $transaction->id]))
            ->assertInertia(fn ($page) => $page->where('focusLineId', $transaction->id));

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page->where('focusLineId', null));
    }

    public function test_a_payment_lowers_the_balance_and_gets_the_next_voucher_number(): void
    {
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '100.00']);

        $this->recordPayment(['amount' => '26'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'payment-recorded')
            ->assertRedirect(route('subscriptions.statement', $this->subscription));
        $this->recordPayment(['amount' => '20.86']);

        $this->assertSame(['-26.00', '-20.86'], SubscriptionTransaction::where('type', 'payment')->orderBy('id')->pluck('amount')->all());
        $this->assertSame([1, 2], SubscriptionTransaction::where('type', 'payment')->orderBy('id')->pluck('voucher_number')->all());
        $this->assertSame(
            ['action' => 'payment-recorded', 'subject' => 'Ahmad — 26 شيكل'],
            $this->branchAdmin->notifications()->oldest()->first()->data,
        );

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.index'))
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.outstandingBalance', fn ($balance): bool => round((float) $balance, 2) === 53.14));
    }

    public function test_a_payment_is_taken_in_shekels_at_a_rate_of_one(): void
    {
        $this->recordPayment(['amount' => '30', 'currency' => 'ILS', 'exchange_rate' => '5'])->assertSessionHasNoErrors();

        $payment = SubscriptionTransaction::sole();
        $this->assertSame('-30.00', $payment->amount);
        $this->assertSame('1.0000', $payment->exchange_rate);
    }

    public function test_only_cash_payments_get_a_voucher_number_and_transfers_leave_the_sequence_alone(): void
    {
        $this->recordPayment(['amount' => '10'])->assertSessionHasNoErrors();
        $this->recordPayment(['amount' => '20', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'TR-1'])->assertSessionHasNoErrors();
        $this->recordPayment(['amount' => '30', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => 'TR-2'])->assertSessionHasNoErrors();
        $this->recordPayment(['amount' => '40'])->assertSessionHasNoErrors();

        $this->assertSame([1, null, null, 2], SubscriptionTransaction::orderBy('id')->pluck('voucher_number')->all());

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries', fn ($entries): bool => collect($entries)->pluck('voucherNumber')->all() === ['000001', null, null, '000002']));
    }

    #[TestWith(['USD'])]
    #[TestWith(['JOD'])]
    public function test_a_foreign_payment_without_a_managed_exchange_rate_is_refused(string $currency): void
    {
        $this->recordPayment(['amount' => '20', 'currency' => $currency])
            ->assertSessionHasErrors('exchange_rate');

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_a_bank_transfer_needs_one_of_the_transfer_banks_and_sender_and_keeps_no_cash_box_or_paper_voucher(): void
    {
        $this->recordPayment(['payment_method' => 'bank_transfer', 'bank_name' => '', 'reference_number' => '', 'sender_name' => ''])
            ->assertSessionHasErrors([
                'bank_name' => 'اختر البنك أو المحفظة التي حُوّل إليها المبلغ.',
                'sender_name' => 'أدخل اسم صاحب الحساب الذي حُوّل منه المبلغ.',
            ]);
        $this->recordPayment(['payment_method' => 'bank_transfer', 'bank_name' => 'بنك القاهرة', 'reference_number' => 'TRX-1', 'sender_name' => 'Ahmad'])
            ->assertSessionHasErrors(['bank_name' => 'اختر أحد البنوك أو المحافظ المتاحة.']);
        $this->assertDatabaseCount('subscription_transactions', 0);

        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => 'جوال باي',
            'reference_number' => 'TRX-88214',
            'sender_name' => 'محمود سالم',
            'cash_box' => '3',
            'manual_voucher_number' => '4471',
        ])->assertSessionHasNoErrors();

        $transfer = SubscriptionTransaction::sole();
        $this->assertSame(
            ['جوال باي', 'TRX-88214', null, null],
            [$transfer->bank_name, $transfer->reference_number, $transfer->cash_box, $transfer->manual_voucher_number],
        );
        $this->assertSame('دفعة بتحويل بنكي من محمود سالم', $transfer->description());
    }

    public function test_a_transfer_can_be_recorded_without_a_reference_number(): void
    {
        $this->recordPayment(['payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad', 'reference_number' => ''])
            ->assertSessionHasNoErrors();
        $this->recordPayment(['payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Ahmad'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SubscriptionTransaction::whereNull('reference_number')->whereNull('active_reference')->count());
    }

    public function test_a_reference_already_on_another_payment_needs_confirmation_after_normalization(): void
    {
        $otherSubscription = Subscription::factory()->create(['full_name' => 'Mona']);
        $otherCollector = User::factory()->branchAdmin()->create(['branch_id' => $otherSubscription->branch_id]);
        $existing = SubscriptionTransaction::recordPayment($otherSubscription, $otherCollector, [
            'amount' => '25',
            'currency' => 'ILS',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_name' => 'Mona',
            'reference_number' => ' tr  - 42 ',
        ]);
        $duplicate = [
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_name' => 'Ahmad',
            'reference_number' => 'TR-42',
        ];

        $this->recordPayment($duplicate)->assertSessionHasErrors([
            'reference_number' => 'هذا الرقم المرجعي مسجَّل مسبقًا على دفعة أخرى للمشترك Mona.',
        ]);
        $this->assertDatabaseCount('subscription_transactions', 1);

        $this->recordPayment([...$duplicate, 'confirm_duplicate_reference' => true])->assertSessionHasNoErrors();
        $this->assertSame(['TR42', 'TR42'], SubscriptionTransaction::orderBy('id')->pluck('active_reference')->all());
    }

    public function test_live_reference_check_reports_conflicts_and_warns_about_same_day_duplicates(): void
    {
        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_name' => 'Ahmad',
            'reference_number' => 'TR-LIVE-1',
        ])->assertSessionHasNoErrors();
        $payment = SubscriptionTransaction::sole();

        $this->actingAs($this->branchAdmin)
            ->getJson(route('subscriptions.payments.reference-status', [$this->subscription, 'reference_number' => ' tr-live-1 ']))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('conflict.id', $payment->id)
            ->assertJsonPath('conflict.voucherNumber', $payment->printedVoucherNumber());

        $this->getJson(route('subscriptions.payments.reference-status', [
            $this->subscription,
            'reference_number' => 'TR-LIVE-2',
            'amount' => '25',
            'currency' => 'ILS',
            'sender_name' => 'Ahmad',
        ]))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('warning.id', $payment->id);
    }

    public function test_cancelling_a_transfer_releases_its_reference_for_reuse(): void
    {
        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_name' => 'Ahmad',
            'reference_number' => 'REUSE-7',
        ])->assertSessionHasNoErrors();
        $payment = SubscriptionTransaction::sole();

        $this->actingAs($this->branchAdmin)
            ->delete(route('subscriptions.transactions.destroy', [$this->subscription, $payment]), [
                'correction_reason' => 'duplicate',
                'correction_notes' => 'أُلغي لإعادة التسجيل',
            ])->assertSessionHasNoErrors();

        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_name' => 'Ahmad',
            'reference_number' => ' reuse - 7 ',
        ])->assertSessionHasNoErrors();

        $this->assertNull($payment->fresh()->active_reference);
        $this->assertSame('REUSE7', SubscriptionTransaction::whereNull('cancelled_at')->where('type', 'payment')->sole()->active_reference);
    }

    #[TestWith(['بنك فلسطين'])]
    #[TestWith(['محفظة بالباي'])]
    #[TestWith(['جوال باي'])]
    #[TestWith(['البنك الإسلامي الفلسطيني'])]
    #[TestWith(['البنك الإسلامي العربي'])]
    public function test_each_offered_bank_can_be_a_transfer_source_and_destination(string $bank): void
    {
        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => $bank,
            'sender_bank_name' => $bank,
            'sender_name' => 'Ahmad',
            'reference_number' => 'TR-1',
        ])->assertSessionHasNoErrors();

        $transfer = SubscriptionTransaction::sole();
        $this->assertSame($bank, $transfer->bank_name);
        $this->assertSame($bank, $transfer->sender_bank_name);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('transferBanks', config('powercollect.transfer_banks'))
                ->where('entries.0.bankName', $bank)
                ->where('entries.0.senderBankName', $bank)
                ->where('entries.0.recorded.sender_bank_name', $bank));
    }

    public function test_a_sender_only_bank_can_be_a_transfer_source_but_never_its_destination(): void
    {
        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_bank_name' => 'بنك القدس',
            'sender_name' => 'Ahmad',
            'reference_number' => 'TR-QUDS',
        ])->assertSessionHasNoErrors();
        $this->assertSame('بنك القدس', SubscriptionTransaction::sole()->sender_bank_name);

        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك القدس',
            'sender_bank_name' => 'بنك فلسطين',
            'sender_name' => 'Ahmad',
            'reference_number' => 'TR-QUDS-2',
        ])->assertSessionHasErrors('bank_name');
        $this->assertDatabaseCount('subscription_transactions', 1);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('senderBanks', config('powercollect.sender_banks'))
                ->where('transferBanks', fn ($banks) => ! collect($banks)->contains('بنك القدس')));
    }

    public function test_a_transfer_rejects_an_unlisted_source_bank(): void
    {
        $this->recordPayment([
            'payment_method' => 'bank_transfer',
            'bank_name' => 'بنك فلسطين',
            'sender_bank_name' => 'بنك القاهرة',
            'sender_name' => 'Ahmad',
            'reference_number' => 'TR-1',
        ])->assertSessionHasErrors('sender_bank_name');

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_every_time_on_the_statement_follows_the_business_clock_even_across_midnight(): void
    {
        // 22:30 UTC on 30 August is 01:30 on 31 August in Gaza.
        $this->travelTo('2026-08-30 22:30:00');
        $payment = SubscriptionTransaction::recordPayment($this->subscription, $this->branchAdmin, ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'cash']);
        SubscriptionTransaction::recordCharge($this->subscription, $this->branchAdmin, ChargeType::Penalty, '10', null);
        $this->travelTo('2026-08-30 23:10:00');
        $payment->amend($this->branchAdmin, ['notes' => 'تصحيح'], 'توضيح');
        $payment->cancel($this->branchAdmin, CorrectionReason::Duplicate, null);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertInertia(fn ($page) => $page
                ->where('entries.0.date', '2026-08-31 01:30')
                ->where('entries.0.amendments.0.at', '2026-08-31 02:10')
                ->where('entries.0.cancellation.at', '2026-08-31 02:10'));
    }

    public function test_a_recorded_payment_hands_its_form_the_voucher_number_and_the_balance_it_left(): void
    {
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '50.00', 'recorded_by' => $this->branchAdmin->id]);

        $this->followingRedirects()
            ->recordPayment(['amount' => '74'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/Statement')
                ->hasFlash('recordedPayment.voucherNumber', '000001')
                ->hasFlash('recordedPayment.balance', '-24.00'));
    }

    public function test_a_cash_payment_keeps_no_sender(): void
    {
        $this->recordPayment(['payment_method' => 'cash', 'sender_name' => 'محمود سالم', 'bank_name' => 'بنك فلسطين', 'sender_bank_name' => 'جوال باي'])->assertSessionHasNoErrors();

        $payment = SubscriptionTransaction::sole();
        $this->assertNull($payment->sender_name);
        $this->assertNull($payment->bank_name);
        $this->assertNull($payment->sender_bank_name);
        $this->assertSame('دفعة نقدية', $payment->description());
    }

    /**
     * @param  array<string, string>  $payment
     */
    #[TestWith([['amount' => '0'], 'amount'])]
    #[TestWith([['amount' => '10.555'], 'amount'])]
    #[TestWith([['currency' => 'GBP'], 'currency'])]
    #[TestWith([['currency' => 'USD', 'exchange_rate' => '0'], 'exchange_rate'])]
    #[TestWith([['payment_method' => 'gold'], 'payment_method'])]
    #[TestWith([['payment_method' => 'cheque', 'bank_name' => 'بنك فلسطين', 'reference_number' => '77'], 'payment_method'])]
    #[TestWith([['payment_method' => 'e_wallet'], 'payment_method'])]
    public function test_an_invalid_payment_is_rejected(array $payment, string $field): void
    {
        $this->recordPayment($payment)->assertSessionHasErrors($field);

        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_recording_payments_takes_the_record_collections_permission_within_the_branch(): void
    {
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $this->branch->id]);
        $otherBranchAdmin = User::factory()->branchAdmin()->create();

        $this->recordPayment([], $dataEntry)->assertForbidden();
        $this->recordPayment([], $otherBranchAdmin)->assertForbidden();

        $this->actingAs($dataEntry)
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canRecordPayment', false));
        $this->assertDatabaseCount('subscription_transactions', 0);
    }

    public function test_the_subscriptions_list_opens_the_statement_named_in_its_address(): void
    {
        SubscriptionTransaction::factory()->for($this->subscription)->create(['amount' => '50.00', 'recorded_by' => $this->branchAdmin->id]);

        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.index', ['statement' => $this->subscription->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Subscriptions/Index')
                ->where('statement.subscription.fullName', 'Ahmad')
                ->where('statement.entries.0.description', 'رسوم اشتراك جديد')
                ->where('statement.summary.balance', '50.00')
                ->where('statement.canRecordPayment', true));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[TestWith([[]])]
    #[TestWith([['statement' => 'abc']])]
    #[TestWith([['statement' => ['1']]])]
    public function test_the_subscriptions_list_opens_no_statement_without_a_subscription_id(array $query): void
    {
        $this->actingAs($this->branchAdmin)
            ->get(route('subscriptions.index', $query))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statement', null));
    }

    public function test_the_subscriptions_list_does_not_open_another_branchs_statement(): void
    {
        $this->actingAs(User::factory()->branchAdmin()->create())
            ->get(route('subscriptions.index', ['statement' => $this->subscription->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statement', null));
    }

    public function test_a_payment_saved_over_the_list_returns_to_the_list_with_the_statement_still_open(): void
    {
        $listWithStatement = route('subscriptions.index', ['page' => 2, 'statement' => $this->subscription->id]);

        $this->actingAs($this->branchAdmin)
            ->from($listWithStatement)
            ->post(route('subscriptions.payments.store', $this->subscription), ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($listWithStatement);
    }

    public function test_the_statement_is_only_shown_within_the_actors_branch(): void
    {
        $this->actingAs(User::factory()->branchAdmin()->create())
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertForbidden();

        $this->actingAs(User::factory()->collector()->create(['branch_id' => $this->branch->id]))
            ->get(route('subscriptions.statement', $this->subscription))
            ->assertForbidden();
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function recordPayment(array $overrides = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->branchAdmin)
            ->from(route('subscriptions.statement', $this->subscription))
            ->post(route('subscriptions.payments.store', $this->subscription), [
                'amount' => '25',
                'currency' => 'ILS',
                'payment_method' => 'cash',
                ...$overrides,
            ]);
    }
}
