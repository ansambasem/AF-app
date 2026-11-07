<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a person's personal details: who made it (and from which
 * branch), and each field's value before and after.
 *
 * `changes` is `field => ['from' => ?string, 'to' => ?string]`.
 */
#[Fillable(['subscriber_profile_id', 'user_id', 'branch_id', 'changes'])]
class SubscriberProfileChange extends Model
{
    public const UPDATED_AT = null;

    /** The personal fields as people read them. */
    public const LABELS = [
        'full_name' => 'الاسم الكامل',
        'national_id' => 'رقم الهوية',
        'phone' => 'رقم الجوال',
        'address' => 'العنوان',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SubscriberProfile::class, 'subscriber_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
