<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;

class BranchViewContext
{
    public static function available(Request $request): bool
    {
        return $request->user()?->isSuperAdmin() === true && BranchDomainContext::branch() === null;
    }

    public static function sessionKey(User $user): string
    {
        return 'branch_view.'.$user->id;
    }

    public static function branch(): ?Branch
    {
        return app()->bound('request') ? request()->attributes->get('view_branch') : null;
    }

    public static function id(): ?int
    {
        return self::branch()?->id;
    }
}
