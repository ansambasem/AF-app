<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCurrencyRateRequest;
use App\Models\CurrencyRate;
use Illuminate\Http\RedirectResponse;

class CurrencyRateController extends Controller
{
    public function update(UpdateCurrencyRateRequest $request, CurrencyRate $currencyRate): RedirectResponse
    {
        $currencyRate->update(['rate' => $request->validated('rate'), 'updated_by' => $request->user()->id]);

        return redirect()->route('settings.payments.index')->with('status', 'تم حفظ سعر الصرف.');
    }
}
