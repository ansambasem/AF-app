<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\CompanyFormTemplate;
use App\Models\User;

class CompanyFormTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(PermissionKey::PrintCompanyForms, PermissionKey::ManagePrintTemplates);
    }

    public function view(User $user, CompanyFormTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }

    public function update(User $user, CompanyFormTemplate $template): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, CompanyFormTemplate $template): bool
    {
        return $this->create($user);
    }
}
