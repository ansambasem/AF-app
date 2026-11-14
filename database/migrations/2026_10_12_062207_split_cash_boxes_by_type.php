<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_boxes', function (Blueprint $table): void {
            $table->string('type')->default('cash')->after('kind');
            $table->timestamp('collector_submitted_at')->nullable()->after('closed_at');
            $table->index(['branch_id', 'user_id', 'type', 'status']);
        });

        $lastId = (int) DB::table('cash_boxes')->max('id');

        DB::table('cash_boxes')->where('id', '<=', $lastId)->lazyById()->each(function (object $box): void {
            $methods = DB::table('cash_box_payments')->where('cash_box_id', $box->id)->distinct()->pluck('method');
            $hasCash = $methods->contains('cash');
            $hasOther = $methods->contains(fn (string $method): bool => $method !== 'cash');
            $key = fn (string $type): ?string => $box->open_key ? $box->open_key.':'.$type : null;

            if (! $hasCash && ($hasOther || $box->kind === 'direct')) {
                DB::table('cash_boxes')->where('id', $box->id)->update(['type' => 'bank_transfer', 'open_key' => $key('bank_transfer')]);

                return;
            }

            DB::table('cash_boxes')->where('id', $box->id)->update(['open_key' => $key('cash')]);

            if ($hasOther) {
                $copy = (array) $box;
                unset($copy['id']);
                $newId = DB::table('cash_boxes')->insertGetId([
                    ...$copy,
                    'number' => 'BOX-'.Str::ulid(),
                    'type' => 'bank_transfer',
                    'open_key' => $key('bank_transfer'),
                    'collector_counts' => null, 'head_counts' => null, 'expected_counts' => null, 'decisions' => null, 'reason' => null,
                ]);
                DB::table('cash_box_payments')->where('cash_box_id', $box->id)->where('method', '!=', 'cash')->update(['cash_box_id' => $newId]);
            }
        });

        DB::table('cash_boxes')->where('status', '!=', 'open')
            ->where(fn (Builder $query) => $query->where('type', '!=', 'cash')->orWhereNotNull('collector_counts'))
            ->update(['collector_submitted_at' => DB::raw('closed_at')]);

        // A received non-cash box has no physical handover to confirm.
        DB::table('cash_boxes')->where('type', '!=', 'cash')->where('status', 'received')->whereNull('confirmed_at')
            ->update(['confirmed_at' => DB::raw('received_at')]);
    }

    /**
     * Drops the new columns only; boxes that were split stay split.
     */
    public function down(): void
    {
        DB::table('cash_boxes')->where('type', '!=', 'cash')->update(['open_key' => null]);
        DB::table('cash_boxes')->whereNotNull('open_key')->update(['open_key' => DB::raw("REPLACE(open_key, ':cash', '')")]);

        Schema::table('cash_boxes', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'user_id', 'type', 'status']);
            $table->dropColumn(['type', 'collector_submitted_at']);
        });
    }
};
