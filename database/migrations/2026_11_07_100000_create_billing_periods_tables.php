<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing periods: each branch's weeks as its manager sets them (first and
 * last day), opened on their first day and closed when the next one opens,
 * then approved by the manager. Every account line and reading belongs to
 * one, so a period's figures are read straight from its own lines, and an
 * approved period's balances are kept as they were.
 *
 * The branches' past reading weeks become their past periods, the latest
 * one left open, so the history reads by period too. Nothing existing is
 * changed but the new period columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'starts_on']);
            $table->index(['branch_id', 'opened_at']);
        });

        Schema::create('subscriber_period_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->decimal('opening', 12, 2);
            $table->decimal('invoice', 12, 2);
            $table->decimal('discounts', 12, 2);
            $table->decimal('charges', 12, 2);
            $table->decimal('payments', 12, 2);
            $table->decimal('closing', 12, 2);
            $table->timestamps();
            $table->unique(['billing_period_id', 'subscription_id'], 'subscriber_period_balances_period_subscription_unique');
        });

        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->foreignId('billing_period_id')->nullable()->after('branch_id')->constrained()->restrictOnDelete();
            $table->index(['billing_period_id', 'subscription_id'], 'subscription_transactions_period_subscription_index');
        });

        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->foreignId('billing_period_id')->nullable()->after('branch_id')->constrained()->restrictOnDelete();
        });

        $this->backfillPastPeriods();
    }

    /**
     * Each branch's reading weeks, oldest first, become its periods: each
     * opened on its first day and closed when the next opened, the latest
     * left open. Readings join their week's period; every other line the
     * period open when it was recorded, and a weekly reading's lines their
     * reading's period. Lines from before the first period keep none: they
     * are the opening balance of the first.
     */
    private function backfillPastPeriods(): void
    {
        $timezone = config('app.business_timezone');
        $now = now();

        foreach (DB::table('meter_readings')->select('branch_id')->distinct()->pluck('branch_id') as $branchId) {
            $weeks = DB::table('meter_readings')->where('branch_id', $branchId)
                ->select('week_start', DB::raw('MAX(week_end) as week_end'))->groupBy('week_start')->orderBy('week_start')->get()->values();
            $starts = $weeks->map(fn (object $week): CarbonImmutable => CarbonImmutable::parse(substr((string) $week->week_start, 0, 10), $timezone)->utc());

            foreach ($weeks as $index => $week) {
                $periodId = DB::table('billing_periods')->insertGetId([
                    'branch_id' => $branchId,
                    'starts_on' => substr((string) $week->week_start, 0, 10),
                    'ends_on' => substr((string) $week->week_end, 0, 10),
                    'opened_at' => $starts[$index],
                    'closed_at' => $starts[$index + 1] ?? null,
                    'notes' => 'أُنشئت تلقائيًا من أسبوع القراءة السابق.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('meter_readings')->where('branch_id', $branchId)->where('week_start', $week->week_start)->update(['billing_period_id' => $periodId]);

                $lines = DB::table('subscription_transactions')->whereNull('meter_reading_id')
                    ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhere(fn ($legacy) => $legacy->whereNull('branch_id')
                        ->whereIn('subscription_id', DB::table('subscriptions')->where('branch_id', $branchId)->select('id'))))
                    ->where('created_at', '>=', $starts[$index]);
                if (isset($starts[$index + 1])) {
                    $lines->where('created_at', '<', $starts[$index + 1]);
                }
                $lines->update(['billing_period_id' => $periodId]);
            }
        }

        DB::table('subscription_transactions')->whereNotNull('meter_reading_id')->orderBy('id')->chunkById(500, function ($lines): void {
            $periods = DB::table('meter_readings')->whereIn('id', $lines->pluck('meter_reading_id')->unique())->pluck('billing_period_id', 'id');
            foreach ($lines as $line) {
                DB::table('subscription_transactions')->where('id', $line->id)->update(['billing_period_id' => $periods[$line->meter_reading_id] ?? null]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('billing_period_id');
        });

        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->dropIndex('subscription_transactions_period_subscription_index');
            $table->dropConstrainedForeignId('billing_period_id');
        });

        Schema::dropIfExists('subscriber_period_balances');
        Schema::dropIfExists('billing_periods');
    }
};
