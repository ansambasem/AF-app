<?php

namespace App\Models;

use Database\Factories\BankFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'logo_path', 'is_receiver', 'is_sender', 'is_active'])]
class Bank extends Model
{
    /** @use HasFactory<BankFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_receiver' => 'boolean', 'is_sender' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return list<string> */
    public static function receiverNames(): array
    {
        return static::query()->where('is_active', true)->where('is_receiver', true)->orderBy('id')->pluck('name')->all();
    }

    /** @return list<string> */
    public static function senderNames(): array
    {
        return static::query()->where('is_active', true)->where('is_sender', true)->orderBy('id')->pluck('name')->all();
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return str_starts_with($this->logo_path, '/images/') ? $this->logo_path : Storage::disk('public')->url($this->logo_path);
    }
}
