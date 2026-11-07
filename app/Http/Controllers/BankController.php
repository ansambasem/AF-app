<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveBankRequest;
use App\Models\Bank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class BankController extends Controller
{
    public function store(SaveBankRequest $request): RedirectResponse
    {
        return $this->save($request, new Bank);
    }

    public function update(SaveBankRequest $request, Bank $bank): RedirectResponse
    {
        return $this->save($request, $bank);
    }

    private function save(SaveBankRequest $request, Bank $bank): RedirectResponse
    {
        $oldLogo = $bank->logo_path;
        $bank->fill($request->safe()->except('logo'));
        if ($request->hasFile('logo')) {
            $bank->logo_path = $request->file('logo')->store('bank-logos', 'public');
        }
        $bank->save();
        if ($oldLogo && $oldLogo !== $bank->logo_path && str_starts_with($oldLogo, 'bank-logos/')) {
            Storage::disk('public')->delete($oldLogo);
        }

        return redirect()->route('settings.payments.index')->with('status', 'تم حفظ بيانات البنك.');
    }
}
