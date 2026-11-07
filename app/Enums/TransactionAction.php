<?php

namespace App\Enums;

enum TransactionAction: string
{
    case Edit = 'edit';
    case EditMetadata = 'edit_metadata';
    case Delete = 'delete';
    case DeleteReversal = 'delete_reversal';
    case DeleteTree = 'delete_tree';
    case Cancel = 'cancel';
    case Refund = 'refund';

    /**
     * The canonical display order used by the transaction history.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::Edit, self::EditMetadata, self::Delete, self::DeleteReversal, self::DeleteTree, self::Cancel, self::Refund];
    }

    public function isPermanentDeletion(): bool
    {
        return in_array($this, [self::Delete, self::DeleteReversal, self::DeleteTree], true);
    }
}
