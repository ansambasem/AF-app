<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'token_hash', 'expires_at', 'last_used_at'])]
class MobileAccessToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function issue(User $user, bool $remember = true): string
    {
        $plainTextToken = Str::random(64);

        self::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => $remember ? now()->addDays(30) : now()->addHours(12),
        ]);

        return $plainTextToken;
    }
}
