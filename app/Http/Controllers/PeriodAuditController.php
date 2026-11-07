<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePeriodAuditRequest;
use App\Support\BankTransferAuditing;
use Illuminate\Http\RedirectResponse;

class PeriodAuditController extends Controller
{
    public function store(StorePeriodAuditRequest $request, BankTransferAuditing $auditing): RedirectResponse
    {
        $data = $request->validated();
        $auditing->completePeriod((int) $data['branch_id'], $data['week'], $request->user(), array_map(intval(...), $data['box_ids']), $data['snapshot']);

        return back()->with('status', 'تم اعتماد جميع صناديق الفترة وتثبيت فروق التدقيق.');
    }
}
