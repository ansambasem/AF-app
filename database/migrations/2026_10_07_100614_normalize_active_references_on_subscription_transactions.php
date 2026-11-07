<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * References are now compared without dashes, slashes and dots as well
     * as spaces and case, so the ones already kept as "in use" are brought
     * to that form: a new FT-100 must meet the FT-100 recorded before.
     * References that were released (cancelled or deleted payments) stay so.
     */
    public function up(): void
    {
        DB::table('subscription_transactions')
            ->whereNotNull('active_reference')
            ->whereNotNull('reference_number')
            ->chunkById(500, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    $normalized = mb_strtoupper(preg_replace('/[\s\p{Pd}\/.]+/u', '', trim($transaction->reference_number)));

                    if ($normalized !== '' && $normalized !== $transaction->active_reference) {
                        DB::table('subscription_transactions')->where('id', $transaction->id)->update(['active_reference' => $normalized]);
                    }
                }
            });
    }

    /**
     * The earlier form cannot be told apart from the new one, so there is
     * nothing to put back.
     */
    public function down(): void {}
};
