<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An area's name (the «منطقة 2» rows, shown as «المنطقة») is unique within
 * its branch rather than across the company, so two branches may each have
 * an area called «المدارس». A branch's areas are those under the branch's
 * own area container (`branches.area_id` = `sub_areas.area_id`), so the
 * name becomes unique per `area_id`.
 *
 * Only the index changes: no row is read, moved or changed, and every
 * existing link (branch → container, area → container, box → area) stays
 * as it is. Names were unique everywhere, so they are unique per container
 * too and the new index always builds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_areas', function (Blueprint $table): void {
            $table->unique(['area_id', 'name']);
        });
        Schema::table('sub_areas', function (Blueprint $table): void {
            $table->dropUnique(['name']);
        });
    }

    /**
     * Back to names unique everywhere; refused by the database if two
     * branches have since given their areas the same name.
     */
    public function down(): void
    {
        // MySQL lets the (area_id, name) index stand for the area_id foreign key's; give the key its own first.
        if (! collect(Schema::getIndexes('sub_areas'))->contains(fn (array $index): bool => $index['columns'] === ['area_id'])) {
            Schema::table('sub_areas', fn (Blueprint $table) => $table->index('area_id', 'sub_areas_area_id_index'));
        }
        Schema::table('sub_areas', function (Blueprint $table): void {
            $table->unique('name');
        });
        Schema::table('sub_areas', function (Blueprint $table): void {
            $table->dropUnique(['area_id', 'name']);
        });
    }
};
