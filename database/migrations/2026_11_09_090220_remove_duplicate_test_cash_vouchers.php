<?php

use App\Models\SubscriptionTransaction;
use App\Models\TransactionDeletion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes the duplicate paper voucher numbers left on standing cash payments
 * by the trial run, so the unique voucher index that follows
 * (2026_11_09_090221) can be built. The owner confirmed every transaction
 * of the trial is test data and asked for the duplicates to be deleted,
 * keeping one payment per voucher number.
 *
 * For each voucher number that two or more standing cash payments share,
 * the first one recorded (lowest id) is kept and the others are deleted
 * with everything that points at them: their box entry (an audit note on
 * it is unlinked by its own foreign key), their row in an old closing,
 * their amendments, and any reversal, cancellation or refund of them. A
 * correction they made of an older line is unlinked from it. Nothing else
 * is touched. Before anything is deleted:
 *  - a full backup is attempted (backup:run), and
 *  - every row to be deleted, and a whole copy of each table it touches, is
 *    written to storage/app/private/voucher-cleanup/<time>/,
 * and each deletion is kept in the audit log (transaction_deletions, shown
 * on the transaction audit page) with its reason. Each subscription's
 * running balance is recalculated afterwards. With no duplicates, nothing
 * happens. It is not reversible but by the backup.
 */
