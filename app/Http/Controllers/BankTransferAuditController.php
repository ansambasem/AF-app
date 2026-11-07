<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBankTransferAuditRequest;
use App\Models\CashBoxPayment;
use App\Support\BankTransferAuditing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BankTransferAuditController extends Controller
{
    public function show(Request $request, CashBoxPayment $entry, BankTransferAuditing $auditing): JsonResponse
    {
        return response()->json(['split' => $auditing->details($entry, $request->user())]);
    }

    public function store(StoreBankTransferAuditRequest $request, CashBoxPayment $entry, BankTransferAuditing $auditing): RedirectResponse
    {
        $auditing->record($entry, $request->user(), (string) $request->validated('bank_amount'), $request->validated('reason'), $request->validated('parts', []), $request->validated('revision'));

        return back()->with('status', 'تم حفظ مراجعة الحوالة؛ يثبت أثرها المالي عند اعتماد الصندوق.');
    }
}
