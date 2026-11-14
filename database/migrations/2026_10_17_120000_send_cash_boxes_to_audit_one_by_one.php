<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boxes go to audit one by one, no longer in a weekly package: each box
     * keeps when it was sent, who sent it and their note. A box received
     * into a week the branch already sent counts as sent then, by whoever
     * sent the week. The new permission to send boxes goes to every Branch
     * Admin.
     */
    public function up(): void
    {
        Schema::table('cash_boxes', function (Blueprint $table): void {
            $table->timestamp('sent_at')->nullable()->after('confirmed_at');
            $table->foreignId('sent_by')->nullable()->after('sent_at')->constrained('users')->restrictOnDelete();
            $table->text('send_note')->nullable()->after('sent_by');
            $table->index(['branch_id', 'sent_at']);
        });

        $sentWeeks = DB::table('closings')->whereNotNull('workflow_settings')->whereNotNull('submitted_at')->get(['id', 'submitted_at', 'branch_approved_by']);
        foreach ($sentWeeks as $week) {
            DB::table('cash_boxes')->where('status', 'received')->whereNull('sent_at')
                ->where(fn ($query) => $query->where('received_in_closing_id', $week->id)->orWhere(fn ($legacy) => $legacy->whereNull('received_in_closing_id')->where('closing_id', $week->id)))
                ->update(['sent_at' => $week->submitted_at, 'sent_by' => $week->branch_approved_by]);
        }

        $timestamp = now();
        DB::table('permissions')->insertOrIgnore([
            ['key' => 'collections.send_boxes', 'label' => 'Send Cash Boxes to Audit', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);
        $permissionId = DB::table('permissions')->where('key', 'collections.send_boxes')->value('id');
        DB::table('permission_user')->insertOrIgnore(DB::table('users')->where('role', 'branch_admin')->pluck('id')->map(fn (int $userId): array => [
            'permission_id' => $permissionId,
            'user_id' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_boxes', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'sent_at']);
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['sent_at', 'send_note']);
        });
        $permissionId = DB::table('permissions')->where('key', 'collections.send_boxes')->value('id');
        DB::table('permission_user')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
