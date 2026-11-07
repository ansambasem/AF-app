<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_boxes', function (Blueprint $table): void {
            $table->foreignId('received_in_closing_id')->nullable()->after('received_at')->constrained('closings')->restrictOnDelete();
        });
        DB::table('cash_boxes')->where('status', 'received')->update(['received_in_closing_id' => DB::raw('closing_id')]);
        DB::table('closing_settings')->update(['frequency' => 'weekly', 'arrangement' => 'combined']);
        DB::table('closing_setting_changes')->whereNull('applied_at')->lazyById()->each(function (object $change): void {
            $settings = [...json_decode($change->settings, true, flags: JSON_THROW_ON_ERROR), 'frequency' => 'weekly', 'arrangement' => 'combined'];
            unset($settings['continuation']);
            DB::table('closing_setting_changes')->where('id', $change->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_boxes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_in_closing_id');
        });
    }
};
