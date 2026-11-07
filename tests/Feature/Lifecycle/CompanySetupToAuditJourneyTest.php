<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\UserRole;
use App\Models\Area;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CircuitBreaker;
use App\Models\CurrencyRate;
use App\Models\Governorate;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\ReadingCycle;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\TariffSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A company's first weeks on a fresh deployment, every step through the
 * website as its people would take it: the Super Admin lays down the
 * locations, banks, currencies, tariffs, breakers, a branch with its tablons
 * and its staff with their permissions; the branch registers subscribers,
 * reads them week by week, corrects a reading and a replaced meter, collects
 * their payments, closes the boxes and hands them to the auditor.
 */
class CompanySetupToAuditJourneyTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyThroughTheWebsite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startFreshDeployment();
    }

    public function test_the_super_admin_lays_down_locations_banks_currencies_tariffs_and_breakers_from_nothing(): void
    {
        $this->actingAs($this->superAdmin);

        $this->post(route('governorates.store'), ['name' => 'المحافظة الوسطى'])->assertSessionHasNoErrors();
        $this->post(route('governorates.store'), ['name' => 'المحافظة الوسطى'])->assertSessionHasErrors('name');
        $governorate = Governorate::sole();
        $this->post(route('areas.store'), ['name' => 'مخيم النصيرات', 'governorate_id' => $governorate->id])->assertSessionHasNoErrors();
        $area = Area::sole();
        $this->post(route('sub-areas.store'), ['name' => 'البلوك أ', 'area_id' => $area->id])->assertSessionHasNoErrors();
        $this->post(route('sub-areas.store'), ['name' => 'البلوك ب', 'area_id' => $area->id])->assertSessionHasNoErrors();
        $this->post(route('sub-areas.store'), ['name' => 'البلوك أ', 'area_id' => $area->id])->assertSessionHasErrors('name');
        $this->assertSame(['البلوك أ', 'البلوك ب'], SubArea::query()->where('area_id', $area->id)->orderBy('id')->pluck('name')->all());

        // The deployment comes with the banks of config('powercollect.transfer_banks'); the company adds its own and retires one.
        $this->post(route('settings.banks.store'), ['name' => 'بنك الاستثمار الفلسطيني', 'is_receiver' => true, 'is_sender' => true, 'is_active' => true])->assertSessionHasNoErrors();
        $this->post(route('settings.banks.store'), ['name' => 'البنك العربي', 'is_receiver' => false, 'is_sender' => true, 'is_active' => true])->assertSessionHasNoErrors();
        $this->post(route('settings.banks.store'), ['name' => 'بنك فلسطين', 'is_receiver' => true, 'is_sender' => true, 'is_active' => true])->assertSessionHasErrors('name');
        $retired = Bank::query()->where('name', 'البنك الإسلامي العربي')->sole();
        $this->put(route('settings.banks.update', $retired), ['name' => $retired->name, 'is_receiver' => true, 'is_sender' => true, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertContains('بنك الاستثمار الفلسطيني', Bank::receiverNames());
        $this->assertNotContains('البنك العربي', Bank::receiverNames());
        $this->assertContains('البنك العربي', Bank::senderNames());
        $this->assertNotContains('البنك الإسلامي العربي', [...Bank::receiverNames(), ...Bank::senderNames()]);
        $dollar = CurrencyRate::query()->where('currency', 'USD')->sole();
        $this->put(route('settings.currencies.update', $dollar), ['rate' => '3.7'])->assertSessionHasNoErrors();
        $this->put(route('settings.currencies.update', CurrencyRate::query()->where('currency', 'ILS')->sole()), ['rate' => '2'])->assertSessionHasErrors('rate');
        $this->assertSame('3.7000', $dollar->fresh()->rate);

        $this->post(route('tariffs.store'), ['category' => 'residential', 'rate' => '0.50'])->assertSessionHasNoErrors();
        $this->post(route('tariffs.store'), ['category' => 'commercial', 'rate' => '0.80'])->assertSessionHasNoErrors();
        $this->post(route('tariffs.store'), ['category' => 'residential', 'rate' => '0.60'])->assertSessionHasErrors('category');
        $this->post(route('tariff-segments.store'), ['name' => 'أسر محتاجة'])->assertSessionHasNoErrors();
        $this->post(route('circuit-breakers.store'), ['ampere' => 10, 'minimum_payment' => '10'])->assertSessionHasNoErrors();
        $this->post(route('circuit-breakers.store'), ['ampere' => 1, 'minimum_payment' => '10'])->assertSessionHasErrors('ampere');

        $this->assertSame(['residential' => '0.50', 'commercial' => '0.80'], Tariff::query()->orderBy('id')->pluck('rate', 'category')->map(fn ($rate) => (string) $rate)->all());
        $this->assertSame(1, TariffSegment::query()->count());
        $this->assertSame(1, CircuitBreaker::query()->count());
    }

    public function test_the_super_admin_opens_a_branch_with_its_tablons_and_staff_who_can_do_only_their_own_work(): void
    {
        $company = $this->setUpCompany();
        ['branch' => $branch, 'manager' => $manager, 'collector' => $collector, 'reader' => $reader, 'auditor' => $auditor] = $company;

        $this->assertSame([$company['governorate']->id, $company['area']->id], [$branch->governorate_id, $branch->area_id]);
        $this->assertSame(['المدارس 1', 'المدارس 2', 'السوق'], $branch->meterBoxes()->orderBy('id')->get()->map->displayName()->all());
        $this->assertSame([$company['blockA']->id, $company['blockB']->id, $company['blockA']->id], $branch->meterBoxes()->orderBy('id')->pluck('sub_area_id')->all());
        $this->assertSame(
            ['manager' => 'branch-manager', 'collector' => 'collector-1', 'reader' => 'reader-1', 'auditor' => 'auditor-1'],
            array_map(fn (User $user): string => $user->username, compact('manager', 'collector', 'reader', 'auditor')),
        );
        $this->assertSame([$branch->id, $branch->id, $branch->id], [$manager->branch_id, $collector->branch_id, $reader->branch_id]);

        // What each one may not do.
        $this->actingAs($collector)->post(route('governorates.store'), ['name' => 'محافظة أخرى'])->assertForbidden();
        $this->actingAs($collector)->post(route('subscriptions.store'), $this->subscriberForm($company, ['national_id' => '407777777']))->assertForbidden();
        $this->actingAs($reader)->post(route('settings.banks.store'), ['name' => 'بنك جديد', 'is_receiver' => true, 'is_sender' => true, 'is_active' => true])->assertForbidden();
        $this->actingAs($manager)->post(route('branches.store'), ['name' => 'فرع آخر'])->assertForbidden();
        $this->actingAs($manager)->post(route('tariffs.store'), ['category' => 'commercial', 'rate' => '0.10'])->assertForbidden();
        $this->actingAs($manager)->post(route('users.store'), [
            'name' => 'متسلل', 'username' => 'sneaky', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'role' => 'super_admin',
        ])->assertSessionHasErrors('role');
        $this->assertSame(5, User::query()->count());
        $this->assertSame(['المحافظة الوسطى'], Governorate::query()->pluck('name')->all());

        // The branch manager adds a tablon to their own branch only, and only in their own area.
        $otherArea = Area::factory()->create();
        $foreignBlock = SubArea::factory()->create(['area_id' => $otherArea->id]);
        $this->actingAs($manager)->post(route('meter-boxes.store'), ['name' => 'المخبز', 'box_number' => 'T-104', 'sub_area_id' => $foreignBlock->id])->assertSessionHasErrors('sub_area_id');
        $this->actingAs($manager)->post(route('meter-boxes.store'), ['name' => 'المخبز', 'box_number' => 'T-101', 'sub_area_id' => $company['blockB']->id])->assertSessionHasErrors('box_number');
        $this->actingAs($manager)->post(route('meter-boxes.store'), ['name' => 'المخبز', 'box_number' => 'T-104', 'sub_area_id' => $company['blockB']->id, 'branch_id' => Branch::factory()->create()->id])->assertSessionHasNoErrors();
        $this->assertSame($branch->id, MeterBox::query()->where('box_number', 'T-104')->sole()->branch_id);

        // Locations in use cannot be removed out from under the branch.
        $this->actingAs($this->superAdmin)->delete(route('governorates.destroy', $company['governorate']))->assertSessionHasErrors();
        $this->actingAs($this->superAdmin)->delete(route('sub-areas.destroy', $company['blockA']))->assertSessionHasErrors();
        $this->assertModelExists($company['governorate']);
        $this->assertModelExists($company['blockA']);
    }

    public function test_staff_sign_in_with_the_password_they_were_given_and_the_auditor_lands_on_the_closed_boxes(): void
    {
        ['collector' => $collector] = $this->setUpCompany();

        $this->post(route('logout'));
        $this->post(route('login'), ['username' => 'collector-1', 'password' => 'wrong-password'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post(route('login'), ['username' => 'collector-1', 'password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($collector);
        $this->post(route('logout'));
        $this->post(route('login'), ['username' => 'auditor-1', 'password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('dashboard'))->assertRedirect(route('payments.cash-boxes', ['place' => 'closed']));

    }

    public function test_subscribers_on_the_tablons_are_read_through_the_weekly_distribution_corrected_and_billed_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 09:00', 'Asia/Gaza'));
        $company = $this->setUpCompany();
        ['manager' => $manager, 'reader' => $reader] = $company;

        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, [
            'full_name' => 'خالد يوسف', 'national_id' => '402345678', 'subscription_name' => 'محل الأمل', 'meter_box_id' => $company['market']->id,
            'tariff_id' => $company['commercial']->id, 'tariff_segment_id' => $company['segment']->id, 'initial_reading' => 500, 'charge_subscription_fee' => false, 'subscription_fee' => null,
        ]))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, [
            'full_name' => 'منى سعيد', 'national_id' => '403456789', 'meter_box_id' => $company['schools2']->id, 'status' => 'suspended', 'initial_reading' => null, 'charge_subscription_fee' => false,
        ]))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, ['full_name' => 'تكرار', 'phone' => '0512345678']))->assertSessionHasErrors(['national_id', 'phone']);
        [$salem, $shop, $waiting] = Subscription::query()->orderBy('id')->get()->all();
        $this->assertSame(['202600001', '202600002', '202600003'], [$salem->account_number, $shop->account_number, $waiting->account_number]);
        $this->assertSame([$company['branch']->id, $company['branch']->id], [$salem->branch_id, $shop->branch_id]);
        $this->assertSame([100.0, 0.0, 0.0], [$salem->balance(), $shop->balance(), $waiting->balance()]);

        // The week of 16 October is read on Thursday the 22nd: one tablon on the reader's phone, one on paper.
        $this->actingAs($manager)->post(route('reading-cycles.store'), ['week_start' => '2026-10-16', 'reading_date' => '2026-10-22'])->assertSessionHasNoErrors();
        $cycle = ReadingCycle::sole();
        $this->actingAs($manager)->post(route('reading-cycles.assignments.store', $cycle), ['box_ids' => [$company['schools2']->id], 'target' => 'paper'])->assertSessionHasErrors('box_ids');
        $this->actingAs($manager)->post(route('reading-cycles.assignments.store', $cycle), ['box_ids' => [$company['schools1']->id], 'target' => 'mobile', 'user_id' => $reader->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('reading-cycles.assignments.store', $cycle), ['box_ids' => [$company['market']->id], 'target' => 'paper'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('reading-cycles.assignments.approve', $cycle), [])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('reading-cycles.paper-sheets.store', $cycle), ['box_ids' => [$company['market']->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, $cycle->paperSheets()->count());

        $this->travelTo(Carbon::parse('2026-10-21 10:00', 'Asia/Gaza'));
        $token = $this->postJson(route('mobile.login'), ['username' => 'reader-1', 'password' => self::PASSWORD, 'remember' => true])->assertOk()->assertJsonPath('user.can_record_readings', true)->json('token');
        $task = $this->withToken($token)->getJson(route('mobile.reading-assignments.index'))->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.subscriptions.0.previous_reading', 1000)
            ->json('assignments.0');
        $this->travelTo(Carbon::parse('2026-10-22 11:00', 'Asia/Gaza'));
        $this->withToken($token)->postJson(route('mobile.reading-assignments.sync'), ['readings' => [[
            'operation_id' => (string) Str::uuid(), 'assignment_id' => $task['id'], 'authorization' => $task['authorization'],
            'subscription_id' => $salem->id, 'current_reading' => '1080', 'captured_at' => now()->toIso8601String(),
        ]]])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->actingAs($reader)->post(route('meter-readings.store'), ['subscription_id' => $shop->id, 'week_start' => '2026-10-16', 'current_reading' => 620])->assertSessionHasNoErrors();
        $this->actingAs($reader)->post(route('meter-readings.store'), ['subscription_id' => $waiting->id, 'week_start' => '2026-10-16', 'current_reading' => 10])->assertSessionHasErrors('subscription_id');
        $this->assertSame([100.0, 0.0], [$salem->balance(), $shop->balance()]);
        $this->actingAs($reader)->post(route('meter-readings.approve'), ['reading_ids' => MeterReading::query()->pluck('id')->all()])->assertForbidden();

        $this->actingAs($manager)->post(route('meter-readings.approve'), ['reading_ids' => MeterReading::query()->pluck('id')->all()])->assertSessionHasNoErrors();
        $this->assertSame([140.0, 96.0], [$salem->balance(), $shop->balance()]);
        $this->actingAs($manager)->post(route('meter-readings.approve'), ['reading_ids' => MeterReading::query()->pluck('id')->all()])->assertSessionHasErrors('reading_ids');
        $this->assertSame([140.0, 96.0], [$salem->balance(), $shop->balance()]);

        // The next week a reading is typed wrong and approved, then corrected: the wrong bill is replaced, not added to.
        $this->travelTo(Carbon::parse('2026-10-29 11:00', 'Asia/Gaza'));
        $this->actingAs($reader)->post(route('meter-readings.store'), ['subscription_id' => $salem->id, 'week_start' => '2026-10-23', 'current_reading' => 1200])->assertSessionHasNoErrors();
        $mistake = MeterReading::query()->whereDate('week_start', '2026-10-23')->sole();
        $this->assertSame(1080.0, $mistake->previous_reading);
        $this->actingAs($manager)->post(route('meter-readings.approve'), ['reading_ids' => [$mistake->id]])->assertSessionHasNoErrors();
        $this->assertSame(200.0, $salem->balance());
        $this->actingAs($manager)->put(route('meter-readings.update', $mistake), ['current_reading' => 1120, 'notes' => 'خطأ في الكتابة', 'approve' => true])->assertSessionHasNoErrors();
        $this->assertSame(160.0, $salem->balance());
        $this->assertSame(['40.00', '60.00', '20.00'], $salem->transactions()->where('type', SubscriptionTransaction::TYPE_METER_READING)->orderBy('id')->pluck('amount')->all());

        // The meter is replaced: once that week has ended, the new one starts at 0 without touching what was billed.
        $this->actingAs($manager)->put(route('meter-readings.adjust-last', $mistake), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 1120, 'reason' => 'استبدال العداد التالف',
        ])->assertForbidden();
        $this->travelTo(Carbon::parse('2026-11-05 11:00', 'Asia/Gaza'));
        $this->actingAs($manager)->put(route('meter-readings.adjust-last', $mistake), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 1120, 'reason' => '',
        ])->assertSessionHasErrors('reason');
        $this->actingAs($manager)->put(route('meter-readings.adjust-last', $mistake), [
            'adjustment_type' => 'meter_change', 'current_reading' => 0, 'expected_reading' => 1120, 'reason' => 'استبدال العداد التالف',
        ])->assertSessionHasNoErrors();
        $this->assertSame(160.0, $salem->balance());
        $this->actingAs($reader)->post(route('meter-readings.store'), ['subscription_id' => $salem->id, 'week_start' => '2026-10-30', 'current_reading' => 30])->assertSessionHasNoErrors();
        $afterChange = MeterReading::query()->whereDate('week_start', '2026-10-30')->sole();
        $this->assertSame([0.0, 30.0], [$afterChange->previous_reading, $afterChange->consumption]);
        $this->actingAs($manager)->post(route('meter-readings.approve'), ['reading_ids' => [$afterChange->id]])->assertSessionHasNoErrors();
        $this->assertSame(175.0, $salem->balance());

        $this->actingAs($manager)->get(route('subscriptions.statement', $salem))->assertInertia(fn ($page) => $page
            ->where('summary.balance', '175.00')
            ->where('entries', function ($entries): bool {
                $this->assertSame(['subscription_fee', 'meter_reading', 'meter_reading', 'reversal', 'meter_reading', 'meter_reading'], $entries->pluck('type')->all());
                $this->assertSame(['100.00', '140.00', '200.00', '140.00', '160.00', '175.00'], $entries->pluck('balance')->all());

                return true;
            }));
    }

    public function test_a_week_of_cash_transfer_and_dollar_payments_is_closed_into_boxes_and_audited_into_the_balances(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        $company = $this->setUpCompany();
        ['branch' => $branch, 'manager' => $manager, 'collector' => $collector, 'reader' => $reader, 'auditor' => $auditor] = $company;
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, ['subscription_fee' => '300']))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, [
            'full_name' => 'خالد يوسف', 'national_id' => '402345678', 'meter_box_id' => $company['market']->id, 'subscription_fee' => '400',
        ]))->assertSessionHasNoErrors();
        [$salem, $khaled] = Subscription::query()->orderBy('id')->get()->all();

        // Payments: who may take them, how, and with what checks.
        $this->actingAs($reader)->post(route('subscriptions.payments.store', $salem), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $salem), ['amount' => '0', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $salem), [
            'amount' => '40', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك غير معروف', 'sender_name' => 'سالم', 'reference_number' => 'TRF-1',
        ])->assertSessionHasErrors('bank_name');
        $cash = $this->pay($collector, $salem, ['amount' => '60', 'payment_method' => 'cash']);
        $transfer = $this->pay($collector, $salem, ['amount' => '40', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'سالم عبد الله', 'reference_number' => 'TRF-1']);
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $khaled), [
            'amount' => '100', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'خالد', 'reference_number' => 'TRF-1',
        ])->assertSessionHasErrors('reference_number');
        $missingTransfer = $this->pay($collector, $khaled, ['amount' => '100', 'payment_method' => 'bank_transfer', 'bank_name' => 'جوال باي', 'sender_name' => 'خالد يوسف', 'reference_number' => 'TRF-2']);
        $dollars = $this->pay($collector, $khaled, ['amount' => '50', 'currency' => 'USD', 'exchange_rate' => '3.7', 'payment_method' => 'cash']);
        $this->assertSame([200.0, 115.0], [$salem->balance(), $khaled->balance()]);
        $this->assertSame([1, null, null, 2], [$cash->voucher_number, $transfer->voucher_number, $missingTransfer->voucher_number, $dollars->voucher_number]);
        $this->actingAs($collector)->get(route('subscriptions.payments.receipt', [$salem, $cash]))->assertOk();
        $this->actingAs($collector)->get(route('subscriptions.payments.receipt', [$khaled, $cash]))->assertNotFound();

        // The first payment opened the branch's box closing; the branch reviews the transfers and closes the boxes.
        $this->assertSame(['cash', 'bank_transfer'], CashBox::query()->where('status', 'open')->orderBy('id')->pluck('type')->map(fn ($type) => $type->value)->all());
        $this->closingAction($collector, 'start', null)->assertForbidden();
        $this->closingAction($manager, 'start', null)->assertSessionHasErrors('action');
        $this->closingAction($collector, 'receive', $cash->boxEntry->cash_box_id)->assertForbidden();
        $this->closingAction($manager, 'electronic', $transfer->boxEntry->id, ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->closingAction($manager, 'electronic', $missingTransfer->boxEntry->id, ['status' => 'missing'])->assertSessionHasNoErrors();
        $this->closingAction($manager, 'receive', $cash->boxEntry->cash_box_id)->assertSessionHasNoErrors();
        $this->closingAction($manager, 'receive', $cash->boxEntry->cash_box_id)->assertSessionHasErrors('action');
        $this->assertSame('completed', $cash->boxEntry->box->fresh()->stage());
        $this->assertSame([], $cash->fresh()->availableActions($manager));

        // A payment after the cash box closed opens a new box; the week's cut-off closes everything left.
        $late = $this->pay($collector, $salem, ['amount' => '20', 'payment_method' => 'cash']);
        $this->assertNotSame($cash->boxEntry->cash_box_id, $late->boxEntry->cash_box_id);
        $this->travelTo('2026-10-23 21:00:00');
        $this->artisan('closings:process')->assertSuccessful();
        $this->assertSame(0, CashBox::query()->where('status', 'open')->count());

        // The auditor checks the transfers against the bank and approves the week; the result reaches the balances once.
        $this->actingAs($manager)->post(route('payments.bank-audits.store', $transfer->boxEntry), ['bank_amount' => '40'])->assertForbidden();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $transfer->boxEntry), ['bank_amount' => '40'])->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $missingTransfer->boxEntry), ['bank_amount' => '0', 'reason' => 'لم تصل الحوالة'])->assertSessionHasNoErrors();
        $preview = $this->actingAs($auditor)->get(route('payments.cash-boxes', ['branch_id' => $branch->id, 'place' => 'closed', 'week' => '2026-10-17']))->inertiaProps('periodAudit');
        $this->assertSame(3, $preview['boxes']);
        $approval = [...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true];
        $this->actingAs($manager)->post(route('payments.period-audits.store'), $approval)->assertForbidden();
        $this->actingAs($auditor)->post(route('payments.period-audits.store'), $approval)->assertSessionHasNoErrors();
        $this->actingAs($auditor)->post(route('payments.period-audits.store'), $approval)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('cash_box_audits', 3);
        $this->assertSame([180.0, 215.0], [$salem->balance(), $khaled->balance()]);
        $this->actingAs($auditor)->post(route('payments.bank-audits.store', $missingTransfer->boxEntry), ['bank_amount' => '100', 'reason' => 'تغيير القرار'])->assertSessionHasErrors('action');
        $this->actingAs($collector)->post(route('subscriptions.transactions.actions.store', [$salem, $cash]), ['action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'بعد التدقيق'])->assertForbidden();
        $this->assertSame([180.0, 215.0], [$salem->balance(), $khaled->balance()]);
        $this->actingAs($manager)->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('summary.collected', 305)
            ->where('ledgerTotals.net', 395));
    }

    public function test_a_second_branchs_staff_cannot_see_bill_collect_from_or_close_the_first_branchs_work(): void
    {
        $this->travelTo('2026-10-22 08:00:00');
        $company = $this->setUpCompany();
        $this->actingAs($company['manager'])->post(route('subscriptions.store'), $this->subscriberForm($company))->assertSessionHasNoErrors();
        $salem = Subscription::sole();
        $cash = $this->pay($company['collector'], $salem, ['amount' => '30', 'payment_method' => 'cash']);

        $this->actingAs($this->superAdmin)->post(route('branches.store'), ['name' => 'فرع دير البلح', 'is_active' => true, 'governorate_id' => $company['governorate']->id])->assertSessionHasNoErrors();
        $otherBranch = Branch::query()->where('name', 'فرع دير البلح')->sole();
        $this->actingAs($this->superAdmin)->post(route('users.store'), [
            'name' => 'مدير دير البلح', 'username' => 'other-manager', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'branch_id' => $otherBranch->id,
        ])->assertSessionHasNoErrors();
        $outsider = User::query()->where('username', 'other-manager')->sole();
        $this->actingAs($this->superAdmin)->put(route('settings.permissions.update'), ['permissions' => [$outsider->id => Permission::idsFor(UserRole::BranchAdmin->starterPermissions())]])->assertSessionHasNoErrors();

        $this->actingAs($outsider)->get(route('subscriptions.statement', $salem))->assertForbidden();
        $this->actingAs($outsider)->post(route('subscriptions.payments.store', $salem), ['amount' => '10', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($outsider)->post(route('subscriptions.charges.store', $salem), ['type' => 'penalty', 'amount' => '5', 'notes' => 'تجربة'])->assertForbidden();
        $this->actingAs($outsider)->post(route('meter-readings.store'), ['subscription_id' => $salem->id, 'week_start' => '2026-10-16', 'current_reading' => 1100])->assertSessionHasErrors('subscription_id');
        $this->actingAs($outsider)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $cash->boxEntry->cash_box_id]), ['branch_id' => $company['branch']->id])->assertNotFound();
        $this->actingAs($outsider)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $cash->boxEntry->cash_box_id]), ['branch_id' => $otherBranch->id])->assertNotFound();
        $this->actingAs($outsider)->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->where('subscriptions.total', 0));

        $this->assertSame(70.0, $salem->balance());
        $this->assertSame('open', $cash->boxEntry->box->fresh()->status);
        $this->assertSame(0, MeterReading::query()->count());
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
     * @param  array<string, mixed>  $data
     */
    private function closingAction(User $actor, string $action, ?int $record, array $data = []): TestResponse
    {
        return $this->actingAs($actor)->post(route('payments.cash-boxes.actions', array_filter(['action' => $action, 'record' => $record])), [
            'branch_id' => $actor->branch_id, ...$data,
        ]);
    }
}
