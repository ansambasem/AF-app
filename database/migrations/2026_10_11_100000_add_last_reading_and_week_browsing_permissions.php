<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the permissions to edit the last reading and to browse earlier
     * reading weeks, and give them to the people who had them in effect
     * before: every Branch Admin and Data Entry user who corrects readings
     * may edit the last one, and everyone who sees the reading sheet keeps
     * its earlier weeks until their admin takes them away.
     */
    public function up(): void
    {
        $timestamp = now();

        DB::table('permissions')->insertOrIgnore([
            ['key' => 'meter_readings.correct_last', 'label' => 'Edit Last Readings', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['key' => 'meter_readings.browse_weeks', 'label' => 'Browse Earlier Reading Weeks', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);

        $this->grant('meter_readings.correct_last', DB::table('users')
            ->whereIn('role', ['branch_admin', 'data_entry'])
            ->whereIn('id', $this->holdersOf(['meter_readings.correct']))
            ->pluck('id')
            ->all());

        $this->grant('meter_readings.browse_weeks', $this->holdersOf(['meter_readings.view', 'meter_readings.record', 'meter_readings.approve']));
    }

    /**
     * Preserve permission definitions and any grants made after this migration.
     */
    public function down(): void
    {
        throw new RuntimeException('Reading permissions and their grants must be preserved; use a forward migration.');
    }

    /**
     * The ids of the users holding any of the given permissions.
     *
     * @param  array<int, string>  $keys
     * @return array<int, int>
     */
    private function holdersOf(array $keys): array
    {
        return DB::table('permission_user')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->whereIn('permissions.key', $keys)
            ->distinct()
            ->pluck('permission_user.user_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function grant(string $key, array $userIds): void
    {
        $permissionId = DB::table('permissions')->where('key', $key)->value('id');
        $timestamp = now();

        DB::table('permission_user')->insertOrIgnore(array_map(fn (int $userId): array => [
            'permission_id' => $permissionId,
            'user_id' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $userIds));
    }
};
