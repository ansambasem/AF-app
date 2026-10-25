<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PrintSelection
{
    /** @return list<int>|null */
    public static function ids(Request $request, string $page): ?array
    {
        if ($request->input('print_scope') !== 'selected') {
            return null;
        }
        $token = $request->input('print_selection');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9-]{36}$/', $token), 419);
        $selection = Cache::get('print_selection:'.$token);
        abort_unless(
            is_array($selection)
                && ($selection['user_id'] ?? null) === $request->user()?->id
                && ($selection['page'] ?? null) === $page
                && hash_equals($selection['session_hash'] ?? '', hash('sha256', $request->session()->getId())),
            419,
        );

        return $selection['ids'];
    }
}
