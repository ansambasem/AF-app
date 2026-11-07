<?php

namespace App\Support;

use App\Models\Branch;

class BranchDomainContext
{
    public static function branch(): ?Branch
    {
        return app()->bound('request') ? request()->attributes->get('domain_branch') : null;
    }

    public static function id(): ?int
    {
        return app()->bound('request') && request()->attributes->get('branch_domain_authorized') ? self::branch()?->id : null;
    }

    public static function centralUrl(string $path = '/dashboard'): string
    {
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    public static function branchUrl(Branch $branch): ?string
    {
        if (! $branch->domain || ! $branch->domain_enabled || ! $branch->is_active) {
            return null;
        }
        $scheme = 'https';
        $port = null;
        if (app()->isLocal()) {
            $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'http';
            $port = parse_url(config('app.url'), PHP_URL_PORT);
        }

        return $scheme.'://'.$branch->domain.($port ? ':'.$port : '').'/dashboard';
    }
}
