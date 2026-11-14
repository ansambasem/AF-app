<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Boxes hold shekels only: a payment in dollars or dinars counts at its
     * value in shekels. Entries of boxes the branch has not confirmed yet
     * take that value, and a box closed at the cutoff expects its cash in
     * shekels. Confirmed boxes keep what they were confirmed with.
     */
    public function up(): void
    {
        $editable = DB::table('cash_boxes')->whereIn('status', ['open', 'pending'])->select('id');
        $converted = [];

        DB::table('cash_box_payments')
            ->join('subscription_transactions', 'subscription_transactions.id', '=', 'cash_box_payments.subscription_transaction_id')
            ->whereIn('cash_box_payments.cash_box_id', $editable)
            ->where('cash_box_payments.currency', '!=', 'ILS')
            ->select('cash_box_payments.id', 'cash_box_payments.cash_box_id', 'subscription_transactions.amount')
            ->orderBy('cash_box_payments.id')
            ->each(function (object $entry) use (&$converted): void {
                DB::table('cash_box_payments')->where('id', $entry->id)->update([
                    'currency' => 'ILS',
                    'amount' => number_format(abs((float) $entry->amount), 2, '.', ''),
                ]);
                $converted[$entry->cash_box_id] = true;
            });

        foreach (DB::table('cash_boxes')->whereIn('id', array_keys($converted))->where('status', 'pending')->pluck('id') as $boxId) {
            $cash = DB::table('cash_box_payments')
                ->join('subscription_transactions', 'subscription_transactions.id', '=', 'cash_box_payments.subscription_transaction_id')
                ->where('cash_box_payments.cash_box_id', $boxId)
                ->where('cash_box_payments.method', 'cash')
                ->where('subscription_transactions.status', 'active')
                ->sum('cash_box_payments.amount');

            DB::table('cash_boxes')->where('id', $boxId)->update([
                'expected_counts' => json_encode(['ILS' => number_format((float) $cash, 2, '.', ''), 'USD' => '0.00', 'JOD' => '0.00']),
            ]);
        }
    }

    /**
     * The original amounts are still on each transaction, but which entries
     * were converted is not kept, so there is nothing to undo.
     */
    public function down(): void {}
};