return new class extends Migration
{
    private const REASON = 'حذف دفعة تجريبية مكررة: رقم السند %s مسجّل على دفعة نقدية أخرى (رقم %d) أُبقيت. حُذفت لإنشاء فهرس منع تكرار أرقام السندات بموافقة مالك النظام.';

    public function up(): void
    {
        $groups = DB::table('subscription_transactions')
            ->selectRaw('TRIM(manual_voucher_number) as voucher, MIN(id) as kept')
            ->where('type', 'payment')->where('payment_method', 'cash')->whereNull('cancelled_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'))
            ->whereRaw("TRIM(manual_voucher_number) <> ''")
            ->groupByRaw('TRIM(manual_voucher_number)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            return;
        }

        $plan = $groups->map(fn (object $group): array => [
            'voucher' => (string) $group->voucher,
            'kept' => (int) $group->kept,
            'removed' => DB::table('subscription_transactions')
                ->where('type', 'payment')->where('payment_method', 'cash')->whereNull('cancelled_at')
                ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'))
                ->whereRaw('TRIM(manual_voucher_number) = ?', [$group->voucher])
                ->where('id', '!=', $group->kept)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ])->values()->all();

        $this->report($plan);
        $this->backUp($plan);

        foreach ($plan as $group) {
            foreach ($group['removed'] as $paymentId) {
                $this->remove($paymentId, $group['voucher'], $group['kept']);
            }
        }
    }

    public function down(): void
    {
        // The deleted payments come back only from the backup taken before.
    }

    /** @param list<array{voucher: string, kept: int, removed: list<int>}> $plan */
    private function report(array $plan): void
    {
        $removed = array_sum(array_map(fn (array $group): int => count($group['removed']), $plan));
        echo sprintf("Duplicate voucher numbers: %d; payments to delete: %d\n", count($plan), $removed);
        foreach ($plan as $group) {
            echo sprintf("  voucher %s: kept #%d, delete #%s\n", $group['voucher'], $group['kept'], implode(', #', $group['removed']));
        }
    }

    /** Every table the cleanup deletes from or changes, copied whole before anything is. */
    private const TABLES = ['subscription_transactions', 'cash_box_payments', 'closing_payments', 'transaction_amendments', 'bank_transfer_audits', 'audit_notes'];

    /**
     * A full backup when the backup command can make one; and in any case,
     * written with plain PHP (the server's PHP command line has no fileinfo,
     * which Laravel's storage disks need), every row that will be deleted and
     * a whole copy of each table the cleanup touches. Nothing is deleted
     * unless both are saved.
     *
     * @param  list<array{voucher: string, kept: int, removed: list<int>}>  $plan
     */
    private function backUp(array $plan): void
    {
        try {
            $status = Artisan::call('backup:run');
            echo 'Full backup: '.($status === 0 ? 'done' : 'failed (exit '.$status.')')."\n";
        } catch (Throwable $exception) {
            echo 'Full backup: failed ('.$exception->getMessage().")\n";
        }

        $rows = [];
        foreach ($plan as $group) {
            foreach ($group['removed'] as $paymentId) {
                $rows[] = ['voucher' => $group['voucher'], 'kept' => $group['kept'], ...$this->tree($paymentId)];
            }
        }
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        $directory = storage_path('app/private/voucher-cleanup/'.now()->format('Y-m-d_His'));
        $saved = (is_dir($directory) || mkdir($directory, 0750, true))
            && $this->save($directory.'/deleted-rows.json', $rows)
            && $this->save($directory.'/tables-before.json', $tables);
        if (! $saved) {
            throw new RuntimeException('The backup could not be saved to '.$directory.'; nothing was deleted.');
        }
        echo 'Rows to delete and the tables before the cleanup saved to '.$directory."\n";
    }

    private function save(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return file_put_contents($path, $json) === strlen($json);
    }

    /**
     * The payment and everything pointing at it, as they are now.
     *
     * @return array<string, mixed>
     */
    private function tree(int $paymentId): array
    {
        $lines = DB::table('subscription_transactions')
            ->where('id', $paymentId)
            ->orWhere('reverses_id', $paymentId)
            ->orWhere('reference_transaction_id', $paymentId)
            ->orderBy('id')->get();
        $lineIds = $lines->pluck('id')->all();
        $entries = DB::table('cash_box_payments')->whereIn('subscription_transaction_id', $lineIds)->get();

        return [
            'transactions' => $lines->map(fn (object $line): array => (array) $line)->all(),
            'cash_box_payments' => $entries->map(fn (object $entry): array => (array) $entry)->all(),
            'closing_payments' => DB::table('closing_payments')->whereIn('subscription_transaction_id', $lineIds)->get()->map(fn (object $row): array => (array) $row)->all(),
            'transaction_amendments' => DB::table('transaction_amendments')->whereIn('transaction_id', $lineIds)->get()->map(fn (object $row): array => (array) $row)->all(),
            'bank_transfer_audits' => DB::table('bank_transfer_audits')->whereIn('cash_box_payment_id', $entries->pluck('id'))->get()->map(fn (object $row): array => (array) $row)->all(),
            'audit_notes_unlinked' => DB::table('audit_notes')->whereIn('cash_box_payment_id', $entries->pluck('id'))->pluck('id')->all(),
        ];
    }

    /** Delete one duplicate payment and what points at it, keep the record, and set the subscription's running balance again. */
    private function remove(int $paymentId, string $voucher, int $keptId): void
    {
        DB::transaction(function () use ($paymentId, $voucher, $keptId): void {
            $payment = DB::table('subscription_transactions')->where('id', $paymentId)->lockForUpdate()->first();
            if ($payment === null) {
                return;
            }
            $tree = $this->tree($paymentId);
            $lineIds = array_column($tree['transactions'], 'id');
            $entryIds = array_column($tree['cash_box_payments'], 'id');

            DB::table('subscription_transactions')->whereIn('corrects_id', $lineIds)->update(['corrects_id' => null]);
            DB::table('audit_notes')->whereIn('cash_box_payment_id', $entryIds)->update(['cash_box_payment_id' => null]);
            DB::table('bank_transfer_audits')->whereIn('cash_box_payment_id', $entryIds)->delete();
            DB::table('cash_box_payments')->whereIn('id', $entryIds)->delete();
            DB::table('closing_payments')->whereIn('subscription_transaction_id', $lineIds)->delete();
            DB::table('transaction_amendments')->whereIn('transaction_id', $lineIds)->delete();
            // The reversals and refunds of the payment first, then the payment itself.
            DB::table('subscription_transactions')->whereIn('id', $lineIds)->where('id', '!=', $paymentId)->delete();
            DB::table('subscription_transactions')->where('id', $paymentId)->delete();

            DB::table('transaction_deletions')->insert([
                'subscription_id' => $payment->subscription_id,
                'branch_id' => $payment->branch_id,
                'user_id' => null,
                'action' => TransactionDeletion::ACTION_ERASE,
                'reason' => sprintf(self::REASON, $voucher, $keptId),
                'transactions' => json_encode($tree['transactions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            Closure::bind(fn (int $subscriptionId) => self::recalculateBalances($subscriptionId), null, SubscriptionTransaction::class)((int) $payment->subscription_id);
            Log::warning('Duplicate test cash payment deleted', ['payment' => $paymentId, 'voucher' => $voucher, 'kept' => $keptId, 'lines' => $lineIds]);
            echo sprintf("  deleted payment #%d (voucher %s, kept #%d)\n", $paymentId, $voucher, $keptId);
        });
    }
};
