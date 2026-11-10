<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A paper voucher number is unique within its branch, compared exactly as it
 * was written: `5` and `000005` are two vouchers, `A5` and `a5` too, and
 * another branch may use the same number.
 *
 * The unique index on `cash_voucher_key` (2026_11_09_090221) was across all
 * branches, and on MySQL compared it case-insensitively. Here the virtual
 * column is added again with a binary collation (on MySQL/MariaDB; SQLite
 * already compares exactly) and the unique index becomes (branch_id,
 * cash_voucher_key). The column is virtual, computed by the database, so no
 * row is read or changed; the new rule is looser than the old one, so the
 * data already there always satisfies it.
 */
return new class extends Migration
{
    private const KEY_EXPRESSION = "CASE WHEN type = 'payment' AND payment_method = 'cash' AND cancelled_at IS NULL AND (status IS NULL OR status = 'active') AND TRIM(manual_voucher_number) <> '' THEN TRIM(manual_voucher_number) END";

    public function up(): void
    {
        $this->rebuild(
            fn (Blueprint $table) => $table->dropUnique('subscription_transactions_cash_voucher_unique'),
            fn (Blueprint $table) => $table->unique(['branch_id', 'cash_voucher_key'], 'subscription_transactions_branch_cash_voucher_unique'),
            DB::connection()->getDriverName() === 'sqlite' ? null : 'utf8mb4_bin',
        );
    }

    public function down(): void
    {
        // MySQL lets the (branch_id, cash_voucher_key) index stand for the branch foreign key's; give the key its own first.
        $branchIndexed = collect(Schema::getIndexes('subscription_transactions'))
            ->contains(fn (array $index): bool => $index['columns'] === ['branch_id']);
        if (! $branchIndexed) {
            Schema::table('subscription_transactions', fn (Blueprint $table) => $table->index('branch_id', 'subscription_transactions_branch_id_index'));
        }

        $this->rebuild(
            fn (Blueprint $table) => $table->dropUnique('subscription_transactions_branch_cash_voucher_unique'),
            fn (Blueprint $table) => $table->unique('cash_voucher_key', 'subscription_transactions_cash_voucher_unique'),
            null,
        );
    }

    /** Drop the index, add the virtual column again with `$collation`, then add the new index. */
    private function rebuild(Closure $dropIndex, Closure $addIndex, ?string $collation): void
    {
        Schema::table('subscription_transactions', $dropIndex);
        Schema::table('subscription_transactions', fn (Blueprint $table) => $table->dropColumn('cash_voucher_key'));
        Schema::table('subscription_transactions', function (Blueprint $table) use ($collation): void {
            $column = $table->string('cash_voucher_key', 191)->nullable()->virtualAs(self::KEY_EXPRESSION);
            if ($collation !== null) {
                $column->collation($collation);
            }
        });
        Schema::table('subscription_transactions', $addIndex);
    }
};
