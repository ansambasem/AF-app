<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\CashBoxType;
use App\Enums\MeterReadingStatus;
use App\Enums\PermissionKey;
use App\Enums\ReadingEntryMode;
use App\Enums\SubscriptionStatus;
use App\Enums\TransactionAction;
use App\Models\BankTransferAudit;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\CircuitBreaker;
use App\Models\ClosingSetting;
use App\Models\CurrencyRate;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\ReadingEntrySetting;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\TransactionDeletion;
use App\Models\User;
use App\Support\BranchBoxes;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Weeks of one branch's work, generated from a seed and replayed through the
 * real endpoints by the people who do it: subscribers registered, activated,
 * disconnected, reconnected, archived and restored; weekly readings entered,
 * corrected and approved, with standing discounts given and stopped; cash,
 * foreign, transfer, late and split payments, charges, discounts and
 * clearings; every action the statement offers (and some it does not);
 * collectors' boxes closed by hand and at the end of the week, their
 * transfers reviewed, audited, approved box by box and period by period.
 *
 * After every step the books must still agree: each statement with its own
 * lines, the financial log with the statements, the readings with their
 * bills, the boxes with the payments in them, and nothing a closed box or
 * an audit sealed may change again. A refused request must change nothing.
 *
 * LIFECYCLE_SIMULATION_SEEDS and LIFECYCLE_SIMULATION_STEPS scale a run up
 * (12 seeds of 40 steps by default); a failure prints the steps that led to
 * it and replays with `--filter "seed N"`.
 */
