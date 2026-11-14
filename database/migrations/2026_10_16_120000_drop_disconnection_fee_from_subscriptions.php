<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The disconnection fee is chosen when the disconnection is confirmed,
     * not kept on the subscriber: the subscriber's own fee goes, and the
     * permission to set it becomes the permission to change the fee when
     * confirming, keeping everyone it was granted to.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('disconnection_fee');
        });

        DB::table('permissions')->where('key', 'subscriptions.update_disconnection_fee')->update([
            'key' => 'disconnections.change_fee',
            'label' => 'Change Disconnection Fees',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'disconnections.change_fee')->update([
            'key' => 'subscriptions.update_disconnection_fee',
            'label' => 'Edit Subscription Disconnection Fee',
            'updated_at' => now(),
        ]);

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->decimal('disconnection_fee', 10, 2)->nullable()->after('kilowatt_price');
        });
    }
};
