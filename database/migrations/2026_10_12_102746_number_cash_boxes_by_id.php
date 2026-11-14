<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every cash box a short number people can read, print and quote:
     * its id, as each new box gets. Boxes were numbered "BOX-" and a ULID.
     */
    public function up(): void
    {
        DB::table('cash_boxes')->select('id')->lazyById()->each(function (object $box): void {
            DB::table('cash_boxes')->where('id', $box->id)->update(['number' => (string) $box->id]);
        });
    }

    /**
     * The ULIDs are gone; a reversed box keeps its id after the old prefix.
     */
    public function down(): void
    {
        DB::table('cash_boxes')->select('id')->lazyById()->each(function (object $box): void {
            DB::table('cash_boxes')->where('id', $box->id)->update(['number' => 'BOX-'.$box->id]);
        });
    }
};
