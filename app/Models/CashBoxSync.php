<?php

namespace App\Models;

use Database\Factories\CashBoxSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['branch_id', 'user_id', 'synced_at', 'pending_payments'])]
class CashBoxSync extends Model
{
    /** @use HasFactory<CashBoxSyncFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['synced_at' => 'immutable_datetime', 'pending_payments' => 'integer'];
    }
}
