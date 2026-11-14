<?php

namespace App\Models;

use App\Support\DeletionBlocker;
use Database\Factories\UserTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class UserType extends Model
{
    /** @use HasFactory<UserTypeFactory> */
    use HasFactory;

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function deletionBlocker(): ?string
    {
        return DeletionBlocker::describe('نوع المستخدم', ['المستخدمون' => $this->users()->count()]);
    }
}
