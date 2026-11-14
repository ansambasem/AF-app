<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReadNotificationController extends Controller
{
    /**
     * Mark all of the user's notifications as read, once they open the bell.
     */
    public function store(Request $request): Response
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