#[Group('simulation')]
class BranchOperationsSimulationTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSFER_BANK = 'بنك فلسطين';

    private Branch $branch;

    private Tariff $tariff;

    private CircuitBreaker $circuitBreaker;

    private MeterBox $meterBox;

    private User $head;

    private User $collector;

    private User $secondCollector;

    private User $dataEntry;

    private User $accountant;

    private User $auditor;

    /** @var list<string> */
    private array $trace = [];

    /** @var array<int, string> what each closed box held when it closed, by box id */
    private array $sealedBoxes = [];

    /** @var array<int, string> each approved box's audit, by box id */
    private array $sealedAudits = [];

    /** @var list<int> transfers recorded on a reference already in use, once the collector confirmed it */
    private array $confirmedDuplicates = [];

    private int $counter = 0;

    /**
     * @return array<string, array{int}>
     */
    public static function seeds(): array
    {
        $count = max(1, (int) (getenv('LIFECYCLE_SIMULATION_SEEDS') ?: 12));

        return collect(range(1, $count))->mapWithKeys(fn (int $seed): array => ["seed {$seed}" => [$seed]])->all();
    }

    #[DataProvider('seeds')]
    public function test_every_step_of_a_simulated_branch_keeps_the_books_consistent(int $seed): void
    {
        mt_srand($seed);
        $this->travelTo('2026-09-03 07:00:00');
        $this->arrangeBranch();
        $steps = max(1, (int) (getenv('LIFECYCLE_SIMULATION_STEPS') ?: 40));

        for ($step = 1; $step <= $steps; $step++) {
            $this->performRandomStep($step);
            $this->assertBooksAreSound();
        }
        $this->closeAndAuditEverything();

        $this->assertBooksAreSound();
        $this->assertSame(0, CashBox::query()->whereIn('status', ['open', 'pending'])->whereHas('payments')->count(), $this->failure('a box with entries is still open after the final week'));
        $this->assertSame(0, CashBox::query()->where('status', 'received')->whereDoesntHave('audit')->count(), $this->failure('a closed box was never approved by the audit'));
        $this->assertSame(0, BankTransferAudit::query()->whereNull('posted_at')->count(), $this->failure('a bank audit decision was never posted'));
    }

    private function arrangeBranch(): void
    {
        ClosingSetting::company()->update(['week_starts_on' => 6]);
        ReadingEntrySetting::company()->update(['mode' => ReadingEntryMode::Open]);
        CurrencyRate::query()->where('currency', 'USD')->update(['rate' => '3.7000']);
        CurrencyRate::query()->where('currency', 'JOD')->update(['rate' => '5.2000']);
        $this->branch = Branch::factory()->create();
        $this->tariff = Tariff::factory()->residential()->create(['rate' => '0.60']);
        $this->circuitBreaker = CircuitBreaker::factory()->create(['minimum_payment' => '12.00']);
        $this->meterBox = MeterBox::factory()->create(['branch_id' => $this->branch->id]);
        $this->head = User::factory()->branchAdmin()->for($this->branch)->withPermissions([
            PermissionKey::ForceDeleteTransactions, PermissionKey::RecordLatePayments,
            PermissionKey::ArchiveSubscriptions, PermissionKey::ViewArchivedSubscriptions, PermissionKey::RestoreArchivedSubscriptions,
        ])->create(['name' => 'رئيس الفرع']);
        $this->collector = User::factory()->collector()->for($this->branch)->withPermissions([PermissionKey::RecordLatePayments])->create(['name' => 'الجابي الأول']);
        $this->secondCollector = User::factory()->collector()->for($this->branch)->create(['name' => 'الجابي الثاني']);
        $this->dataEntry = User::factory()->dataEntry()->for($this->branch)->create(['name' => 'مدخل القراءات']);
        $this->accountant = User::factory()->accountant()->for($this->branch)->withPermissions([PermissionKey::CloseCashBoxes])->create(['name' => 'المحاسب']);
        $this->auditor = User::factory()->financialAuditor()->create(['name' => 'المدقق']);

        foreach (range(1, 3) as $step) {
            $this->registerSubscription(0);
        }
    }

    private function performRandomStep(int $step): void
    {
        $operation = $this->weighted([
            'registerSubscription' => $this->subscriptions()->count() < 6 ? 3 : 0,
            'activateSubscription' => 3, 'disconnectSubscription' => 3, 'reconnectSubscription' => 3,
            'recordReading' => 12, 'approveReadings' => 8, 'correctReading' => 5, 'changeStandingDiscount' => 3,
            'recordCashPayment' => 9, 'recordForeignPayment' => 3, 'recordTransferPayment' => 7,
            'recordLatePayment' => 3, 'recordSplitPayment' => 4,
            'addCharge' => 5, 'addDiscount' => 4, 'addClearing' => 4,
            'applyOfferedAction' => 14, 'applyRefusedAction' => 3,
            'correctLine' => 4, 'deleteLine' => 3, 'eraseLine' => 2, 'amendPayment' => 3,
            'closeBox' => 8, 'closeBoxesOfType' => 2, 'reviewEntry' => 4,
            'passTime' => 18, 'auditTransfer' => 8, 'approveBox' => 3, 'approvePeriod' => 2,
            'archiveSubscription' => 2, 'restoreSubscription' => 2,
        ]);
        $this->trace[] = sprintf('#%d %s at %s', $step, $operation, now()->toDateTimeString());

        $this->{$operation}($step);
    }

    private function registerSubscription(int $step): void
    {
        $active = $this->chance(65);
        $fee = $this->chance(60) ? (string) mt_rand(20, 150) : null;

        $this->submit('register', fn () => $this->actingAs($this->head)->post(route('subscriptions.store'), [
            'full_name' => 'مشترك محاكاة '.(++$this->counter), 'national_id' => sprintf('4%08d', $this->counter),
            'phone' => sprintf('0599%06d', $this->counter), 'tariff_id' => $this->tariff->id,
            'circuit_breaker_id' => $this->circuitBreaker->id, 'meter_box_id' => $this->meterBox->id,
            'status' => $active ? SubscriptionStatus::Active->value : SubscriptionStatus::Suspended->value,
            'initial_reading' => $active ? mt_rand(0, 5000) : null, 'minimum_charge' => mt_rand(0, 25),
            'subscription_fee' => $fee, 'charge_subscription_fee' => $fee !== null,
        ]), mustSucceed: true);
    }

    private function activateSubscription(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->where('status', SubscriptionStatus::Suspended)->whereNull('archived_at')->get());
        if ($subscription === null) {
            return;
        }

        $this->updateStatus($subscription, SubscriptionStatus::Active, ['initial_reading' => mt_rand(0, 5000)]);

        $this->assertNotNull($subscription->fresh()->activated_at, $this->failure('an activated subscription has no activation date'));
    }

    private function disconnectSubscription(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->where('status', SubscriptionStatus::Active)->get());
        if ($subscription !== null) {
            $this->updateStatus($subscription, SubscriptionStatus::Disconnected);
        }
    }

    private function reconnectSubscription(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->where('status', SubscriptionStatus::Disconnected)->whereNull('archived_at')->get());
        if ($subscription === null) {
            return;
        }
        $firstSubscribed = $subscription->subscription_date?->toDateString();

        $this->updateStatus($subscription, SubscriptionStatus::Active);

        $subscription->refresh();
        $this->assertSame(now(config('app.business_timezone'))->toDateString(), $subscription->reconnected_at?->toDateString(), $this->failure('a reconnection was not dated today'));
        $this->assertNotNull($firstSubscribed, $this->failure('a subscription that has been active has no subscription date'));
        $this->assertSame($firstSubscribed, $subscription->subscription_date?->toDateString(), $this->failure('a reconnection changed the day they first subscribed'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function updateStatus(Subscription $subscription, SubscriptionStatus $status, array $overrides = []): void
    {
        $this->submit("status of {$subscription->id} to {$status->value}", fn () => $this->actingAs($this->head)->put(route('subscriptions.update', $subscription), [
            'full_name' => $subscription->full_name, 'national_id' => $subscription->national_id, 'phone' => $subscription->phone,
            'tariff_id' => $subscription->tariff_id, 'circuit_breaker_id' => $subscription->circuit_breaker_id,
            'meter_box_id' => $subscription->meter_box_id, 'minimum_charge' => $subscription->minimum_charge,
            'initial_reading' => $subscription->initial_reading, 'subscription_date' => $subscription->subscription_date?->toDateString(),
            'status' => $status->value, ...$overrides,
        ]), mustSucceed: true);
    }

    private function recordReading(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->where('status', SubscriptionStatus::Active)->get()
            ->filter(fn (Subscription $candidate): bool => $candidate->latestWeekReading() === null
                && ! $candidate->meterReadings()->where('requires_reentry', true)->exists()));
        if ($subscription === null) {
            return;
        }
        $week = MeterReading::latestEndedWeekStart($this->branch->id);
        $previous = $subscription->previousReadingBefore($week);
        $consumption = $this->chance(8) ? mt_rand(2100, 2600) : mt_rand(0, 140);

        $this->submit("reading of {$subscription->id} for {$week->toDateString()}", fn () => $this->actingAs($this->dataEntry)->post(route('meter-readings.store'), [
            'subscription_id' => $subscription->id, 'week_start' => $week->toDateString(), 'current_reading' => $previous + $consumption,
        ]), mustSucceed: true);
    }

    private function approveReadings(int $step): void
    {
        $pending = MeterReading::query()->where('branch_id', $this->branch->id)->where('status', MeterReadingStatus::Pending)
            ->where('requires_reentry', false)->get();
        if ($pending->isEmpty()) {
            return;
        }
        $confirmUnusual = $this->chance(50);

        $this->submit('approve '.$pending->count().' readings', fn () => $this->actingAs($this->head)->post(route('meter-readings.approve'), [
            'reading_ids' => $pending->modelKeys(), 'confirm_unusual' => $confirmUnusual,
        ]), mustSucceed: $confirmUnusual || $pending->contains(fn (MeterReading $reading): bool => ! $reading->isUnusual()));

        foreach ($pending as $reading) {
            $this->assertSame($confirmUnusual || ! $reading->isUnusual(), $reading->fresh()->status === MeterReadingStatus::Approved, $this->failure("reading {$reading->id} was not approved as asked"));
        }
    }

    private function correctReading(int $step): void
    {
        $reading = $this->pick(MeterReading::query()->where('branch_id', $this->branch->id)->get()
            ->filter(fn (MeterReading $candidate): bool => $this->head->can('update', $candidate)
                && ! MeterReading::query()->where('subscription_id', $candidate->subscription_id)->where('week_start', '>', $candidate->week_start)->exists()));
        if ($reading === null) {
            return;
        }
        $approve = $this->chance(50);
        $current = $reading->previous_reading + mt_rand(0, 140);

        $this->submit("correct reading {$reading->id} to {$current}", fn () => $this->actingAs($approve ? $this->head : $this->dataEntry)->put(route('meter-readings.update', $reading), [
            'current_reading' => $current, 'approve' => $approve, 'confirm_unusual' => true,
        ]), mustSucceed: true);

        $this->assertSame((float) $current, $reading->fresh()->current_reading, $this->failure("reading {$reading->id} kept its old value"));
    }

    private function changeStandingDiscount(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->whereNull('archived_at')->get());
        if ($subscription === null) {
            return;
        }

        if ($subscription->standingDiscount()->exists() && $this->chance(40)) {
            $this->submit("stop standing discount of {$subscription->id}", fn () => $this->actingAs($this->head)->delete(route('subscriptions.standing-discount.destroy', $subscription)), mustSucceed: true);

            return;
        }
        [$method, $value] = $this->pick([['percentage', (string) mt_rand(5, 50)], ['kilowatt', (string) mt_rand(1, 20)], ['shekel', '0.'.mt_rand(10, 40)]]);

        $this->submit("standing discount of {$subscription->id}: {$method} {$value}", fn () => $this->actingAs($this->head)->put(route('subscriptions.standing-discount.update', $subscription), [
            'method' => $method, 'value' => $value, 'segment' => 'فئة المحاكاة',
        ]), mustSucceed: true);
    }

    private function recordCashPayment(int $step): void
    {
        $this->recordPayment($this->pick([$this->collector, $this->secondCollector, $this->head]), ['currency' => 'ILS', 'payment_method' => 'cash']);
    }

    private function recordForeignPayment(int $step): void
    {
        $this->recordPayment($this->pick([$this->collector, $this->head]), [
            'currency' => $this->pick(['USD', 'JOD']), 'payment_method' => $this->pick(['cash', 'bank_transfer']), 'amount' => (string) mt_rand(5, 40),
        ]);
    }

    private function recordTransferPayment(int $step): void
    {
        $reused = $this->chance(15) ? SubscriptionTransaction::query()->whereNotNull('active_reference')->inRandomOrder()->value('reference_number') : null;
        $confirmed = $reused !== null && $this->chance(50);

        $payment = $this->recordPayment($this->pick([$this->collector, $this->secondCollector]), [
            'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'reference_number' => $reused ?? $this->newReference(),
            'confirm_duplicate_reference' => $confirmed,
        ]);

        if ($payment !== null && $confirmed) {
            $this->confirmedDuplicates[] = $payment['subscription']->transactions()->where('type', SubscriptionTransaction::TYPE_PAYMENT)->latest('id')->value('id');
        }
    }

    private function recordLatePayment(int $step): void
    {
        $payment = $this->recordPayment($this->collector, [
            'currency' => 'ILS', 'payment_method' => 'bank_transfer',
            'bank_received_on' => now(config('app.business_timezone'))->subDays(mt_rand(1, 4))->toDateString(),
        ]);
        if ($payment === null) {
            return;
        }
        $count = SubscriptionTransaction::query()->count();

        $this->submit('retired late payment flag cannot create another payment', fn () => $this->actingAs($this->collector)->post(route('subscriptions.payments.store', $payment['subscription']), [...$payment['payload'], 'late_payment' => true]), mustSucceed: false);

        $this->assertSame($count, SubscriptionTransaction::query()->count(), $this->failure('replaying a late payment recorded it twice'));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{subscription: Subscription, payload: array<string, mixed>}|null
     */
    private function recordPayment(User $collector, array $details): ?array
    {
        $subscription = $this->pick($this->subscriptions()->whereNull('archived_at')->get());
        if ($subscription === null) {
            return null;
        }
        $payload = [
            'amount' => $this->amountAround($subscription->balance()), 'bank_name' => self::TRANSFER_BANK, 'sender_name' => 'مرسل المحاكاة',
            'confirm_overpayment' => $this->chance(60), ...$details,
        ];

        $succeeded = $this->submit("{$details['payment_method']} payment {$payload['amount']} {$payload['currency']} on {$subscription->id} by {$collector->name}", fn () => $this->actingAs($collector)->post(route('subscriptions.payments.store', $subscription), $payload));

        return $succeeded ? ['subscription' => $subscription, 'payload' => $payload] : null;
    }

    private function recordSplitPayment(int $step): void
    {
        $parts = $this->subscriptions()->whereNull('archived_at')->get()->shuffle()->take(2);
        if ($parts->count() < 2) {
            return;
        }
        $amounts = [mt_rand(5, 80), mt_rand(5, 80)];

        $this->submit('split transfer '.implode('+', $amounts).' on '.$parts->pluck('id')->implode(','), fn () => $this->actingAs($this->collector)->post(route('payments.split.store'), [
            'total_amount' => (string) array_sum($amounts), 'bank_name' => self::TRANSFER_BANK, 'sender_name' => 'مرسل الحوالة المقسمة',
            'reference_number' => $this->newReference(), 'confirm_overpayment' => true,
            'parts' => $parts->values()->map(fn (Subscription $subscription, int $index): array => ['subscription_id' => $subscription->id, 'amount' => (string) $amounts[$index]])->all(),
        ]), mustSucceed: true);
    }

    private function addCharge(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->whereNull('archived_at')->get());
        if ($subscription === null) {
            return;
        }
        $minimum = $subscription->weeklyMinimumPayment();
        [$type, $amount] = $this->pick([
            ['penalty', (string) mt_rand(5, 60)], ['disconnection_fee', '20'], ['subscription_fee', (string) mt_rand(20, 100)],
            ...((float) $minimum > 0 ? [['minimum_charge', $minimum]] : []),
        ]);

        $this->submit("{$type} {$amount} on {$subscription->id}", fn () => $this->actingAs($this->head)->post(route('subscriptions.charges.store', $subscription), [
            'type' => $type, 'amount' => $amount, 'notes' => 'تحميل المحاكاة',
        ]), mustSucceed: true);
    }

    private function addDiscount(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->whereNull('archived_at')->get());
        if ($subscription === null) {
            return;
        }
        $owed = $subscription->balance();
        $value = $owed >= 1 ? (string) mt_rand(1, (int) min($owed, 40)) : '5';

        $this->submit("discount {$value} on {$subscription->id} owing {$owed}", fn () => $this->actingAs($this->head)->post(route('subscriptions.discounts.store', $subscription), [
            'method' => 'shekel', 'value' => $value, 'notes' => 'خصم المحاكاة',
        ]), mustSucceed: $owed >= 1);
    }

    private function addClearing(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->whereNull('archived_at')->get());
        if ($subscription === null) {
            return;
        }
        $amount = (string) mt_rand(5, 60);
        $confirmed = $this->chance(50);

        $this->submit("clearing {$amount} on {$subscription->id} owing {$subscription->balance()}", fn () => $this->actingAs($this->head)->post(route('subscriptions.clearings.store', $subscription), [
            'amount' => $amount, 'notes' => 'صيانة للشركة', 'confirm_over_balance' => $confirmed,
        ]), mustSucceed: $confirmed || (float) $amount <= $subscription->balance());
    }

    /**
     * Apply one of the actions the statement offers, and check that it did
     * to the balance what the statement said it would.
     */
    private function applyOfferedAction(int $step): void
    {
        [$subscription, $entry, $action] = $this->pickStatementAction(fn (array $entry): array => $entry['available_actions']) ?? [null, null, null];
        if ($entry === null) {
            return;
        }
        $line = SubscriptionTransaction::query()->findOrFail($entry['id']);
        $before = $subscription->balance();
        $payload = $this->actionPayload($action, $line);

        $this->submit("{$action} on line {$line->id} ({$line->type} {$line->amount}, {$line->status})", fn () => $this->actingAs($this->head)->post(route('subscriptions.transactions.actions.store', [$subscription, $line]), $payload), mustSucceed: true);

        $expected = match (TransactionAction::from($action)) {
            TransactionAction::Edit => $before - (float) $line->amount + (float) $payload['amount'],
            TransactionAction::EditMetadata => $before,
            TransactionAction::Refund => $line->split_payment_id === null ? $before + (float) $entry['refundableAmount'] : null,
            // Cancelling takes back the line, and a weekly reading's standing discount with it; it never brings back a line it corrected.
            TransactionAction::Cancel => $before - (float) $line->amount + (float) ($entry['readingDiscount'] ?? 0),
            TransactionAction::Delete => $before - (float) $entry['actionEffects']['delete'],
            TransactionAction::DeleteReversal => $before - (float) $entry['actionEffects']['delete_reversal'],
            TransactionAction::DeleteTree => $before - (float) $entry['actionEffects']['delete_tree'],
        };
        if ($expected !== null) {
            $this->assertSame(round($expected, 2), $subscription->balance(), $this->failure("{$action} on line {$line->id} did not leave the balance the statement previewed"));
        }
    }

    /**
     * Try an action the statement does not offer for a line: it must be
     * refused and change nothing.
     */
    private function applyRefusedAction(int $step): void
    {
        [$subscription, $entry, $action] = $this->pickStatementAction(fn (array $entry): array => array_values(array_diff(
            array_map(fn (TransactionAction $action): string => $action->value, TransactionAction::cases()),
            $entry['available_actions'],
        ))) ?? [null, null, null];
        if ($entry === null) {
            return;
        }
        $line = SubscriptionTransaction::query()->findOrFail($entry['id']);

        $succeeded = $this->submit("refused {$action} on line {$line->id} ({$line->type}, {$line->status})", fn () => $this->actingAs($this->head)->post(route('subscriptions.transactions.actions.store', [$subscription, $line]), $this->actionPayload($action, $line)));

        $this->assertFalse($succeeded, $this->failure("an action the statement does not offer was applied: {$action} on line {$line->id}"));
    }

    /**
     * @param  Closure(array<string, mixed>): array<int, string>  $actionsOf
     * @return array{Subscription, array<string, mixed>, string}|null
     */
    private function pickStatementAction(Closure $actionsOf): ?array
    {
        $choices = [];
        foreach ($this->subscriptions()->get() as $subscription) {
            foreach ($this->statement($subscription)['entries'] as $entry) {
                foreach ($actionsOf($entry) as $action) {
                    $choices[] = [$subscription, $entry, $action];
                }
            }
        }

        return $this->pick($choices);
    }

    /**
     * @return array<string, mixed>
     */
    private function actionPayload(string $action, SubscriptionTransaction $line): array
    {
        return match ($action) {
            'edit' => ['action' => 'edit', 'amount' => (string) mt_rand(1, 200), 'amendment_reason' => 'تصحيح المبلغ'],
            'edit_metadata' => ['action' => 'edit_metadata', 'notes' => 'ملاحظة '.(++$this->counter), 'amendment_reason' => 'تعديل البيانات',
                ...($line->payment_method?->throughBank() ? ['bank_name' => self::TRANSFER_BANK] : [])],
            'cancel' => ['action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'إلغاء المحاكاة',
                'reopen_reading' => $line->type === SubscriptionTransaction::TYPE_METER_READING && $this->chance(50)],
            'refund' => ['action' => 'refund', 'correction_notes' => 'إرجاع المحاكاة'],
            default => ['action' => $action, 'correction_notes' => 'حذف المحاكاة'],
        };
    }

    private function correctLine(int $step): void
    {
        [$subscription, $entry] = $this->pickEntry(fn (array $entry): bool => $entry['canCorrect']) ?? [null, null];
        if ($entry === null) {
            return;
        }
        $recorded = $entry['recorded'];
        $payload = match ($recorded['kind']) {
            'payment' => [...array_intersect_key($recorded, array_flip(['currency', 'payment_method', 'bank_name', 'sender_name', 'reference_number'])), 'amount' => (string) mt_rand(5, 90), 'correction_reason' => 'wrong_amount', 'confirm_overpayment' => true],
            'discount' => ['method' => 'shekel', 'value' => '1', 'correction_reason' => 'wrong_amount'],
            'clearing' => ['amount' => (string) mt_rand(5, 40), 'notes' => 'صيانة مصححة', 'confirm_over_balance' => true, 'correction_reason' => 'wrong_amount'],
            default => ['type' => $recorded['type'] === 'minimum_charge' ? 'penalty' : $recorded['type'], 'amount' => (string) mt_rand(5, 60), 'notes' => 'تحميل مصحح', 'correction_reason' => 'wrong_amount'],
        };

        $succeeded = $this->submit("correct line {$entry['id']} ({$entry['type']})", fn () => $this->actingAs($this->head)->put(route('subscriptions.transactions.update', [$subscription, $entry['id']]), [...$payload, 'correction_notes' => 'تصحيح المحاكاة']));

        if ($succeeded) {
            $this->assertSame(1, SubscriptionTransaction::query()->where('corrects_id', $entry['id'])->count(), $this->failure("the correction of line {$entry['id']} does not point back at it"));
        }
    }

    private function deleteLine(int $step): void
    {
        [$subscription, $entry] = $this->pickEntry(fn (array $entry): bool => $entry['canDelete'] && $entry['deletionReasons'] !== []) ?? [null, null];
        if ($entry !== null) {
            $this->submit("delete line {$entry['id']} ({$entry['type']})", fn () => $this->actingAs($this->head)->delete(route('subscriptions.transactions.destroy', [$subscription, $entry['id']]), [
                'correction_reason' => $entry['deletionReasons'][0]['value'], 'correction_notes' => 'إلغاء بسبب خطأ',
            ]), mustSucceed: true);
        }
    }

    private function eraseLine(int $step): void
    {
        [$subscription, $entry] = $this->pickEntry(fn (array $entry): bool => $entry['canForceDelete']) ?? [null, null];
        if ($entry !== null) {
            $this->submit("erase line {$entry['id']} ({$entry['type']})", fn () => $this->actingAs($this->head)->delete(route('subscriptions.transactions.force-destroy', [$subscription, $entry['id']]), [
                'correction_notes' => 'حذف نهائي',
            ]), mustSucceed: true);
        }
    }

    private function amendPayment(int $step): void
    {
        [$subscription, $entry] = $this->pickEntry(fn (array $entry): bool => $entry['canAmend']) ?? [null, null];
        if ($entry !== null) {
            $this->submit("amend payment {$entry['id']}", fn () => $this->actingAs($this->head)->patch(route('subscriptions.transactions.amend', [$subscription, $entry['id']]), [
                'notes' => 'تعديل '.(++$this->counter), 'amendment_reason' => 'تصحيح الملاحظات',
                ...($entry['paymentMethod'] === 'bank_transfer' ? ['bank_name' => self::TRANSFER_BANK, 'sender_name' => 'مرسل معدل'] : []),
            ]), mustSucceed: true);
        }
    }

    /**
     * @param  Closure(array<string, mixed>): bool  $wanted
     * @return array{Subscription, array<string, mixed>}|null
     */
    private function pickEntry(Closure $wanted): ?array
    {
        $choices = [];
        foreach ($this->subscriptions()->get() as $subscription) {
            foreach ($this->statement($subscription)['entries'] as $entry) {
                if ($wanted($entry)) {
                    $choices[] = [$subscription, $entry];
                }
            }
        }

        return $this->pick($choices);
    }

    private function closeBox(int $step): void
    {
        $this->artisan('closings:process')->assertSuccessful();
        $box = $this->pick($this->openBoxes());
        if ($box === null) {
            return;
        }
        $closer = $box->user_id === $this->accountant->id ? $this->head : $this->pick([$this->head, $this->accountant]);

        $this->submit("close box {$box->id} ({$box->type->value}) by {$closer->name}", fn () => $this->actingAs($closer)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $this->branch->id]), mustSucceed: true);

        $this->assertSame('received', $box->fresh()->status, $this->failure("box {$box->id} did not close"));
        $this->assertSame([], $box->payments()->with('transaction')->get()->map->transaction->filter()->flatMap(fn (SubscriptionTransaction $line): array => $line->fresh()->availableActions($this->head))->all(), $this->failure("a line in closed box {$box->id} still offers actions"));
    }

    private function closeBoxesOfType(int $step): void
    {
        $this->artisan('closings:process')->assertSuccessful();
        $type = $this->pick(CashBoxType::cases());
        $boxes = $this->openBoxes()->where('type', $type);
        if ($boxes->isEmpty()) {
            return;
        }

        $this->submit("close {$boxes->count()} {$type->value} boxes", fn () => $this->actingAs($this->head)->post(route('payments.cash-boxes.actions', ['action' => 'receive-type']), [
            'branch_id' => $this->branch->id, 'type' => $type->value, 'box_ids' => $boxes->modelKeys(),
        ]), mustSucceed: true);
    }

    private function reviewEntry(int $step): void
    {
        $this->artisan('closings:process')->assertSuccessful();
        $entry = $this->pick(CashBoxPayment::query()->with(['box', 'transaction'])->where('status', 'pending')->get()
            ->filter(fn (CashBoxPayment $entry): bool => $entry->box->isEditable() && $entry->transaction?->status === SubscriptionTransaction::STATUS_ACTIVE));
        if ($entry === null) {
            return;
        }
        $status = $this->pick($entry->method === 'clearing' ? ['confirmed', 'rejected'] : ['confirmed', 'missing']);

        $this->submit("review entry {$entry->id} ({$entry->method}) as {$status}", fn () => $this->actingAs($this->head)->post(route('payments.cash-boxes.actions', ['action' => 'electronic', 'record' => $entry->id]), [
            'branch_id' => $this->branch->id, 'status' => $status,
        ]), mustSucceed: true);
    }

    private function passTime(int $step): void
    {
        $this->travel(mt_rand(12, 72))->hours();
        $this->trace[] = '  now '.now()->toDateTimeString();

        if ($this->chance(70)) {
            $this->artisan('closings:process')->assertSuccessful();
        }
    }

    private function auditTransfer(int $step): void
    {
        $entry = $this->pick(CashBoxPayment::query()->with(['box', 'transaction'])->get()
            ->filter(fn (CashBoxPayment $entry): bool => $entry->box->status === 'received' && $entry->box->type === CashBoxType::BankTransfer
                && ! $entry->box->audit()->exists() && $entry->transaction?->isPayment() && ! $entry->transaction->isCancelled()));
        if ($entry === null) {
            return;
        }
        $recorded = (float) $entry->transaction->currency_amount;
        $review = $this->actingAs($this->auditor)->getJson(route('payments.bank-audits.show', $entry));
        $this->assertContains($review->getStatusCode(), [200, 422], $this->failure("opening the review of entry {$entry->id} failed with {$review->getStatusCode()}"));
        if ($review->getStatusCode() === 422) {
            $this->trace[] = "  review of entry {$entry->id} → refused: ".implode(' | ', array_merge(...array_values($review->json('errors'))));

            return;
        }
        $split = $review->json('split');
        $bankAmount = fn (float $amount): string => number_format(max(0, $this->pick([$amount, $amount, 0, $amount - mt_rand(1, 10), $amount + mt_rand(1, 10)])), 2, '.', '');
        $parts = $split === null ? [] : collect($split['parts'])->map(fn (array $part): array => ['transaction_id' => $part['transaction_id'], 'bank_amount' => $bankAmount((float) $part['recorded'])])->all();
        $total = $split === null ? $bankAmount($recorded) : number_format(collect($parts)->sum(fn (array $part): float => (float) $part['bank_amount']), 2, '.', '');

        $this->submit("audit transfer entry {$entry->id} recorded {$recorded} as {$total}", fn () => $this->actingAs($this->auditor)->post(route('payments.bank-audits.store', $entry), [
            'bank_amount' => $total, 'reason' => 'مطابقة كشف البنك',
            ...($split === null ? [] : ['parts' => $parts, 'revision' => $split['revision']]),
        ]), mustSucceed: true);
    }

    private function approveBox(int $step): void
    {
        $box = $this->pick(CashBox::query()->where('branch_id', $this->branch->id)->where('status', 'received')->whereDoesntHave('audit')->get());
        if ($box !== null) {
            $this->submit("approve box {$box->id}", fn () => $this->actingAs($this->auditor)->post(route('payments.box-audits.store', $box), ['confirmed' => true]), mustSucceed: true);
        }
    }

    private function approvePeriod(int $step): void
    {
        $box = $this->pick(CashBox::query()->with('closing')->where('branch_id', $this->branch->id)->where('status', 'received')->whereDoesntHave('audit')->get());
        if ($box === null) {
            return;
        }
        $week = app(BranchBoxes::class)->weekOf($box->closingWeekMoment(), $this->branch->id);
        $preview = $this->actingAs($this->auditor)->get(route('payments.cash-boxes', ['branch_id' => $this->branch->id, 'place' => 'closed', 'week' => $week]))->inertiaProps('periodAudit');
        $this->assertContains($box->id, $preview['box_ids'], $this->failure("closed box {$box->id} is missing from the audit of its week {$week}"));

        $this->submit("approve period {$week} with {$preview['boxes']} boxes", fn () => $this->actingAs($this->auditor)->post(route('payments.period-audits.store'), [
            ...array_intersect_key($preview, array_flip(['branch_id', 'week', 'box_ids', 'snapshot'])), 'confirmed' => true,
        ]), mustSucceed: true);

        $this->assertSame(0, CashBox::query()->whereKey($preview['box_ids'])->whereDoesntHave('audit')->count(), $this->failure("period {$week} left a box unapproved"));
    }

    private function archiveSubscription(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->whereNull('archived_at')->get()->filter(fn (Subscription $candidate): bool => $candidate->archiveBlocker() === null));
        if ($subscription === null) {
            return;
        }

        $this->submit("archive {$subscription->id}", fn () => $this->actingAs($this->head)->post(route('subscriptions.archive.store', $subscription), [
            'service_ended_on' => now(config('app.business_timezone'))->toDateString(), 'reason' => 'انتهاء الخدمة', 'confirmed' => true,
        ]), mustSucceed: true);
    }

    private function restoreSubscription(int $step): void
    {
        $subscription = $this->pick($this->subscriptions()->whereNotNull('archived_at')->get());
        if ($subscription !== null) {
            $this->submit("restore {$subscription->id}", fn () => $this->actingAs($this->head)->delete(route('subscriptions.archive.destroy', $subscription), [
                'reason' => 'عودة الخدمة', 'confirmed' => true,
            ]), mustSucceed: true);
        }
    }

    /**
     * Let the last week end, then close whatever is still open and have the
     * audit approve every closed box.
     */
    private function closeAndAuditEverything(): void
    {
        $this->trace[] = '# end of the simulation';
        $this->travel(8)->days();
        $this->artisan('closings:process')->assertSuccessful();
        foreach ($this->openBoxes() as $box) {
            $this->submit("close box {$box->id}", fn () => $this->actingAs($this->head)->post(route('payments.cash-boxes.actions', ['action' => 'receive', 'record' => $box->id]), ['branch_id' => $this->branch->id]), mustSucceed: true);
        }
        foreach (CashBox::query()->where('status', 'received')->whereDoesntHave('audit')->get() as $box) {
            $this->submit("approve box {$box->id}", fn () => $this->actingAs($this->auditor)->post(route('payments.box-audits.store', $box), ['confirmed' => true]), mustSucceed: true);
        }
    }

    private function assertBooksAreSound(): void
    {
        $this->assertStatementsAgreeWithTheirLines();
        $this->assertTheFinancialLogAgreesWithTheStatements();
        $this->assertReadingsAgreeWithTheirBills();
        $this->assertBoxesHoldTheirPayments();
        $this->assertClosedBoxesAndAuditsNeverChange();
    }

    private function assertStatementsAgreeWithTheirLines(): void
    {
        foreach ($this->subscriptions()->get() as $subscription) {
            $lines = $subscription->transactions()->orderBy('created_at')->orderBy('id')->get();
            $byId = $lines->keyBy('id');
            $runningInCents = 0;

            foreach ($lines as $line) {
                $runningInCents += $this->cents($line->amount);
                if ($line->type !== SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT && ! $line->isInClosedDay()) {
                    $this->assertSame($this->money($runningInCents), (string) $line->balance_after, $this->failure("balance_after of line {$line->id} ({$line->type}) is not the running total"));
                }
                if ($line->isReversal()) {
                    $this->assertTrue($byId->has($line->reverses_id), $this->failure("reversal {$line->id} takes back a line that is gone"));

                    continue;
                }
                $reversals = $lines->where('reverses_id', $line->id);
                $netInCents = $this->cents($line->amount) + $reversals->sum(fn (SubscriptionTransaction $reversal): int => $this->cents($reversal->amount));
                if ($line->status === SubscriptionTransaction::STATUS_ACTIVE) {
                    $this->assertNull($line->cancelled_at, $this->failure("active line {$line->id} has a cancellation date"));
                    $this->assertFalse($reversals->isNotEmpty() && $netInCents === 0, $this->failure("line {$line->id} is active but fully reversed"));
                } else {
                    $this->assertSame(0, $netInCents, $this->failure("line {$line->id} is {$line->status} but its reversals do not take it back"));
                }
            }

            $statement = $this->statement($subscription);
            $summary = $statement['summary'];
            $this->assertSame($this->money($runningInCents), $summary['balance'], $this->failure("the statement of {$subscription->id} shows another balance than its lines"));
            $this->assertSame(round($runningInCents / 100, 2), $subscription->balance(), $this->failure("the balance of {$subscription->id} differs from its lines"));
            $this->assertSame($summary['balance'], $this->money($this->cents($summary['charged']) - $this->cents($summary['paid']) - $this->cents($summary['discounted']) - $this->cents($summary['cleared'])), $this->failure("the statement summary of {$subscription->id} does not add up to its balance"));
            $this->assertSame($lines->modelKeys(), collect($statement['entries'])->pluck('id')->all(), $this->failure("the statement of {$subscription->id} does not list every line once, in order"));
            foreach ($statement['entries'] as $entry) {
                $this->assertSame($byId[$entry['id']]->availableActions($this->head), $entry['available_actions'], $this->failure("the statement offers other actions for line {$entry['id']} than the server allows"));
            }
        }

        $references = SubscriptionTransaction::query()->whereNotNull('active_reference')->whereKeyNot($this->confirmedDuplicates)->get()
            ->groupBy(fn (SubscriptionTransaction $line): string => $line->active_reference.':'.($line->split_payment_id ?? 'single:'.$line->id));
        $this->assertSame($references->count(), $references->keys()->map(fn (string $key): string => Str::before($key, ':'))->unique()->count(), $this->failure('two standing payments hold the same reference without the duplicate being confirmed'));
    }

    private function assertTheFinancialLogAgreesWithTheStatements(): void
    {
        $log = $this->actingAs($this->head)->get(route('ledger.index', ['period' => 'all']))->assertOk()->inertiaProps();
        $lines = SubscriptionTransaction::query()->where('branch_id', $this->branch->id)->get();
        $balances = $this->subscriptions()->get()->sum(fn (Subscription $subscription): int => $this->cents((string) $subscription->balance()));
        $collected = $lines->filter(fn (SubscriptionTransaction $line): bool => $line->cancelled_at === null && $line->reverses_id === null
            && in_array($line->type, [SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_BANK_AUDIT_ADJUSTMENT], true))
            ->sum(fn (SubscriptionTransaction $line): int => -$this->cents($line->amount));

        $this->assertSame($lines->count(), $log['entries']['total'], $this->failure('the financial log does not list every line'));
        $this->assertSame($this->money($balances), $this->money($this->cents((string) $log['ledgerTotals']['net'])), $this->failure('the financial log\'s net differs from what the subscriptions owe'));
        $this->assertSame($lines->whereNotNull('cancelled_at')->whereNull('reverses_id')->count(), $log['ledgerTotals']['cancelledCount'], $this->failure('the financial log counts other cancelled lines'));
        $this->assertSame($this->money($collected), $this->money($this->cents((string) $log['summary']['collected'])), $this->failure('the financial log reports another amount collected'));
    }

    private function assertReadingsAgreeWithTheirBills(): void
    {
        foreach (MeterReading::query()->where('branch_id', $this->branch->id)->get() as $reading) {
            $lines = SubscriptionTransaction::query()->where('meter_reading_id', $reading->id)->get();
            $charges = $lines->where('type', SubscriptionTransaction::TYPE_METER_READING)->whereNull('cancelled_at');
            $discounts = $lines->where('type', SubscriptionTransaction::TYPE_READING_DISCOUNT)->whereNull('cancelled_at');
            $this->assertLessThanOrEqual(1, $charges->count(), $this->failure("reading {$reading->id} is billed twice"));
            $this->assertLessThanOrEqual(1, $discounts->count(), $this->failure("reading {$reading->id} has two standing discounts"));
            $this->assertTrue($discounts->isEmpty() || $charges->isNotEmpty(), $this->failure("reading {$reading->id} has a standing discount without its charge"));

            if ($reading->isPending()) {
                $this->assertTrue($charges->isEmpty(), $this->failure("pending reading {$reading->id} is billed"));

                continue;
            }
            // A bill deleted for good leaves its reading approved and unbilled, with the deletion in the audit log.
            $this->assertTrue($lines->where('type', SubscriptionTransaction::TYPE_METER_READING)->isNotEmpty() || in_array($reading->id, $this->deletedBillReadingIds(), true), $this->failure("approved reading {$reading->id} was never billed"));
            if ($charge = $charges->first()) {
                $this->assertSame($reading->amountBeforeDiscount(), $charge->amount, $this->failure("approved reading {$reading->id} is billed another amount than it costs"));
                $this->assertSame($this->cents($reading->discount_amount) > 0 ? $this->money(-$this->cents($reading->discount_amount)) : null, $discounts->first()?->amount, $this->failure("reading {$reading->id} takes off another standing discount than it was given"));
            }
        }
    }

    /**
     * The readings whose bill was deleted or erased for good.
     *
     * @return list<int>
     */
    private function deletedBillReadingIds(): array
    {
        return TransactionDeletion::query()->get()
            ->flatMap(fn (TransactionDeletion $deletion): array => $deletion->transactions)
            ->where('type', SubscriptionTransaction::TYPE_METER_READING)
            ->pluck('meter_reading_id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    private function assertBoxesHoldTheirPayments(): void
    {
        $collections = SubscriptionTransaction::query()->where('branch_id', $this->branch->id)
            ->whereIn('type', [SubscriptionTransaction::TYPE_PAYMENT, SubscriptionTransaction::TYPE_CLEARING])->get();

        foreach ($collections as $line) {
            $entries = CashBoxPayment::query()->with('box')->where('subscription_transaction_id', $line->id)->get();
            $this->assertLessThanOrEqual(1, $entries->count(), $this->failure("line {$line->id} is in two boxes"));
            if ($line->status === SubscriptionTransaction::STATUS_ACTIVE) {
                $this->assertCount(1, $entries, $this->failure("standing {$line->type} {$line->id} is in no box"));
                if ($entries->first()->box->isEditable()) {
                    $this->assertSame($this->money(abs($this->cents($line->amount))), $entries->first()->amount, $this->failure("box entry of line {$line->id} holds another amount"));
                }
            }
        }
        $this->assertSame(0, CashBox::query()->whereIn('status', ['open', 'pending'])->whereHas('audit')->count(), $this->failure('an open box was approved by the audit'));
        foreach (BankTransferAudit::query()->whereNotNull('posted_at')->get() as $audit) {
            $this->assertSame($this->cents($audit->difference) === 0, $audit->adjustment_transaction_id === null, $this->failure("posted audit {$audit->id} has a wrong adjustment"));
            if ($audit->adjustment_transaction_id !== null) {
                $this->assertSame($audit->difference, $audit->adjustment->amount, $this->failure("the adjustment of audit {$audit->id} is not its difference"));
            }
        }
    }

    private function assertClosedBoxesAndAuditsNeverChange(): void
    {
        foreach (CashBox::query()->whereNotIn('status', ['open', 'pending'])->with(['payments.transaction', 'audit'])->get() as $box) {
            $sealed = json_encode([$box->getAttributes(), $box->payments->map(fn (CashBoxPayment $entry): array => [$entry->getAttributes(), $entry->transaction?->getAttributes()])->all()], JSON_THROW_ON_ERROR);
            $this->sealedBoxes[$box->id] ??= $sealed;
            $this->assertSame($this->sealedBoxes[$box->id], $sealed, $this->failure("closed box {$box->id} or a payment in it changed"));

            if ($box->audit !== null) {
                $audit = json_encode([$box->audit->getAttributes(), BankTransferAudit::query()->whereIn('cash_box_payment_id', $box->payments->modelKeys())->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR);
                $this->sealedAudits[$box->id] ??= $audit;
                $this->assertSame($this->sealedAudits[$box->id], $audit, $this->failure("the approved audit of box {$box->id} changed"));
            }
        }
    }

    /**
     * Send a request; a refused one must change nothing.
     *
     * @param  Closure(): TestResponse  $request
     */
    private function submit(string $label, Closure $request, bool $mustSucceed = false): bool
    {
        $before = $this->books();
        session()->forget('errors');

        $response = $request();

        $status = $response->getStatusCode();
        $errors = $this->validationErrors();
        $succeeded = $status < 400 && $errors === [];
        $this->trace[] = sprintf('  %s → %s', $label, $succeeded ? 'done' : "refused ({$status}) ".implode(' | ', $errors));
        $this->assertLessThan(500, $status, $this->failure("{$label} failed with {$status}"));
        $this->assertTrue($succeeded || ! $mustSucceed, $this->failure("{$label} was refused"));
        if (! $succeeded) {
            $this->assertSame($before, $this->books(), $this->failure("refused {$label} still changed the books"));
        }

        return $succeeded;
    }

    /**
     * The messages of the last request's validation errors. The session is
     * stored as JSON, so its error bag comes back as an array of bags.
     *
     * @return list<string>
     */
    private function validationErrors(): array
    {
        $bags = session('errors');
        if ($bags instanceof ViewErrorBag) {
            return $bags->getBag('default')->all();
        }

        return collect($bags ?? [])->flatMap(fn (array $bag): array => collect($bag['messages'] ?? [])->flatten()->all())->values()->all();
    }

    /** Everything a request could change in the branch's books. */
    private function books(): string
    {
        return md5(json_encode(collect([
            'subscriptions', 'subscription_transactions', 'meter_readings', 'standing_discounts', 'split_payments',
            'cash_boxes', 'cash_box_payments', 'bank_transfer_audits', 'cash_box_audits', 'transaction_amendments', 'transaction_deletions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->all()])->all(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function statement(Subscription $subscription): array
    {
        return $this->actingAs($this->head)->get(route('subscriptions.statement', $subscription))->assertOk()->inertiaProps();
    }

    /**
     * @return Builder<Subscription>
     */
    private function subscriptions(): Builder
    {
        return Subscription::query()->where('branch_id', $this->branch->id)->orderBy('id');
    }

    /**
     * @return Collection<int, CashBox>
     */
    private function openBoxes(): Collection
    {
        return CashBox::query()->where('branch_id', $this->branch->id)->whereIn('status', ['open', 'pending'])->whereHas('payments')->orderBy('id')->get();
    }

    private function newReference(): string
    {
        return 'SIM-'.(++$this->counter).'-'.mt_rand(1000, 9999);
    }

    /** Usually some or all of what is owed; now and then far more. */
    private function amountAround(float $owed): string
    {
        $base = max((int) $owed, 10);
        $amount = $this->chance(10) ? $base * 4 + 50 : $this->pick([mt_rand(1, $base), intdiv($base, 2) ?: 1, $base, mt_rand(1, 30)]);

        return $amount.($this->chance(20) ? '.'.mt_rand(10, 99) : '');
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @return T|null
     */
    private function pick(iterable $items): mixed
    {
        $items = array_values(is_array($items) ? $items : collect($items)->all());

        return $items === [] ? null : $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $choice => $weight) {
            if (($roll -= $weight) <= 0) {
                return $choice;
            }
        }

        return array_key_last($weights);
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function failure(string $message): string
    {
        return $message."\nSteps:\n".implode("\n", $this->trace);
    }
}
