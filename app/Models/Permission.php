<?php

namespace App\Models;

use App\Enums\PermissionKey;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['key', 'label'])]
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The ids of the given permissions, adding any row that's missing (the
     * list of permissions is defined by PermissionKey).
     *
     * @param  array<int, PermissionKey>  $keys
     * @return array<int, int>
     */
    public static function idsFor(array $keys): array
    {
        return array_map(
            fn (PermissionKey $key) => static::firstOrCreate(['key' => $key->value], ['label' => $key->label()])->id,
            $keys,
        );
    }
}
