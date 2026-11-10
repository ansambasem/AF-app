<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The work is organised as branch → areas → meter boxes. The areas are the
 * former sub-areas (منطقة 2); the former area level (منطقة 1) was the branch
 * itself, and governorates are no longer used.
 *
 * Each area gets the one branch it belongs to (`sub_areas.branch_id`):
 *  1. the branch that sits in the area's former area (branches.area_id),
 *     when exactly one does;
 *  2. otherwise the branch of the meter boxes in it, when they all share one;
 *  3. otherwise none, and it is listed here for someone to assign.
 * An area's name is then unique within its branch rather than everywhere.
 * Nothing is deleted: the governorates and former areas, and the columns
 * pointing at them, stay as they are, unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which branch each area belongs to, read before the table changes.
        $assigned = [];
        $unassigned = [];
        foreach (DB::table('sub_areas')->orderBy('id')->get(['id', 'name', 'area_id']) as $area) {
            $byArea = $area->area_id === null ? collect() : DB::table('branches')->where('area_id', $area->area_id)->pluck('id');
            $byBoxes = DB::table('meter_boxes')->where('sub_area_id', $area->id)->pluck('branch_id')->unique()->values();
            $branchId = match (true) {
                $byArea->count() === 1 => $byArea->first(),
                $byBoxes->count() === 1 => $byBoxes->first(),
                default => null,
            };

            if ($branchId === null) {
                $unassigned[] = sprintf('#%d %s', $area->id, $area->name);
            } else {
                $assigned[(int) $branchId][] = $area->id;
            }
        }

        Schema::table('sub_areas', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('area_id');
            // SQLite would rebuild the table to add the constraint; the database the app runs on adds it in place.
            if (! $this->sqlite()) {
                $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            }
        });
        foreach ($assigned as $branchId => $areaIds) {
            DB::table('sub_areas')->whereIn('id', $areaIds)->update(['branch_id' => $branchId]);
        }

        Schema::table('sub_areas', function (Blueprint $table): void {
            $table->dropUnique(['name']);
            $table->unique(['branch_id', 'name']);
        });

        if ($unassigned !== []) {
            echo 'Areas with no single branch to belong to (assign them on the branch\'s areas page): '.implode(', ', $unassigned)."\n";
        }
        $mismatched = DB::table('meter_boxes')->join('sub_areas', 'sub_areas.id', '=', 'meter_boxes.sub_area_id')
            ->whereNotNull('sub_areas.branch_id')->whereColumn('meter_boxes.branch_id', '!=', 'sub_areas.branch_id')->pluck('meter_boxes.id');
        if ($mismatched->isNotEmpty()) {
            echo 'Meter boxes whose area belongs to another branch (left as they are): #'.$mismatched->implode(', #')."\n";
        }
    }

    public function down(): void
    {
        // The foreign key first: MySQL may let the (branch_id, name) index stand for its own.
        if (! $this->sqlite()) {
            Schema::table('sub_areas', fn (Blueprint $table) => $table->dropForeign(['branch_id']));
        }
        Schema::table('sub_areas', function (Blueprint $table): void {
            $table->dropUnique(['branch_id', 'name']);
            $table->unique('name');
        });
        Schema::table('sub_areas', fn (Blueprint $table) => $table->dropColumn('branch_id'));
    }

    private function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
