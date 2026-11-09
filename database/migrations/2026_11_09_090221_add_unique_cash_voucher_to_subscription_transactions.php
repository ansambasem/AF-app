<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A cash payment's paper voucher number (manual_voucher_number) is held by
 * one standing cash payment at most, enforced by the database itself so two
 * payments saved at the same moment cannot share it.
 *
 * `cash_voucher_key` is a virtual generated column: the trimmed paper
 * voucher of a standing cash payment, and NULL for every other line (a
 * cancelled or refunded payment, a transfer, a clearing, any other line). It
 * is computed by the database and never written, so no row is changed; the
 * unique index on it refuses a second standing cash payment with the same
 * voucher. A plain index on manual_voucher_number lets the app find any
 * payment (cancelled ones too) already holding a voucher.
 *
 * Nothing is written: before adding the index it only reads whether two
 * standing cash payments already share a voucher, and if so stops with the
 * list, changing nothing, for the duplicates to be settled first.
 */
return new class extends Migration
{
    private const KEY_EXPRESSION = "CASE WHEN type = 'payment' AND payment_method = 'cash' AND cancelled_at IS NULL AND (status IS NULL OR status = 'active') AND TRIM(manual_voucher_number) <> '' THEN TRIM(manual_voucher_number) END";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->refuseExistingDuplicates();

        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->string('cash_voucher_key', 191)->nullable()->virtualAs(self::KEY_EXPRESSION);
        });

        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->unique('cash_voucher_key', 'subscription_transactions_cash_voucher_unique');
            $table->index('manual_voucher_number', 'subscription_transactions_manual_voucher_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->dropUnique('subscription_transactions_cash_voucher_unique');
            $table->dropIndex('subscription_transactions_manual_voucher_index');
        });

        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->dropColumn('cash_voucher_key');
        });
    }

    /**
     * Stop, writing nothing, when standing cash payments already share a
     * voucher or one is too long for the key: the index could not be built.
     */
    private function refuseExistingDuplicates(): void
    {
        $duplicates = DB::table('subscription_transactions')
            ->selectRaw('TRIM(manual_voucher_number) as voucher, COUNT(*) as payments, '.$this->idList().' as ids')
            ->where('type', 'payment')->where('payment_method', 'cash')->whereNull('cancelled_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'))
            ->whereRaw("TRIM(manual_voucher_number) <> ''")
            ->groupByRaw('TRIM(manual_voucher_number)')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        $tooLong = DB::table('subscription_transactions')->whereRaw('LENGTH(TRIM(manual_voucher_number)) > 191')->pluck('id');

        if ($duplicates->isEmpty() && $tooLong->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            "Standing cash payments already share a paper voucher number; nothing was changed. Settle these first:\n"
            .$duplicates->map(fn ($row): string => "  voucher {$row->voucher}: {$row->payments} payments (ids {$row->ids})")->implode("\n")
            .($tooLong->isNotEmpty() ? "\n  vouchers longer than 191 characters on ids ".$tooLong->implode(', ') : ''),
        );
    }

    /** The ids of a group, comma separated, in this database's own words. */
    private function idList(): string
    {
        return DB::getDriverName() === 'sqlite' ? 'GROUP_CONCAT(id)' : 'GROUP_CONCAT(id ORDER BY id)';
    }
};
