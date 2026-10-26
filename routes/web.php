<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankTransferAuditController;
use App\Http\Controllers\BranchAccessController;
use App\Http\Controllers\BranchClosingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchDomainController;
use App\Http\Controllers\BranchPerformanceController;
use App\Http\Controllers\CashBoxAuditController;
use App\Http\Controllers\CashBoxReportController;
use App\Http\Controllers\CircuitBreakerController;
use App\Http\Controllers\CompanyDocumentController;
use App\Http\Controllers\CompanyFormTemplateController;
use App\Http\Controllers\CurrencyRateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisconnectionItemController;
use App\Http\Controllers\DisconnectionListController;
use App\Http\Controllers\GovernorateController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\MeterBoxController;
use App\Http\Controllers\MeterBoxOptionController;
use App\Http\Controllers\MeterBoxSubscriptionController;
use App\Http\Controllers\MeterReadingController;
use App\Http\Controllers\MobileAppDownloadController;
use App\Http\Controllers\PaperSheetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentSettingController;
use App\Http\Controllers\PeriodAuditController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PrintSelectionController;
use App\Http\Controllers\PrintTemplateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileDeviceController;
use App\Http\Controllers\ReadingAssignmentController;
use App\Http\Controllers\ReadingCycleController;
use App\Http\Controllers\ReadingPrintController;
use App\Http\Controllers\ReadingScheduleController;
use App\Http\Controllers\ReadingSubmissionController;
use App\Http\Controllers\ReadNotificationController;
use App\Http\Controllers\ReceivableController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SessionStatusController;
use App\Http\Controllers\SmsSettingController;
use App\Http\Controllers\SplitPaymentController;
use App\Http\Controllers\SubAreaController;
use App\Http\Controllers\SubscriberProfileHistoryController;
use App\Http\Controllers\SubscriptionArchiveController;
use App\Http\Controllers\SubscriptionBulkChangeController;
use App\Http\Controllers\SubscriptionChargeController;
use App\Http\Controllers\SubscriptionClearingController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionDiscountController;
use App\Http\Controllers\SubscriptionMessageController;
use App\Http\Controllers\SubscriptionPaymentController;
use App\Http\Controllers\SubscriptionPersonalDetailsController;
use App\Http\Controllers\SubscriptionPhoneController;
use App\Http\Controllers\SubscriptionReadingBaselineController;
use App\Http\Controllers\SubscriptionStandingDiscountController;
use App\Http\Controllers\SubscriptionStatementController;
use App\Http\Controllers\SubscriptionTransactionController;
use App\Http\Controllers\TariffController;
use App\Http\Controllers\TariffSegmentController;
use App\Http\Controllers\TransactionAuditController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserTypeController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request): RedirectResponse {
    return redirect()->route($request->user() ? 'dashboard' : 'login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/auth/session', SessionStatusController::class)->name('auth.session');
    Route::post('/print-selections', [PrintSelectionController::class, 'store'])->middleware('throttle:30,1')->name('print-selections.store');
    Route::get('/reading-cycles/print', [ReadingPrintController::class, 'index'])->name('reading-cycles.print');
    Route::get('/paper-sheets', [PaperSheetController::class, 'index'])->name('paper-sheets.index');
    Route::get('/settings/mobile-app', [MobileAppDownloadController::class, 'index'])->name('settings.mobile-app.index');
    Route::get('/settings/mobile-app/download', [MobileAppDownloadController::class, 'download'])->middleware('throttle:12,1')->name('settings.mobile-app.download');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/devices', [ProfileDeviceController::class, 'destroy'])->middleware('throttle:6,1')->name('profile.devices.destroy');

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/summary', [PaymentController::class, 'summary'])->name('payments.summary');
    Route::get('/payments/cash-boxes', [PaymentController::class, 'cashBoxes'])->name('payments.cash-boxes');
    Route::get('/payments/cash-boxes/report', CashBoxReportController::class)->name('payments.cash-boxes.report');
    Route::post('/payments/cash-boxes/audit-period', [PeriodAuditController::class, 'store'])->name('payments.period-audits.store');
    Route::get('/payments/cash-boxes/entries/{entry}/audit', [BankTransferAuditController::class, 'show'])->whereNumber('entry')->name('payments.bank-audits.show');
    Route::post('/payments/cash-boxes/entries/{entry}/audit', [BankTransferAuditController::class, 'store'])->whereNumber('entry')->name('payments.bank-audits.store');
    Route::post('/payments/cash-boxes/{cashBox}/audit', [CashBoxAuditController::class, 'store'])->whereNumber('cashBox')->name('payments.box-audits.store');
    Route::get('/payments/cash-boxes/entries', [PaymentController::class, 'entries'])->name('payments.cash-boxes.entries');
    Route::get('/payments/entries', [PaymentController::class, 'movedEntries'])->name('payments.entries');
    Route::get('/payments/cash-boxes/{cashBox}', [PaymentController::class, 'showCashBox'])->whereNumber('cashBox')->name('payments.cash-boxes.show');
    Route::post('/payments/audit-notes/{auditNote}/reply', [PaymentController::class, 'replyToAuditNote'])->whereNumber('auditNote')->name('payments.audit-notes.reply');
    Route::post('/payments/cash-boxes/actions/{action}/{record?}', [BranchClosingController::class, 'action'])->whereNumber('record')->name('payments.cash-boxes.actions');
    Route::get('/payments/search', [PaymentController::class, 'search'])->name('payments.search');
    Route::post('/payments/split', [SplitPaymentController::class, 'store'])->name('payments.split.store');
    Route::get('/payments/split/reference-status', [SplitPaymentController::class, 'referenceStatus'])->name('payments.split.reference-status');
    Route::get('/split-payments/{splitPayment}', [SplitPaymentController::class, 'show'])->name('split-payments.show');
    Route::get('/split-payments/{splitPayment}/edit', [SplitPaymentController::class, 'edit'])->name('split-payments.edit');
    Route::patch('/split-payments/{splitPayment}', [SplitPaymentController::class, 'update'])->name('split-payments.update');
    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::get('/receivables', [ReceivableController::class, 'index'])->name('receivables.index');
    Route::get('/transaction-audit', [TransactionAuditController::class, 'index'])->name('transaction-audit.index');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/company-forms', [CompanyFormTemplateController::class, 'index'])->name('company-forms.index');
    Route::get('/company-forms/create', [CompanyFormTemplateController::class, 'create'])->name('company-forms.create');
    Route::get('/company-forms/subscriptions', [CompanyDocumentController::class, 'subscriptions'])->name('company-forms.subscriptions');
    Route::post('/company-forms', [CompanyFormTemplateController::class, 'store'])->name('company-forms.store');
    Route::get('/company-forms/{company_form}/edit', [CompanyFormTemplateController::class, 'edit'])->name('company-forms.edit');
    Route::get('/company-forms/{company_form}/source', [CompanyFormTemplateController::class, 'source'])->name('company-forms.source');
    Route::get('/company-forms/{company_form}', [CompanyFormTemplateController::class, 'show'])->name('company-forms.show');
    Route::post('/company-forms/{company_form}', [CompanyFormTemplateController::class, 'update'])->name('company-forms.update');
    Route::delete('/company-forms/{company_form}', [CompanyFormTemplateController::class, 'destroy'])->name('company-forms.destroy');
    Route::post('/company-documents', [CompanyDocumentController::class, 'store'])->name('company-documents.store');
    Route::get('/company-documents/{company_document}/source', [CompanyDocumentController::class, 'source'])->name('company-documents.source');
    Route::get('/company-documents/{company_document}', [CompanyDocumentController::class, 'show'])->name('company-documents.show');
    Route::get('/reports/lines.csv', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/branch-performance', [BranchPerformanceController::class, 'index'])->name('branch-performance.index');
    Route::get('/branch-performance/{branch}', [BranchPerformanceController::class, 'show'])->name('branch-performance.show');

    Route::resource('branches', BranchController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::put('/branch-domains/{branch}', [BranchDomainController::class, 'update'])->name('branch-domains.update');
    Route::get('/branch-access', [BranchAccessController::class, 'index'])->name('branch-access.index');
    Route::resource('user-types', UserTypeController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('/subscriptions/bulk-changes', [SubscriptionBulkChangeController::class, 'index'])->name('subscriptions.bulk-changes.index');
    Route::get('/subscriptions/{subscription}/messages', [SubscriptionMessageController::class, 'index'])->name('subscriptions.messages.index');
    Route::post('/subscriptions/bulk-changes', [SubscriptionBulkChangeController::class, 'store'])->name('subscriptions.bulk-changes.store');
    Route::post('/subscriptions/bulk-changes/{change}/undo', [SubscriptionBulkChangeController::class, 'undo'])->name('subscriptions.bulk-changes.undo');
    Route::patch('/subscriptions/{subscription}/phone', [SubscriptionPhoneController::class, 'update'])->name('subscriptions.phone.update');
    Route::patch('/subscriptions/{subscription}/personal-details', [SubscriptionPersonalDetailsController::class, 'update'])->name('subscriptions.personal-details.update');
    Route::get('/subscriptions/{subscription}/personal-details/history', [SubscriberProfileHistoryController::class, 'show'])->name('subscriptions.personal-details.history');
    Route::post('/subscriptions/{subscription}/archive', [SubscriptionArchiveController::class, 'store'])->name('subscriptions.archive.store');
    Route::delete('/subscriptions/{subscription}/archive', [SubscriptionArchiveController::class, 'destroy'])->name('subscriptions.archive.destroy');
    Route::resource('subscriptions', SubscriptionController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('/subscriptions/statement-print', [SubscriptionStatementController::class, 'preview'])->name('subscriptions.statement.print');
    Route::get('/subscriptions/{subscription}/statement', [SubscriptionStatementController::class, 'show'])->name('subscriptions.statement');
    Route::get('/subscriptions/{subscription}/payments/reference-status', [SubscriptionPaymentController::class, 'referenceStatus'])->name('subscriptions.payments.reference-status');
    Route::post('/subscriptions/{subscription}/payments', [SubscriptionPaymentController::class, 'store'])->name('subscriptions.payments.store');
    Route::get('/subscriptions/{subscription}/payments/{transaction}/receipt', [SubscriptionPaymentController::class, 'receipt'])->scopeBindings()->name('subscriptions.payments.receipt');
    Route::post('/subscriptions/{subscription}/charges', [SubscriptionChargeController::class, 'store'])->name('subscriptions.charges.store');
    Route::post('/subscriptions/{subscription}/discounts', [SubscriptionDiscountController::class, 'store'])->name('subscriptions.discounts.store');
    Route::post('/subscriptions/{subscription}/clearings', [SubscriptionClearingController::class, 'store'])->name('subscriptions.clearings.store');
    Route::post('/subscriptions/{subscription}/transactions/{transaction}/actions', [SubscriptionTransactionController::class, 'apply'])->scopeBindings()->name('subscriptions.transactions.actions.store');
    Route::patch('/subscriptions/{subscription}/transactions/{transaction}/details', [SubscriptionTransactionController::class, 'amend'])->scopeBindings()->name('subscriptions.transactions.amend');
    Route::put('/subscriptions/{subscription}/transactions/{transaction}', [SubscriptionTransactionController::class, 'update'])->scopeBindings()->name('subscriptions.transactions.update');
    Route::delete('/subscriptions/{subscription}/transactions/{transaction}', [SubscriptionTransactionController::class, 'destroy'])->scopeBindings()->name('subscriptions.transactions.destroy');
    Route::delete('/subscriptions/{subscription}/transactions/{transaction}/permanent', [SubscriptionTransactionController::class, 'forceDestroy'])->scopeBindings()->name('subscriptions.transactions.force-destroy');
    Route::put('/subscriptions/{subscription}/standing-discount', [SubscriptionStandingDiscountController::class, 'update'])->name('subscriptions.standing-discount.update');
    Route::delete('/subscriptions/{subscription}/standing-discount', [SubscriptionStandingDiscountController::class, 'destroy'])->name('subscriptions.standing-discount.destroy');
    Route::resource('tariffs', TariffController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::resource('tariff-segments', TariffSegmentController::class)->only(['store', 'update', 'destroy']);
    Route::resource('circuit-breakers', CircuitBreakerController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::resource('meter-boxes', MeterBoxController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('/meter-boxes/options', [MeterBoxOptionController::class, 'index'])->name('meter-boxes.options');
    Route::get('/meter-boxes/{meter_box}/subscriptions', [MeterBoxSubscriptionController::class, 'index'])->name('meter-boxes.subscriptions.index');
    Route::resource('meter-readings', MeterReadingController::class)->only(['index', 'store', 'update']);
    Route::post('/meter-readings/approve', [MeterReadingController::class, 'approve'])->name('meter-readings.approve');
    Route::post('/meter-readings/{meter_reading}/last-reading-preview', [MeterReadingController::class, 'previewLastReading'])->name('meter-readings.last-reading-preview');
    Route::post('/meter-readings/{meter_reading}/adjustment-preview', [MeterReadingController::class, 'previewAdjustment'])->name('meter-readings.adjustment-preview');
    Route::put('/meter-readings/{meter_reading}/last-reading', [MeterReadingController::class, 'adjustLastReading'])->name('meter-readings.adjust-last');
    Route::post('/subscriptions/{subscription}/reading-baseline/preview', [SubscriptionReadingBaselineController::class, 'preview'])->name('subscriptions.reading-baseline.preview');
    Route::put('/subscriptions/{subscription}/reading-baseline', [SubscriptionReadingBaselineController::class, 'update'])->name('subscriptions.reading-baseline.update');
    Route::put('/meter-readings/{meter_reading}/pricing', [MeterReadingController::class, 'updatePricing'])->name('meter-readings.pricing');
    Route::resource('areas', AreaController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
    Route::resource('sub-areas', SubAreaController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
    Route::resource('governorates', GovernorateController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);

    Route::get('/settings/permissions', [PermissionController::class, 'edit'])->name('settings.permissions.edit');
    Route::put('/settings/permissions', [PermissionController::class, 'update'])->name('settings.permissions.update');
    Route::get('/settings/print-templates', [PrintTemplateController::class, 'index'])->name('settings.print-templates.index');
    Route::post('/print-templates', [PrintTemplateController::class, 'store'])->name('print-templates.store');
    Route::put('/print-templates/{print_template}', [PrintTemplateController::class, 'update'])->name('print-templates.update');
    Route::post('/print-templates/{print_template}/duplicate', [PrintTemplateController::class, 'duplicate'])->name('print-templates.duplicate');
    Route::delete('/print-templates/{print_template}', [PrintTemplateController::class, 'destroy'])->name('print-templates.destroy');
    Route::get('/settings/payments', [PaymentSettingController::class, 'index'])->name('settings.payments.index');
    Route::put('/settings/payments', [PaymentSettingController::class, 'update'])->name('settings.payments.update');
    Route::redirect('/settings/currencies', '/settings/payments')->name('settings.currencies.index');
    Route::put('/settings/currencies/{currencyRate}', [CurrencyRateController::class, 'update'])->name('settings.currencies.update');
    Route::redirect('/settings/banks', '/settings/payments')->name('settings.banks.index');
    Route::post('/settings/banks', [BankController::class, 'store'])->name('settings.banks.store');
    Route::put('/settings/banks/{bank}', [BankController::class, 'update'])->name('settings.banks.update');
    Route::get('/settings/sms', [SmsSettingController::class, 'edit'])->name('settings.sms.edit');
    Route::put('/settings/sms', [SmsSettingController::class, 'update'])->name('settings.sms.update');
    Route::post('/settings/sms/test', [SmsSettingController::class, 'test'])->middleware('throttle:6,1')->name('settings.sms.test');
    Route::get('/settings/reading-schedule', [ReadingScheduleController::class, 'edit'])->name('settings.reading-schedule.edit');
    Route::put('/settings/reading-schedule', [ReadingScheduleController::class, 'update'])->name('settings.reading-schedule.update');

    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/create', [MessageController::class, 'create'])->name('messages.create');
    Route::get('/messages/quick', [MessageController::class, 'quick'])->name('messages.quick');
    Route::get('/messages/recipients', [MessageController::class, 'recipients'])->name('messages.recipients');
    Route::post('/messages/recipients', [MessageController::class, 'recipients'])->name('messages.recipients.preview');
    Route::post('/messages', [MessageController::class, 'store'])->middleware('throttle:20,1')->name('messages.store');
    Route::get('/messages/{batch}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/messages/{batch}/retry', [MessageController::class, 'retry'])->name('messages.retry');
    Route::put('/messages/{batch}/messages/{message}/sent', [MessageController::class, 'markSent'])->scopeBindings()->name('messages.sent');
    Route::resource('message-templates', MessageTemplateController::class)->only(['store', 'update', 'destroy']);

    Route::get('/disconnection-lists', [DisconnectionListController::class, 'index'])->name('disconnection-lists.index');
    Route::post('/disconnection-lists', [DisconnectionListController::class, 'store'])->name('disconnection-lists.store');
    Route::get('/disconnection-lists/{disconnectionList}', [DisconnectionListController::class, 'show'])->whereNumber('disconnectionList')->name('disconnection-lists.show');
    Route::patch('/disconnection-lists/{disconnectionList}', [DisconnectionListController::class, 'update'])->whereNumber('disconnectionList')->name('disconnection-lists.update');
    Route::get('/disconnection-lists/{disconnectionList}/candidates', [DisconnectionListController::class, 'candidates'])->whereNumber('disconnectionList')->name('disconnection-lists.candidates');
    Route::post('/disconnection-lists/{disconnectionList}/items', [DisconnectionItemController::class, 'store'])->whereNumber('disconnectionList')->name('disconnection-lists.items.store');
    Route::post('/disconnection-lists/{disconnectionList}/items/preview', [DisconnectionItemController::class, 'preview'])->whereNumber('disconnectionList')->name('disconnection-lists.items.preview');
    Route::post('/disconnection-lists/{disconnectionList}/items/reschedule', [DisconnectionItemController::class, 'reschedule'])->whereNumber('disconnectionList')->name('disconnection-lists.items.reschedule');
    Route::post('/disconnection-lists/{disconnectionList}/items/remove', [DisconnectionItemController::class, 'remove'])->whereNumber('disconnectionList')->name('disconnection-lists.items.remove');
    Route::post('/disconnection-lists/{disconnectionList}/items/warn', [DisconnectionItemController::class, 'warn'])->whereNumber('disconnectionList')->middleware('throttle:20,1')->name('disconnection-lists.items.warn');
    Route::post('/disconnection-lists/{disconnectionList}/items/confirm', [DisconnectionItemController::class, 'confirm'])->whereNumber('disconnectionList')->middleware('throttle:20,1')->name('disconnection-lists.items.confirm');

    Route::get('/reading-cycles', [ReadingCycleController::class, 'index'])->name('reading-cycles.index');
    Route::post('/reading-cycles', [ReadingCycleController::class, 'store'])->name('reading-cycles.store');
    Route::post('/reading-cycles/prepare', [ReadingCycleController::class, 'prepare'])->name('reading-cycles.prepare');
    Route::get('/reading-cycles/{readingCycle}', [ReadingCycleController::class, 'show'])->whereNumber('readingCycle')->name('reading-cycles.show');
    Route::patch('/reading-cycles/{readingCycle}', [ReadingCycleController::class, 'update'])->whereNumber('readingCycle')->name('reading-cycles.update');
    Route::post('/reading-cycles/{readingCycle}/copy-previous', [ReadingCycleController::class, 'copyPrevious'])->whereNumber('readingCycle')->name('reading-cycles.copy-previous');
    Route::post('/reading-cycles/{readingCycle}/assignments', [ReadingAssignmentController::class, 'store'])->whereNumber('readingCycle')->name('reading-cycles.assignments.store');
    Route::post('/reading-cycles/{readingCycle}/assignments/approve', [ReadingAssignmentController::class, 'approve'])->whereNumber('readingCycle')->name('reading-cycles.assignments.approve');
    Route::post('/reading-cycles/{readingCycle}/paper-sheets', [PaperSheetController::class, 'store'])->whereNumber('readingCycle')->name('reading-cycles.paper-sheets.store');
    Route::get('/reading-cycles/{readingCycle}/submissions', [ReadingSubmissionController::class, 'index'])->whereNumber('readingCycle')->name('reading-cycles.submissions.index');
    Route::post('/reading-submissions/{readingSubmission}/review', [ReadingSubmissionController::class, 'review'])->whereNumber('readingSubmission')->name('reading-submissions.review');
    Route::get('/paper-sheets/{paperSheet}', [PaperSheetController::class, 'show'])->whereNumber('paperSheet')->name('paper-sheets.show');
    Route::post('/paper-sheets/{paperSheet}/printed', [PaperSheetController::class, 'printed'])->whereNumber('paperSheet')->name('paper-sheets.printed');

    Route::post('/notifications/read', [ReadNotificationController::class, 'store'])->name('notifications.read');
});

require __DIR__.'/auth.php';

// An address that matches nothing (outside the mobile API) is still answered inside the web group, so a signed-in
// user's 404 page knows who they are. Any method is caught, so a real address asked with a wrong one stays a 405.
Route::any('{fallbackPlaceholder}', function (Request $request) {
    $allowed = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($route) => $route->isFallback)
        ->filter(fn ($route) => $route->matches($request, false))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->values();

    abort_if($allowed->isNotEmpty(), 405, headers: ['Allow' => $allowed->implode(', ')]);
    abort(404);
})->where('fallbackPlaceholder', '(?!api(?:/|$)).*')->fallback();
