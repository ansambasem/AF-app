<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What else a bulk change moved on the subscription besides its field
     * (the dates an activation sets), as it was before, so an undo can put
     * those back too.
     */
    public function up(): void
    {
        Schema::table('subscription_bulk_change_items', function (Blueprint $table): void {
            $table->json('side_effects')->nullable()->after('new_value');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_bulk_change_items', function (Blueprint $table): void {
            $table->dropColumn('side_effects');
        });
    }
};
