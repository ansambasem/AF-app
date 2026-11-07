<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBoxPeriodSettingRequest;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\ClosingSetting;
use App\Models\CurrencyRate;
use App\Support\BranchDomainContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PaymentSettingController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('manageAny', ClosingSetting::class);
        $request->validate(['branch' => ['nullable', 'integer']]);
        $actor = $request->user();
        $branchId = $request->filled('branch') ? $request->integer('branch') : (BranchDomainContext::id() ?? ($actor->isSuperAdmin() ? null : $actor->workBranchId()));
        $branch = $branchId === null ? null : Branch::query()
            ->when(! $actor->isSuperAdmin(), fn (Builder $query) => $query->whereKey($actor->workBranchId()))
            ->findOrFail($branchId);
        $this->authorize('manage', [ClosingSetting::class, $branch]);
        $manageCatalog = BranchDomainContext::id() === null && $actor->can('manage', CurrencyRate::class) && $actor->can('manage', Bank::class);

        return Inertia::render('Settings/Payments', [
            'boxPeriod' => [
                'week_starts_on' => ClosingSetting::forBranch($branch)->week_starts_on,
                'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
                'branches' => $actor->isSuperAdmin() ? Branch::query()->orderBy('name')->get(['id', 'name'])->map(fn (Branch $item): array => ['value' => $item->id, 'label' => $item->name])->all() : [],
                'canSelectBranch' => $actor->isSuperAdmin(),
                'followsCompany' => $branch !== null && ClosingSetting::ownFor($branch) === null,
                'indexUrl' => route('settings.payments.index'), 'updateUrl' => route('settings.payments.update'),
            ],
            'canManagePaymentCatalog' => $manageCatalog,
            'currencies' => $manageCatalog ? CurrencyRate::query()->orderBy('id')->get()->map(fn (CurrencyRate $currencyRate): array => [
                'id' => $currencyRate->id, 'code' => $currencyRate->currency->value, 'label' => __($currencyRate->currency->label()),
                'rate' => $currencyRate->rate, 'updatedAt' => $currencyRate->updated_at,
                'updateUrl' => route('settings.currencies.update', $currencyRate),
            ])->all() : [],
            'banks' => $manageCatalog ? Bank::query()->orderBy('name')->get()->map(fn (Bank $bank): array => [
                'id' => $bank->id, 'name' => $bank->name, 'logoUrl' => $bank->logoUrl(),
                'is_receiver' => $bank->is_receiver, 'is_sender' => $bank->is_sender, 'is_active' => $bank->is_active,
                'updateUrl' => route('settings.banks.update', $bank),
            ])->all() : [],
            'storeUrl' => $manageCatalog ? route('settings.banks.store') : null,
        ]);
    }

    public function update(UpdateBoxPeriodSettingRequest $request): RedirectResponse
    {
        $branch = $request->branch();
        $setting = $branch === null ? ClosingSetting::company() : ClosingSetting::startFor($branch);
        $setting->update(['week_starts_on' => $request->integer('week_starts_on'), 'updated_by' => $request->user()->id]);

        return to_route('settings.payments.index', $branch ? ['branch' => $branch->id] : [])->with('status', 'تم حفظ يوم بداية فترة الصناديق.');
    }
}
