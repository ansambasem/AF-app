<?php

namespace App\Support;

/**
 * Why a record can't be deleted yet: the records that still use it.
 * Deleting only ever removes what nothing else points to, so history
 * (readings, account lines, who recorded what) is never lost.
 */
final class DeletionBlocker
{
    /**
     * "لا يمكن حذف الفرع لوجود سجلات مرتبطة به — المشتركون: 12 · المستخدمون: 3.",
     * or null when nothing uses the record. `$instead` suggests what to do
     * instead, for records that are rarely unused.
     *
     * @param  array<string, int>  $uses  what uses the record => how many
     */
    public static function describe(string $record, array $uses, ?string $instead = null): ?string
    {
        $inUse = array_filter($uses);

        if ($inUse === []) {
            return null;
        }

        $list = implode(' · ', array_map(fn (string $label, int $count): string => "{$label}: {$count}", array_keys($inUse), $inUse));

        return trim("لا يمكن حذف {$record} لوجود سجلات مرتبطة به — {$list}. {$instead}");
    }
}
