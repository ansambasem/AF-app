<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBoxPayment;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The trial left standing cash payments sharing a paper voucher number, which
 * stops the unique voucher index from being built. The cleanup keeps the
 * first payment of each number and deletes the others with everything that
 * points at them, saving and logging each one first; then the index goes on.
 */
class RemoveDuplicateTestCashVouchersTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $first;

    private Subscription $second;

    private Migration $cleanup;

    private Migration $index;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $branch = Branch::factory()->create();
        $this->first = Subscription::factory()->create(['branch_id' => $branch->id]);
        $this->second = Subscription::factory()->create(['branch_id' => $branch->id]);
        $this->line($this->first, ['amount' => '500.00']);
        $this->line($this->second, ['amount' => '300.00']);

        $this->cleanup = require database_path('migrations/2026_11_09_090220_remove_duplicate_test_cash_vouchers.php');
        $this->index = require database_path('migrations/2026_11_09_090221_add_unique_cash_voucher_to_subscription_transactions.php');
        // The database as it is on the server: the index not yet built, so vouchers can be shared.
        $this->index->down();
        File::deleteDirectory(storage_path('app/private/voucher-cleanup'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/voucher-cleanup'));
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function saved(string $file): array
    {
        return json_decode(File::get(File::directories(storage_path('app/private/voucher-cleanup'))[0].'/'.$file), true);
    }

    public function test_the_first_payment_of_each_voucher_is_kept_and_the_duplicates_are_deleted_with_what_points_at_them(): void
    {
        $kept = $this->payment($this->first, '-100.00', '500');
        $duplicate = $this->payment($this->first, '-50.00', ' 500 ');
        $entry = CashBoxPayment::query()->where('subscription_transaction_id', $duplicate->id)->sole();
        DB::table('transaction_amendments')->insert(['transaction_id' => $duplicate->id, 'changes' => '{}', 'reason' => 'test']);
        $refund = $this->line($this->first, ['type' => 'refund', 'amount' => '20.00', 'reference_transaction_id' => $duplicate->id]);
        $otherSubscriber = $this->payment($this->second, '-30.00', '500');
        $cancelled = $this->payment($this->first, '-40.00', '500', ['status' => 'cancelled', 'cancelled_at' => now()]);
        $untouched = [
            $this->payment($this->first, '-10.00', '501')->id,
            $this->payment($this->first, '-10.00', null)->id,
            $this->payment($this->second, '-10.00', null)->id,
            $this->payment($this->second, '-10.00', '')->id,
        ];

        $output = $this->runCleanup();

        $this->assertStringContainsString("Duplicate voucher numbers: 1; payments to delete: 2\n  voucher 500: kept #{$kept->id}, delete #{$duplicate->id}, #{$otherSubscriber->id}", $output);
        foreach ([$duplicate->id, $refund->id, $otherSubscriber->id] as $deleted) {
            $this->assertDatabaseMissing('subscription_transactions', ['id' => $deleted]);
        }
        foreach ([$kept->id, $cancelled->id, ...$untouched] as $remaining) {
            $this->assertDatabaseHas('subscription_transactions', ['id' => $remaining]);
        }
        $this->assertSame(8, SubscriptionTransaction::query()->count());
        $this->assertModelMissing($entry);
        $this->assertSame(0, DB::table('transaction_amendments')->count());
        $this->assertNoOrphans();

        // Every deleted row was saved first, and each deletion is in the audit log with its reason.
        $saved = $this->saved('deleted-rows.json');
        $this->assertSame([$duplicate->id, $otherSubscriber->id], array_column(array_column(array_column($saved, 'transactions'), 0), 'id'));
        $this->assertSame([$duplicate->id, $refund->id], array_column($saved[0]['transactions'], 'id'));
        $this->assertSame([$entry->id], array_column($saved[0]['cash_box_payments'], 'id'));
        $before = $this->saved('tables-before.json');
        $this->assertCount(11, $before['subscription_transactions']);
        $this->assertContains($entry->id, array_column($before['cash_box_payments'], 'id'));
        $deletions = TransactionDeletion::query()->orderBy('id')->get();
        $this->assertSame([$this->first->id, $this->second->id], $deletions->pluck('subscription_id')->all());
        $this->assertSame([TransactionDeletion::ACTION_ERASE, TransactionDeletion::ACTION_ERASE], $deletions->pluck('action')->all());
        $this->assertStringContainsString("رقم السند 500 مسجّل على دفعة نقدية أخرى (رقم {$kept->id}) أُبقيت", $deletions[0]->reason);

        // The running balance is the ledger's again.
        foreach ([$this->first, $this->second] as $subscription) {
            $last = SubscriptionTransaction::query()->where('subscription_id', $subscription->id)->orderByDesc('id')->first();
            $this->assertSame(number_format((float) SubscriptionTransaction::query()->where('subscription_id', $subscription->id)->sum('amount'), 2, '.', ''), $last->balance_after);
        }

        // The unique voucher index can now be built.
        $this->index->up();
        $this->assertTrue(Schema::hasColumn('subscription_transactions', 'cash_voucher_key'));
        $this->assertTrue(Schema::hasIndex('subscription_transactions', 'subscription_transactions_cash_voucher_unique'));
    }

    public function test_without_duplicates_nothing_is_changed(): void
    {
        $this->payment($this->first, '-100.00', '500');
        $this->payment($this->second, '-100.00', '501');
        $before = SubscriptionTransaction::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->assertSame('', $this->runCleanup());

        $this->assertSame($before, SubscriptionTransaction::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(0, TransactionDeletion::query()->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/voucher-cleanup'));
    }

    private function runCleanup(): string
    {
        ob_start();
        try {
            $this->cleanup->up();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function assertNoOrphans(): void
    {
        foreach ([['cash_box_payments', 'subscription_transaction_id'], ['closing_payments', 'subscription_transaction_id'], ['transaction_amendments', 'transaction_id'], ['subscription_transactions', 'reverses_id'], ['subscription_transactions', 'corrects_id'], ['subscription_transactions', 'reference_transaction_id']] as [$table, $column]) {
            $this->assertSame(0, DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table('subscription_transactions')->select('id'))->count(), "{$table}.{$column}");
        }
    }

    private function payment(Subscription $subscription, string $amount, ?string $voucher, array $attributes = []): SubscriptionTransaction
    {
        return $this->line($subscription, ['type' => 'payment', 'payment_method' => 'cash', 'amount' => $amount, 'manual_voucher_number' => $voucher, ...$attributes]);
    }

    private function line(Subscription $subscription, array $attributes): SubscriptionTransaction
    {
        return SubscriptionTransaction::factory()->for($subscription)->create(['source_key' => 'test:'.Str::ulid(), ...$attributes]);
    }
}
