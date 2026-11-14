<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A subscriber's own kilo price, above or below their tariff's. Empty means
     * they pay the tariff's price, which is what every existing subscription does.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->decimal('kilowatt_price', 10, 2)->nullable()->after('minimum_charge');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('kilowatt_price');
        });
    }
};
