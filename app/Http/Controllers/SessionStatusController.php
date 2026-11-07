<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

class SessionStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
