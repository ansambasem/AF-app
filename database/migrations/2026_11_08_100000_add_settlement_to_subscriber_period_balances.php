<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each subscription's approved period was settled, kept beside its
 * balances: the invoice target and what settled it (the advance brought
 * forward, payments, discounts), what was collected from the accumulated
 * debt, and the new advance carried forward. Periods approved before this
 * keep their balances and leave these empty: their settlement is read from
 * their lines as for any other period.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'invoice_target', 'advance_used', 'paid_to_invoice', 'discount_to_invoice', 'invoice_settled',
        'paid_to_accumulated', 'discount_to_accumulated', 'new_advance',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriber_period_balances', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 12, 2)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriber_period_balances', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
