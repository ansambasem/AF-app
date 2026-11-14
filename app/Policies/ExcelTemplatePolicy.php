<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\ExcelTemplate;
use App\Models\User;

/**
 * Excel templates are shared by the whole company, like print templates:
 * anyone who exports may use them; adding, changing and removing them
 * takes the permission that manages the print templates.
 */
class ExcelTemplatePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }

    public function update(User $user, ExcelTemplate $template): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }

    public function delete(User $user, ExcelTemplate $template): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }
}
