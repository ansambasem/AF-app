<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\CurrencyRate;
use App\Support\BranchDomainContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The payment settings: the currencies' exchange rates and the banks
 * payments are received at and sent from. The weekday the box weeks
 * started on is no longer set here: whether a payment may be taken
 * depends on its branch's billing period being open, nothing else.
 */
class PaymentSettingController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $actor = $request->user();
        $manageCatalog = BranchDomainContext::id() === null && $actor->can('manage', CurrencyRate::class) && $actor->can('manage', Bank::class);
        abort_unless($manageCatalog, 403);

        return Inertia::render('Settings/Payments', [
            'currencies' => CurrencyRate::query()->orderBy('id')->get()->map(fn (CurrencyRate $currencyRate): array => [
                'id' => $currencyRate->id, 'code' => $currencyRate->currency->value, 'label' => __($currencyRate->currency->label()),
                'rate' => $currencyRate->rate, 'updatedAt' => $currencyRate->updated_at,
                'updateUrl' => route('settings.currencies.update', $currencyRate),
            ])->all(),
            'banks' => Bank::query()->orderBy('name')->get()->map(fn (Bank $bank): array => [
                'id' => $bank->id, 'name' => $bank->name, 'logoUrl' => $bank->logoUrl(),
                'is_receiver' => $bank->is_receiver, 'is_sender' => $bank->is_sender, 'is_active' => $bank->is_active,
                'updateUrl' => route('settings.banks.update', $bank),
            ])->all(),
            'storeUrl' => route('settings.banks.store'),
        ]);
    }
}
