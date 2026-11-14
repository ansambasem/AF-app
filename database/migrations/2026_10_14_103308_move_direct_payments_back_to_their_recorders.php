<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * There are no direct payments: every transfer sits in the box of the
     * person who recorded it. Each entry of a direct payments box goes back
     * to its recorder: into their open box of the same type while the
     * direct box is still open, else into a box of theirs made like the
     * direct box (same week, status and dates). The emptied direct box is
     * removed, and an audit note or debt on it follows its first entry.
     */
    public function up(): void
    {
        foreach (DB::table('cash_boxes')->where('kind', 'direct')->orderBy('id')->get() as $direct) {
            $entries = DB::table('cash_box_payments')
                ->join('subscription_transactions', 'subscription_transactions.id', '=', 'cash_box_payments.subscription_transaction_id')
                ->where('cash_box_payments.cash_box_id', $direct->id)
                ->orderBy('cash_box_payments.id')
                ->get(['cash_box_payments.id', 'subscription_transactions.recorded_by']);
            $firstTarget = null;

            foreach ($entries->groupBy('recorded_by') as $userId => $ofUser) {
                $target = $this->targetBox($direct, (int) $userId);
                $firstTarget ??= $target;
                DB::table('cash_box_payments')->whereIn('id', $ofUser->pluck('id'))->update(['cash_box_id' => $target]);
            }

            if ($firstTarget !== null) {
                DB::table('audit_notes')->where('cash_box_id', $direct->id)->update(['cash_box_id' => $firstTarget]);
                DB::table('collector_debts')->where('cash_box_id', $direct->id)->update(['cash_box_id' => $firstTarget]);
            } else {
                DB::table('audit_notes')->where('cash_box_id', $direct->id)->update(['cash_box_id' => null]);
            }

            if (! DB::table('collector_debts')->where('cash_box_id', $direct->id)->exists()) {
                DB::table('cash_boxes')->where('id', $direct->id)->delete();
            }
        }
    }

    /**
     * The recorder's box an entry of the direct box goes into: their open
     * box of the type while the direct box is open, else a new box of
     * theirs numbered by its id, like the direct box in all else.
     */
    private function targetBox(object $direct, int $userId): int
    {
        $openKey = $direct->status === 'open' ? $direct->branch_id.':'.$userId.':'.$direct->type : null;
        $open = $openKey ? DB::table('cash_boxes')->where('open_key', $openKey)->value('id') : null;
        if ($open) {
            return (int) $open;
        }

        $attributes = array_diff_key((array) $direct, array_flip(['id', 'number']));
        $id = DB::table('cash_boxes')->insertGetId([
            ...$attributes,
            'number' => 'BOX-'.Str::ulid(),
            'user_id' => $userId,
            'kind' => 'staff',
            'open_key' => $openKey,
        ]);
        DB::table('cash_boxes')->where('id', $id)->update(['number' => (string) $id]);

        return $id;
    }

    /**
     * Which entries were direct payments is not kept, so there is nothing
     * to undo.
     */
    public function down(): void {}
};
