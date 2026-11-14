<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\CashBox;
use App\Support\BankTransferAuditing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CashBoxAuditController extends Controller
{
    public function store(Request $request, CashBox $cashBox, BankTransferAuditing $auditing): RedirectResponse
    {
        abort_unless(BankTransferAuditing::allowed($request->user(), AuditAction::ApproveBoxes), 403);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $auditing->complete($cashBox, $request->user());

        return back()->with('status', 'تم اعتماد الصندوق وتثبيت نتيجة تدقيقه.');
    }
}
