<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The permissions of the disconnection lists and of assigning meter boxes
     * to readers, given to every Branch Admin, who runs both for their branch.
     */
    public function up(): void
    {
        $timestamp = now();
        $permissions = [
            'subscriptions.update_disconnection_fee' => 'Edit Subscription Disconnection Fee',
            'disconnections.view' => 'View Disconnection Lists',
            'disconnections.manage' => 'Manage Disconnection Lists',
            'disconnections.warn' => 'Send Disconnection Warnings',
            'disconnections.execute' => 'Confirm Service Disconnections',
            'reading_assignments.view' => 'View Reading Assignments',
            'reading_assignments.manage' => 'Assign Meter Boxes to Readers',
            'reading_assignments.approve' => 'Approve Reading Assignments',
            'reading_assignments.print_sheets' => 'Print Paper Reading Sheets',
            'reading_assignments.review' => 'Review Field Readings',
        ];

        DB::table('permissions')->insertOrIgnore(collect($permissions)->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->values()->all());

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        $branchAdminIds = DB::table('users')->where('role', 'branch_admin')->pluck('id');

        DB::table('permission_user')->insertOrIgnore($branchAdminIds->crossJoin($permissionIds)->map(fn (array $pair): array => [
            'user_id' => $pair[0],
            'permission_id' => $pair[1],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());
    }

    /**
     * Preserve the permissions and any grants made after this migration.
     */
    public function down(): void
    {
        throw new RuntimeException('The disconnection and reading assignment permissions and their grants must be preserved; use a forward migration.');
    }
};
