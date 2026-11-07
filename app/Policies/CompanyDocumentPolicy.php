<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\CompanyDocument;
use App\Models\User;

class CompanyDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::PrintCompanyForms, PermissionKey::ManagePrintTemplates);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::PrintCompanyForms);
    }

    public function view(User $user, CompanyDocument $document): bool
    {
        return $this->viewAny($user) && CompanyDocument::query()->visibleTo($user)->whereKey($document->id)->exists();
    }
}
