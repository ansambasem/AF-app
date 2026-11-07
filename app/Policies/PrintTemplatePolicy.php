<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\PrintTemplate;
use App\Models\User;

/**
 * Print templates are shared by the whole company. Anyone may print with
 * them; adding, changing and removing them takes its own permission.
 */
class PrintTemplatePolicy
{
    /**
     * The templates page.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }

    public function update(User $user, PrintTemplate $template): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }

    public function delete(User $user, PrintTemplate $template): bool
    {
        return $user->hasPermission(PermissionKey::ManagePrintTemplates);
    }
}
