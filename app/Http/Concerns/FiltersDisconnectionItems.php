<?php

namespace App\Http\Concerns;

use App\Enums\DisconnectionItemStatus;
use App\Models\DisconnectionList;
use App\Models\DisconnectionListItem;
use App\Support\DailySeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * A disconnection list's search and filters, shared by the list's page and
 * by the preview its bulk actions start from, so "every matching item"
 * means exactly the rows the page shows. Needs FiltersDataTable.
 */
trait FiltersDisconnectionItems
{
    /** Sortable columns of a disconnection list's items. */
    private const DISCONNECTION_ITEM_SORTABLE = ['scheduled_for', 'created_at', 'status', 'warnings_count', 'extensions_count'];

    /**
     * The list's items matching the request's search and filters.
     */
    protected function filteredDisconnectionItems(DisconnectionList $list, Request $request): Builder
    {
        $filter = (array) $request->input('filter', []);
        $today = DailySeries::today()->toDateString();
        $balance = '(select coalesce(sum(amount), 0) from subscription_transactions where subscription_transactions.subscription_id = disconnection_list_items.subscription_id)';

        $query = DisconnectionListItem::query()
            ->select('disconnection_list_items.*')
            ->where('disconnection_list_id', $list->id);

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->whereHas('subscription', fn (Builder $subscription) => $subscription->matchingSearch($search));
        }

        foreach (['status', 'reason'] as $column) {
            if (filled($filter[$column] ?? null)) {
                $query->where($column, (string) $filter[$column]);
            }
        }

        match ($filter['timing'] ?? null) {
            'overdue' => $query->where('status', DisconnectionItemStatus::Scheduled)->whereDate('scheduled_for', '<', $today),
            'today' => $query->where('status', DisconnectionItemStatus::Scheduled)->whereDate('scheduled_for', $today),
            'upcoming' => $query->where('status', DisconnectionItemStatus::Scheduled)->whereDate('scheduled_for', '>', $today),
            default => null,
        };

        match ($filter['balance'] ?? null) {
            'paid' => $query->whereRaw("{$balance} <= 0"),
            'owing' => $query->whereRaw("{$balance} > 0"),
            'reduced' => $query->whereRaw("{$balance} < disconnection_list_items.balance_when_added"),
            default => null,
        };

        match ($filter['warned'] ?? null) {
            '1' => $query->where('warnings_count', '>', 0),
            '0' => $query->where('warnings_count', 0),
            default => null,
        };

        if (is_string($filter['meter_box_id'] ?? null) && ctype_digit($filter['meter_box_id'])) {
            $query->whereHas('subscription', fn (Builder $subscription) => $subscription->where('meter_box_id', (int) $filter['meter_box_id']));
        }

        if (is_string($filter['sub_area_id'] ?? null) && ctype_digit($filter['sub_area_id'])) {
            $query->whereHas('subscription.meterBox', fn (Builder $box) => $box->where('sub_area_id', (int) $filter['sub_area_id']));
        }

        $sort = (string) $request->string('sort');
        $direction = $request->string('direction')->lower()->value() === 'desc' ? 'desc' : 'asc';

        if ($sort === 'balance') {
            $query->orderByRaw("{$balance} {$direction}");
        } elseif (in_array($sort, self::DISCONNECTION_ITEM_SORTABLE, true)) {
            $query->orderBy($sort, $direction);
        } else {
            // Waiting ones first, the soonest date first.
            $query->orderByRaw('case when status = ? then 0 else 1 end', [DisconnectionItemStatus::Scheduled->value])->orderBy('scheduled_for');
        }

        return $query->orderBy('disconnection_list_items.id');
    }
}
