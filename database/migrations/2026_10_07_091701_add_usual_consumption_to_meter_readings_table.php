<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reading far above what the subscription usually uses is flagged
     * when it is entered: this holds that usual weekly consumption (kWh),
     * and is empty for every reading that was not flagged.
     */
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->decimal('usual_consumption', 12, 2)->nullable()->after('consumption');
        });
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->dropColumn('usual_consumption');
        });
    }
};
