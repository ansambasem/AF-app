<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\PermissionKey;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\MeterBox;
use App\Models\Permission;
use App\Models\SplitPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The ways money comes in and goes back, and who may see and touch it.
 *
 * Payments: cash, a transfer, a transfer whose reference is taken, and a
 * transfer split between two subscribers — its details amended once for
 * both shares, refunded from one share for both, the refund deleted to
 * bring both back, refunded again and deleted with its refunds, and a second
 * split deleted outright while it is the last line of both statements; a
 * cash payment refunded, its refund deleted, then deleted itself.
 *
 * Scopes: a branch manager sees and acts on their own branch only, on every
 * page; the Super Admin sees every branch, or one when they pick it, and
 * whatever they record or correct, in any branch, carries their own name.
 */
class PaymentsAndBranchScopesJourneyTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyThroughTheWebsite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-22 09:00', 'Asia/Gaza'));
        $this->startFreshDeployment();
    }

    public function test_cash_transfer_and_split_payments_go_back_and_come_back_with_every_balance_in_step(): void
    {
        $company = $this->setUpCompany();
        ['manager' => $manager, 'collector' => $collector] = $company;
        $this->grantForceDelete($manager);
        [$salem, $khaled, $mona] = $this->registerThree($company, $manager);
        $this->assertBalances([200.0, 150.0, 100.0], $salem, $khaled, $mona);

        // A cash payment and a transfer; a second transfer on the same reference is refused.
        $cash = $this->pay($collector, $khaled, ['amount' => '40', 'payment_method' => 'cash']);
        $this->pay($collector, $khaled, ['amount' => '60', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'خالد', 'reference_number' => 'TRF-1']);
        $this->actingAs($collector)->post(route('subscriptions.payments.store', $mona), [
            'amount' => '10', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'منى', 'reference_number' => 'TRF-1',
        ])->assertSessionHasErrors('reference_number');
        $this->assertBalances([200.0, 50.0, 100.0], $salem, $khaled, $mona);

        // One transfer split between Salem and Mona: its parts must add up, and its reference must be free.
        $split = fn (string $reference, array $amounts): array => [
            'total_amount' => (string) array_sum($amounts), 'bank_name' => 'بنك فلسطين', 'sender_name' => 'أبو سالم', 'reference_number' => $reference,
            'parts' => [['subscription_id' => $salem->id, 'amount' => (string) $amounts[0]], ['subscription_id' => $mona->id, 'amount' => (string) $amounts[1]]],
        ];
        $this->actingAs($collector)->post(route('payments.split.store'), [...$split('SPLIT-1', [70, 50]), 'total_amount' => '130'])->assertSessionHasErrors('parts');
        $this->actingAs($collector)->post(route('payments.split.store'), $split('TRF-1', [70, 50]))->assertSessionHasErrors('reference_number');
        $this->actingAs($collector)->post(route('payments.split.store'), $split('SPLIT-1', [70, 50]))->assertSessionHasNoErrors();
        $transfer = SplitPayment::sole();
        [$salemShare, $monaShare] = $transfer->payments()->orderBy('id')->get()->all();
        $this->assertSame(['-70.00', '-50.00'], [$salemShare->amount, $monaShare->amount]);
        $this->assertBalances([130.0, 50.0, 50.0], $salem, $khaled, $mona);
        $this->actingAs($manager)->get(route('split-payments.show', $transfer))->assertOk();

        // A penalty after Salem's share: the share is no longer his last line, so it is refunded, not deleted.
        $this->actingAs($manager)->post(route('subscriptions.charges.store', $salem), ['type' => 'penalty', 'amount' => '10', 'notes' => 'تأخير'])->assertSessionHasNoErrors();
        $this->act($manager, $salemShare, ['edit_metadata', 'refund'], ['action' => 'edit_metadata', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'سالم عبد الله', 'amendment_reason' => 'اسم المرسل الصحيح']);
        $this->assertSame(['سالم عبد الله', 'سالم عبد الله'], [$salemShare->fresh()->sender_name, $monaShare->fresh()->sender_name]);
        $this->actingAs($collector)->post(route('subscriptions.transactions.actions.store', [$mona, $monaShare]), ['action' => 'refund'])->assertForbidden();

        $this->act($manager, $monaShare, ['edit_metadata', 'refund'], ['action' => 'refund']);
        $this->assertSame([SubscriptionTransaction::STATUS_LINKED_CANCELLATION, SubscriptionTransaction::STATUS_LINKED_CANCELLATION], [$salemShare->fresh()->status, $monaShare->fresh()->status]);
        $this->assertBalances([210.0, 50.0, 100.0], $salem, $khaled, $mona);

        $monaRefund = $mona->transactions()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole();
        $this->act($manager, $monaRefund, ['delete_reversal'], ['action' => 'delete_reversal', 'correction_notes' => 'الحوالة وصلت فعلًا']);
        $this->assertSame([SubscriptionTransaction::STATUS_ACTIVE, SubscriptionTransaction::STATUS_ACTIVE], [$salemShare->fresh()->status, $monaShare->fresh()->status]);
        $this->assertSame(0, SubscriptionTransaction::query()->where('type', SubscriptionTransaction::TYPE_REFUND)->count());
        $this->assertBalances([140.0, 50.0, 50.0], $salem, $khaled, $mona);

        $this->act($manager, $salemShare, ['edit_metadata', 'refund'], ['action' => 'refund']);
        $this->act($manager, $salemShare, ['delete_tree'], ['action' => 'delete_tree', 'correction_notes' => 'الحوالة سُجّلت مرتين']);
        $this->assertModelMissing($salemShare);
        $this->assertModelMissing($monaShare);
        $this->assertBalances([210.0, 50.0, 100.0], $salem, $khaled, $mona);

        // A second split, the last line of both statements, is deleted outright with both its shares.
        $this->actingAs($collector)->post(route('payments.split.store'), $split('SPLIT-2', [20, 30]))->assertSessionHasNoErrors();
        $secondShare = SubscriptionTransaction::query()->where('reference_number', 'SPLIT-2')->orderBy('id')->firstOrFail();
        $this->assertBalances([190.0, 50.0, 70.0], $salem, $khaled, $mona);
        $this->act($manager, $secondShare, ['edit_metadata', 'delete', 'refund'], ['action' => 'delete', 'correction_notes' => 'رقم المرجع خاطئ']);
        $this->assertSame(0, SubscriptionTransaction::query()->where('reference_number', 'SPLIT-2')->count());
        $this->assertBalances([210.0, 50.0, 100.0], $salem, $khaled, $mona);

        // A cash payment refunded, its refund deleted, and then the payment itself deleted.
        $this->actingAs($manager)->post(route('subscriptions.charges.store', $khaled), ['type' => 'disconnection_fee', 'amount' => '15'])->assertSessionHasNoErrors();
        $this->act($manager, $cash, ['edit_metadata', 'refund'], ['action' => 'refund']);
        $this->assertBalances([210.0, 105.0, 100.0], $salem, $khaled, $mona);
        $this->act($manager, $khaled->transactions()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole(), ['delete_reversal'], ['action' => 'delete_reversal', 'correction_notes' => 'الإرجاع لم يتم']);
        $this->assertBalances([210.0, 65.0, 100.0], $salem, $khaled, $mona);
        $this->actingAs($manager)->post(route('subscriptions.transactions.actions.store', [$khaled, $cash]), ['action' => 'delete', 'correction_notes' => 'ليست آخر حركة'])->assertSessionHasErrors('action');
        $this->assertBalances([210.0, 65.0, 100.0], $salem, $khaled, $mona);

        // One record per subscriber for each share of a split; deleting a split removes each share as a tree.
        $this->assertSame(['delete_reversal', 'delete_reversal', 'delete_tree', 'delete_tree', 'delete_tree', 'delete_tree', 'delete_reversal'], TransactionDeletion::query()->orderBy('id')->pluck('action')->all());
        $owed = $salem->balance() + $khaled->balance() + $mona->balance();
        $this->actingAs($manager)->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('ledgerTotals.net', fn ($net): bool => round((float) $net, 2) === round($owed, 2))
            ->where('summary.collected', 100));
        $this->actingAs($manager)->get(route('reports.index'))->assertInertia(fn ($page) => $page
            ->where('flow.closing', number_format($owed, 2, '.', ''))
            ->where('collections.total', '100.00'));
    }

    public function test_a_branch_manager_sees_their_branch_and_the_super_admin_every_branch_with_their_own_name_on_what_they_do(): void
    {
        $company = $this->setUpCompany();
        ['branch' => $nuseirat, 'manager' => $manager, 'collector' => $collector] = $company;
        [$deir, $deirManager, $deirCollector, $deirBox] = $this->openSecondBranch($company);
        $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company))->assertSessionHasNoErrors();
        $this->actingAs($deirManager)->post(route('subscriptions.store'), $this->subscriberForm($company, [
            'full_name' => 'يوسف حمدان', 'national_id' => '409999999', 'phone' => '0599888777', 'meter_box_id' => $deirBox->id, 'subscription_fee' => '80',
        ]))->assertSessionHasNoErrors();
        $salem = Subscription::query()->where('branch_id', $nuseirat->id)->sole();
        $yousef = Subscription::query()->where('branch_id', $deir->id)->sole();
        $salemCash = $this->pay($collector, $salem, ['amount' => '30', 'payment_method' => 'cash']);
        $yousefCash = $this->pay($deirCollector, $yousef, ['amount' => '20', 'payment_method' => 'cash']);
        $this->actingAs($deirManager)->post(route('subscriptions.charges.store', $yousef), ['type' => 'penalty', 'amount' => '5', 'notes' => 'تأخير'])->assertSessionHasNoErrors();

        // The branch manager: their own branch on every page, and nothing of the other.
        $this->actingAs($manager)->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('entries.total', 2)
            ->where('ledgerTotals.net', 70));
        $this->actingAs($manager)->get(route('reports.index', ['branch' => $deir->id]))->assertInertia(fn ($page) => $page
            ->where('filters.branch', $nuseirat->id)
            ->where('branches', [['value' => $nuseirat->id, 'label' => $nuseirat->name]])
            ->where('flow.closing', '70.00'));
        $this->actingAs($manager)->get(route('receivables.index'))->assertInertia(fn ($page) => $page->where('summary.total', 70)->where('summary.count', 1));
        $this->actingAs($manager)->get(route('subscriptions.statement', $yousef))->assertForbidden();
        $this->actingAs($manager)->post(route('subscriptions.payments.store', $yousef), ['amount' => '5', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($manager)->post(route('subscriptions.transactions.actions.store', [$yousef, $yousefCash]), ['action' => 'refund'])->assertForbidden();
        $this->actingAs($manager)->post(route('payments.split.store'), [
            'total_amount' => '20', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'مرسل', 'reference_number' => 'X-BRANCH',
            'parts' => [['subscription_id' => $salem->id, 'amount' => '10'], ['subscription_id' => $yousef->id, 'amount' => '10']],
        ])->assertSessionHasErrors('parts.1.subscription_id');
        $this->actingAs($manager)->put(route('branch-view.update'), ['branch_id' => $deir->id])->assertForbidden();
        $this->assertBalances([70.0, 65.0], $salem, $yousef);

        // The Super Admin, over every branch: both branches' lines, and a report per branch.
        $owner = $this->superAdmin;
        $this->actingAs($owner)->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('entries.total', 5)
            ->where('ledgerTotals.net', 135));
        $this->actingAs($owner)->get(route('reports.index', ['branch' => 'all']))->assertInertia(fn ($page) => $page
            ->where('filters.branch', 'all')
            ->where('flow.closing', '135.00')
            ->where('collections.total', '50.00')
            ->where('branchSummary', fn ($branches): bool => collect($branches)->mapWithKeys(fn ($branch): array => [$branch['name'] => $branch['flow']['closing']])->sortKeys()->all() === collect([$nuseirat->name => '70.00', $deir->name => '65.00'])->sortKeys()->all()));
        $this->actingAs($owner)->get(route('receivables.index'))->assertInertia(fn ($page) => $page->where('summary.total', 135)->where('summary.count', 2));

        // What the Super Admin records or corrects, in either branch, carries their name.
        $this->actingAs($owner)->post(route('subscriptions.charges.store', $salem), ['type' => 'penalty', 'amount' => '12', 'notes' => 'غرامة من الإدارة'])->assertSessionHasNoErrors();
        $ownerPenalty = $salem->transactions()->where('type', 'penalty')->sole();
        $this->act($owner, $salemCash, ['edit_metadata', 'refund'], ['action' => 'refund']);
        $this->assertSame([$owner->id, $owner->id], [$ownerPenalty->recorded_by, $salem->transactions()->where('type', SubscriptionTransaction::TYPE_REFUND)->sole()->recorded_by]);
        $this->assertSame($owner->id, $salemCash->fresh()->cancelled_by);

        // ...and once they pick a branch, every page shows that branch alone.
        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $deir->id])->assertRedirect(route('dashboard'));
        $this->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page
            ->where('entries.total', 3)
            ->where('ledgerTotals.net', 65));
        $this->get(route('reports.index'))->assertInertia(fn ($page) => $page->where('filters.branch', $deir->id)->where('flow.closing', '65.00'));
        $this->get(route('receivables.index'))->assertInertia(fn ($page) => $page->where('summary.total', 65)->where('summary.count', 1));
        $this->get(route('subscriptions.statement', $salem))->assertNotFound();

        $yousefPenalty = $yousef->transactions()->where('type', 'penalty')->sole();
        $this->act($owner, $yousefPenalty, ['edit', 'delete'], ['action' => 'edit', 'amount' => '8', 'amendment_reason' => 'غرامة الفرع المعتمدة']);
        $ownerCash = $this->pay($owner, $yousef, ['amount' => '8', 'payment_method' => 'cash']);
        $this->assertBalances([112.0, 60.0], $salem, $yousef);
        $this->assertSame([$owner->id, $deir->id], [$ownerCash->recorded_by, $ownerCash->boxEntry->box->branch_id]);
        $this->assertSame($owner->id, $yousefPenalty->amendments()->sole()->user_id);
        $this->assertSame($deir->id, CashBox::query()->where('user_id', $owner->id)->sole()->branch_id);

        // The Deir branch's manager reads the Super Admin's name on those lines and in the transaction audit.
        $deirLog = $this->actingAs($deirManager)->get(route('ledger.index', ['period' => 'all', 'filter' => ['recorded_by' => $owner->id]]))->inertiaProps('entries');
        $this->assertSame([[$ownerCash->id, $owner->name]], array_map(fn (array $row): array => [$row['id'], $row['recordedByName']], $deirLog['data']));
        $events = $this->actingAs($deirManager)->get(route('transaction-audit.index', ['period' => 'all']))->inertiaProps('events');
        $this->assertSame([$owner->name], array_values(array_unique(array_column($events['data'], 'userName'))));
        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => null])->assertRedirect();
        $this->get(route('ledger.index', ['period' => 'all']))->assertInertia(fn ($page) => $page->where('ledgerTotals.net', 172));
    }

    /**
     * Opens a second branch through the website, with a tablon, a manager
     * and a collector of its own.
     *
     * @param  array<string, mixed>  $company
     * @return array{Branch, User, User, MeterBox}
     */
    private function openSecondBranch(array $company): array
    {
        $this->actingAs($this->superAdmin);
        $this->post(route('branches.store'), ['name' => 'فرع دير البلح', 'is_active' => true, 'governorate_id' => $company['governorate']->id, 'area_id' => $company['area']->id])->assertSessionHasNoErrors();
        $branch = Branch::query()->where('name', 'فرع دير البلح')->sole();
        $this->post(route('meter-boxes.store'), ['name' => 'البركة', 'box_number' => 'D-201', 'sub_area_id' => $company['blockB']->id, 'branch_id' => $branch->id])->assertSessionHasNoErrors();
        $staff = [];
        foreach (['manager' => ['مدير دير البلح', 'deir-manager', UserRole::BranchAdmin->starterPermissions()], 'collector' => ['جابي دير البلح', 'deir-collector', UserRole::Collector->starterPermissions()]] as $key => [$name, $username, $permissions]) {
            $this->post(route('users.store'), ['name' => $name, 'username' => $username, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'branch_id' => $branch->id])->assertSessionHasNoErrors();
            $staff[$key] = User::query()->where('username', $username)->sole();
            $this->put(route('settings.permissions.update'), ['permissions' => [$staff[$key]->id => Permission::idsFor($permissions)]])->assertSessionHasNoErrors();
        }

        return [$branch, $staff['manager']->fresh(), $staff['collector']->fresh(), MeterBox::query()->where('box_number', 'D-201')->sole()];
    }

    /**
     * Registers Salem, Khaled and Mona with fees of 200, 150 and 100.
     *
     * @param  array<string, mixed>  $company
     * @return list<Subscription>
     */
    private function registerThree(array $company, User $manager): array
    {
        foreach ([['سالم عبد الله', '401234567', '0599123456', '200'], ['خالد يوسف', '402345678', '0599234567', '150'], ['منى سعيد', '403456789', '0599345678', '100']] as [$name, $nationalId, $phone, $fee]) {
            $this->actingAs($manager)->post(route('subscriptions.store'), $this->subscriberForm($company, [
                'full_name' => $name, 'national_id' => $nationalId, 'phone' => $phone, 'subscription_fee' => $fee,
            ]))->assertSessionHasNoErrors();
        }

        return Subscription::query()->orderBy('id')->get()->all();
    }

    private function grantForceDelete(User $manager): void
    {
        $this->actingAs($this->superAdmin)->put(route('settings.permissions.update'), [
            'permissions' => [$manager->id => Permission::idsFor([...UserRole::BranchAdmin->starterPermissions(), PermissionKey::ForceDeleteTransactions])],
        ])->assertSessionHasNoErrors();
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
