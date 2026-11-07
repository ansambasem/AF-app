<?php

namespace App\Http\Middleware;

use App\Support\BranchDomainContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchDomainAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $branch = BranchDomainContext::branch();
        $actor = $request->user();
        // Staff work only from their branch's link: a session of theirs on the central site (one from before branch links were on) is ended.
        if (! $branch && $actor && config('powercollect.branch_domains_enabled') && ! $actor->canUseCentralSite()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }
        // Those who manage the branches from the central site do the branches' own work from each branch's link.
        if (! $branch && $actor && config('powercollect.branch_domains_enabled') && ! $actor->isSuperAdmin() && ! $actor->hasFinancialAuditAccess()
            && ! $request->routeIs('branch-access.*', 'login', 'logout', 'profile.*', 'password.*', 'notifications.*', 'branches.*', 'branch-domains.*', 'settings.*', 'users.*', 'user-types.*', 'governorates.*', 'areas.*', 'sub-areas.*', 'tariffs.*', 'tariff-segments.*', 'circuit-breakers.*', 'print-templates.*')) {
            if ($request->isMethod('GET')) {
                return redirect()->route('branch-access.index');
            }
            abort(403, 'افتح رابط فرع العمل لتنفيذ هذه العملية.');
        }
        if ($branch && $actor) {
            if (! $actor->is_active || ! $actor->canEnterBranch($branch)) {
                $request->attributes->set('unavailable_branch_domain', true);
                abort(403, 'لا تملك صلاحية الدخول إلى هذا الفرع.');
            }
            $request->attributes->set('branch_domain_authorized', true);
            abort_if(! $request->isMethodSafe() && $request->routeIs('settings.banks.*', 'settings.currencies.*', 'settings.sms.*', 'user-types.*', 'governorates.*', 'areas.*', 'tariffs.*', 'tariff-segments.*', 'circuit-breakers.*', 'print-templates.*'), 403, 'تعديل البيانات المشتركة للشركة متاح من اللوحة المركزية.');
            abort_if($request->routeIs('branches.*', 'branch-domains.*') || ($actor->isSuperAdmin() && $request->routeIs('settings.permissions.*')), 403, 'إدارة الفروع وصلاحيات الشركة متاحة من اللوحة المركزية.');
            // A form without a branch choice (anyone but the Super Admin) sends it empty: the domain decides the branch.
            $branchChosen = $request->filled('branch_id') || ($request->exists('branch_id') && $actor->isSuperAdmin());
            if ($branchChosen && (int) $request->input('branch_id') !== $branch->id) {
                throw ValidationException::withMessages(['branch_id' => 'اختر الفرع المطابق لرابط الموقع الحالي.']);
            }
        }

        return $next($request);
    }
}
