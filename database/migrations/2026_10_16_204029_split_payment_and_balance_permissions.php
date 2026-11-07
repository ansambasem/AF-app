<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each way to be paid, and each kind of balance line, becomes its own
     * permission: taking cash and taking a bank transfer, beside "Add
     * Payments"; and adding a charge, a discount and a clearing in place of
     * "Add Charges and Discounts". Whoever held the old permission gets the
     * new ones it covered, so nobody loses anything they could do.
     */
    public function up(): void
    {
        $timestamp = now();
        $permissions = [
            'collections.record_cash' => 'Take Cash Payments',
            'collections.record_transfer' => 'Take Bank Transfer Payments',
            'collections.charge' => 'Add Charges',
            'collections.discount' => 'Add Discounts',
            'collections.clearing' => 'Add Clearings',
        ];

        DB::table('permissions')->insertOrIgnore(collect($permissions)->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->values()->all());

        $this->grantToHoldersOf('collections.record', ['collections.record_cash', 'collections.record_transfer'], $timestamp);
        $this->grantToHoldersOf('collections.adjust', ['collections.charge', 'collections.discount', 'collections.clearing'], $timestamp);

        // Its grants go with it (the pivot cascades).
        DB::table('permissions')->where('key', 'collections.adjust')->delete();
    }

    /**
     * Preserve the permissions and any grants made after this migration.
     */
    public function down(): void
    {
        throw new RuntimeException('The payment and balance permissions and their grants must be preserved; use a forward migration.');
    }

    /**
     * Give the new permissions to every user who holds the old one.
     *
     * @param  array<int, string>  $newKeys
     */
    private function grantToHoldersOf(string $oldKey, array $newKeys, DateTimeInterface $timestamp): void
    {
        $holderIds = DB::table('permission_user')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('permissions.key', $oldKey)
            ->pluck('permission_user.user_id');
        $permissionIds = DB::table('permissions')->whereIn('key', $newKeys)->pluck('id');

        DB::table('permission_user')->insertOrIgnore($holderIds->crossJoin($permissionIds)->map(fn (array $pair): array => [
            'user_id' => $pair[0],
            'permission_id' => $pair[1],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());
    }
};
