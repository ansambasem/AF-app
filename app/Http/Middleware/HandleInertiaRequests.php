<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\ClosingSetting;
use App\Models\CompanyFormTemplate;
use App\Models\CurrencyRate;
use App\Models\DisconnectionList;
use App\Models\Governorate;
use App\Models\MessageBatch;
use App\Models\MeterBox;
use App\Models\MeterReading;
use App\Models\Permission;
use App\Models\PrintTemplate;
use App\Models\ReadingCycle;
use App\Models\ReadingEntrySetting;
use App\Models\SmsSetting;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use App\Models\UserType;
use App\Support\BranchClosingAccess;
use App\Support\BranchDomainContext;
use App\Support\FinancialReportAccess;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'branchContext' => [
                'current' => BranchDomainContext::branch()?->only(['id', 'name', 'domain']),
                'recoveryUrl' => $request->attributes->get('unavailable_branch_domain') ? BranchDomainContext::centralUrl($user ? '/dashboard' : '/login') : null,
                'enabled' => (bool) config('powercollect.branch_domains_enabled'),
            ],
            'status' => fn () => $request->session()->get('status'),
            'paymentOptions' => fn () => $user ? [
                'today' => now(config('app.business_timezone'))->toDateString(),
                'currencies' => CurrencyRate::options(),
                'banks' => Bank::query()->get()->map(fn (Bank $bank): array => ['name' => $bank->name, 'logo' => $bank->logoUrl()])->all(),
            ] : null,
            'auth' => fn () => $user ? [
                'user' => [
                    'name' => $user->name,
                    'username' => $user->username,
                    'roleLabel' => $user->isSuperAdmin() ? __($user->role->label()) : ($user->userType?->name ?? 'موظف'),
                    'branchName' => $user->branch?->name,
                ],
            ] : null,
            'can' => fn () => $user ? [
                'auditOnly' => $user->isAuditOnly(),
                'printAuditReports' => (! $user->hasFinancialAuditAccess()) || $user->canAuditAction(AuditAction::PrintReports),
                'viewBranches' => $user->can('viewAny', Branch::class),
                'viewSubscriptions' => $user->can('viewAny', Subscription::class),
                'recordPayments' => $user->can('recordAnyPayment', Subscription::class),
                'viewOwnCashBoxes' => $user->can('viewOwnCashBoxes', Subscription::class),
                'viewCashBoxes' => $user->can('viewOwnCashBoxes', Subscription::class) || BranchClosingAccess::view($user),
                'viewLedger' => $user->can('viewAny', SubscriptionTransaction::class),
                'viewBranchBoxes' => BranchClosingAccess::view($user),
                'viewBranchPerformance' => $user->can('viewBranchPerformance', SubscriptionTransaction::class),
                'viewDebtAging' => $user->can('viewDebtAging', SubscriptionTransaction::class),
                'viewTransactionAudit' => $user->can('viewTransactionAudit', SubscriptionTransaction::class),
                'viewReports' => FinancialReportAccess::view($user),
                'viewUsers' => $user->can('viewAny', User::class),
                'viewUserTypes' => $user->can('viewAny', UserType::class),
                'viewTariffs' => $user->can('viewAny', Tariff::class),
                'viewCircuitBreakers' => $user->can('viewAny', CircuitBreaker::class),
                'viewMeterBoxes' => $user->can('viewAny', MeterBox::class),
                'viewMeterReadings' => $user->can('viewAny', MeterReading::class),
                'viewMessages' => $user->can('viewAny', MessageBatch::class),
                'sendMessages' => $user->can('create', MessageBatch::class),
                'viewDisconnections' => $user->can('viewAny', DisconnectionList::class),
                'viewReadingCycles' => $user->can('viewAny', ReadingCycle::class),
                'viewGovernorates' => $user->can('viewAny', Governorate::class) || $user->can('viewAny', SubArea::class),
                'manageSettings' => $user->can('manage', Permission::class),
                'manageReadingSchedule' => $user->can('manageAny', ReadingEntrySetting::class),
                'managePrintTemplates' => $user->can('viewAny', PrintTemplate::class),
                'viewCompanyForms' => $user->can('viewAny', CompanyFormTemplate::class),
                'manageSmsSettings' => $user->can('manage', SmsSetting::class),
                'managePaymentSettings' => $user->can('manage', CurrencyRate::class) || $user->can('manageAny', ClosingSetting::class),
            ] : null,
            // A list opened for printing gets the company's print templates for it.
            'printTemplates' => fn () => $user && $request->boolean('print') ? $this->printTemplates($request) : null,
            'activity' => fn () => $user ? $this->recentActivity($user) : null,
        ];
    }

    /**
     * The print templates of the list being printed, its default first, or
     * null for a page that can't keep templates.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function printTemplates(Request $request): ?array
    {
        $page = PrintTemplate::pageForRequest($request);

        if (! array_key_exists($page, PrintTemplate::PAGES)) {
            return null;
        }

        return PrintTemplate::query()
            ->where('page', $page)
            ->with('updatedBy')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (PrintTemplate $template) => $template->toDesigner())
            ->all();
    }

    /**
     * The user's own latest saved actions for the bell in the top bar,
     * newest first, and how many of them they haven't seen yet.
     *
     * @return array{unreadCount: int, recent: array<int, array{id: string, action: string, subject: string|null, read: bool, createdAt: string}>}
     */
    private function recentActivity(User $user): array
    {
        return [
            'unreadCount' => $user->unreadNotifications()->count(),
            'recent' => $user->notifications()
                ->limit(10)
                ->get()
                ->map(fn (DatabaseNotification $notification) => [
                    'id' => $notification->id,
                    'action' => $notification->data['action'],
                    'subject' => $notification->data['subject'] ?? null,
                    'read' => $notification->read_at !== null,
                    'createdAt' => $notification->created_at->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
