<?php

namespace App\Http\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The subscriptions list's search and filters, shared by the list itself and
 * by its bulk actions, so "every matching subscription" means exactly the
 * rows the list shows. Needs FiltersDataTable.
 */
trait FiltersSubscriptionList
{
    /** Sortable columns of the subscriptions list. */
    private const SUBSCRIPTION_SORTABLE = ['account_number', 'full_name', 'display_name', 'status', 'created_at'];

    /**
     * Apply the list's search, sort and filters to a subscriptions query,
     * plus `?filter[ids]=1,2,3` to keep only the given subscriptions (a
     * printout of the selected ones).
     */
    protected function applySubscriptionListFilters(Builder $query, Request $request, bool $includeArchived = false): Builder
    {
        if (! $includeArchived) {
            $query->whereNull('subscriptions.archived_at');
        }

        $subscriberNumber = DB::raw('(select subscriber_number from subscriber_profiles where subscriber_profiles.id = subscriptions.subscriber_profile_id)');
        $this->applyDataTableFilters($query, $request, ['full_name', 'subscription_name', 'phone', 'subscription_phone', 'account_number', 'legacy_number', $subscriberNumber], self::SUBSCRIPTION_SORTABLE, 'display_name');
        $this->applyDataTableFilterSelects($query, $request, ['status', 'branch_id', 'tariff_id', 'tariff_segment_id', 'meter_box_id']);
        $this->applyMeterBoxNameFilter($query, $request);
        $this->applySubAreaFilter($query, $request);
        $this->applyCircuitBreakerFilter($query, $request);
        $this->applyMinimumChargeFilter($query, $request);
        $this->applyBalanceFilter($query, $request);

        $ids = ((array) $request->input('filter', []))['ids'] ?? null;

        if (is_string($ids) && $ids !== '') {
            $query->whereIn('subscriptions.id', array_map('intval', explode(',', $ids)));
        }

        return $query;
    }

    /** Filter the ledger's net balance, using positive amounts on the chosen side. */
    protected function applyBalanceFilter(Builder $query, Request $request): Builder
    {
        $filters = (array) $request->input('filter', []);
        $keys = ['balance_side', 'balance_operator', 'balance_amount', 'balance_to'];

        if (collect($keys)->every(fn (string $key): bool => ($filters[$key] ?? '') === '' || ($filters[$key] ?? null) === null)) {
            return $query;
        }

        $validated = Validator::make(['filter' => $filters], [
            'filter.balance_side' => ['required', Rule::in(['debit', 'credit'])],
            'filter.balance_operator' => ['required', Rule::in(['gt', 'lt', 'eq', 'between'])],
            'filter.balance_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'filter.balance_to' => ['required_if:filter.balance_operator,between', 'nullable', 'numeric', 'decimal:0,2', 'gte:filter.balance_amount', 'max:9999999999'],
        ], [], [
            'filter.balance_side' => 'جهة الرصيد',
            'filter.balance_operator' => 'شرط الرصيد',
            'filter.balance_amount' => 'مبلغ الرصيد',
            'filter.balance_to' => 'المبلغ الأعلى',
        ])->validate()['filter'];

        $balance = '(select coalesce(round(sum(amount), 2), 0) from subscription_transactions where subscription_transactions.subscription_id = subscriptions.id)';
        $amount = $validated['balance_side'] === 'credit' ? "(-{$balance})" : $balance;
        $query->whereRaw("{$amount} > 0");

        if ($validated['balance_operator'] === 'between') {
            return $query->whereRaw("{$amount} BETWEEN CAST(? AS DECIMAL(12, 2)) AND CAST(? AS DECIMAL(12, 2))", [$validated['balance_amount'], $validated['balance_to']]);
        }

        $operator = ['gt' => '>', 'lt' => '<', 'eq' => '='][$validated['balance_operator']];

        return $query->whereRaw("{$amount} {$operator} CAST(? AS DECIMAL(12, 2))", [$validated['balance_amount']]);
    }

    /**
     * Apply `?filter[sub_area_id]=…`: the subscriptions on a meter box in
     * that منطقة 2.
     */
    protected function applySubAreaFilter(Builder $query, Request $request): Builder
    {
        $value = ((array) $request->input('filter', []))['sub_area_id'] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $query->whereHas('meterBox', fn (Builder $box) => $box->where('sub_area_id', (int) $value));
        }

        return $query;
    }

    /**
     * Apply `?filter[minimum_charge]=…`: the subscriptions whose minimum
     * charge (الحد الأدنى), which follows their circuit breaker's, is that
     * amount.
     */
    protected function applyMinimumChargeFilter(Builder $query, Request $request): Builder
    {
        $value = ((array) $request->input('filter', []))['minimum_charge'] ?? null;

        if (is_string($value) && is_numeric($value)) {
            $query->where($query->qualifyColumn('minimum_charge'), $value);
        }

        return $query;
    }
}
