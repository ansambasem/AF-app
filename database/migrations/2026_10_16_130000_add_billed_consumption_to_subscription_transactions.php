<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kWh a weekly reading's charge was billed for, kept on the line so a
     * charge cancelled by a correction still says what it charged rather than
     * what the corrected reading uses now. Standing charges take their
     * reading's consumption; a cancelled one's is no longer known.
     */
    public function up(): void
    {
        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->decimal('billed_consumption', 12, 2)->nullable()->after('discount_base');
        });

        DB::table('subscription_transactions')
            ->where('type', 'meter_reading')
            ->whereNull('cancelled_at')
            ->whereNotNull('meter_reading_id')
            ->update(['billed_consumption' => DB::raw('(select consumption from meter_readings where meter_readings.id = subscription_transactions.meter_reading_id)')]);
    }

    public function down(): void
    {
        Schema::table('subscription_transactions', function (Blueprint $table): void {
            $table->dropColumn('billed_consumption');
        });
    }
};
