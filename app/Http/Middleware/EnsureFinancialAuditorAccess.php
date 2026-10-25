<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFinancialAuditorAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->hasFinancialAuditAccess() && ($request->routeIs('payments.cash-boxes.report', 'subscriptions.statement.print', 'subscriptions.payments.receipt', 'reading-cycles.print', 'paper-sheets.index', 'print-selections.store') || ($request->boolean('print') && $request->routeIs('subscriptions.index', 'ledger.index', 'transaction-audit.index', 'payments.cash-boxes', 'meter-readings.index', 'paper-sheets.show')))) {
            abort_unless($user->canAuditAction(AuditAction::PrintReports), 403);
        }
        if ($user?->isAuditOnly() && $request->routeIs('dashboard')) {
            return redirect()->route('payments.cash-boxes', ['place' => 'closed']);
        }

        return $next($request);
    }
}
