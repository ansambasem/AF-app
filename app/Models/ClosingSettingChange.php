<?php

namespace App\Models;

use Database\Factories\ClosingSettingChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['branch_id', 'settings', 'effective_from', 'applied_at', 'user_id'])]
class ClosingSettingChange extends Model
{
    /** @use HasFactory<ClosingSettingChangeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['settings' => 'array', 'effective_from' => 'immutable_datetime', 'applied_at' => 'datetime'];
    }
}
