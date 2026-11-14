<?php

namespace App\Http\Controllers;

use App\Models\MeterBox;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The meter boxes a subscription form's drop-down offers, found as the user
 * types instead of every box being sent with the page, so the form stays as
 * quick with thousands of boxes as with a few.
 */
class MeterBoxOptionController extends Controller
{
    /** The most boxes one search offers; typing narrows them down. */
    private const LIMIT = 30;

    public function index(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('viewAny', Subscription::class);

        if (! $request->expectsJson()) {
            return redirect()->route('subscriptions.index');
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
            'sub_area_id' => ['nullable', 'integer'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $boxes = MeterBox::query()
            ->visibleTo($request->user())
            ->with(['branch', 'subArea'])
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['sub_area_id'] ?? null, fn (Builder $query, int $subAreaId) => $query->where('sub_area_id', $subAreaId))
            ->when($search !== '', fn (Builder $query) => $query->matchingLabel($search)->orderByRaw('box_number = ? desc', [$search]))
            ->orderBy('box_number')
            ->limit(self::LIMIT + 1)
            ->get();

        return response()->json([
            'data' => $boxes->take(self::LIMIT)->map(fn (MeterBox $box): array => [
                'value' => (string) $box->id,
                'label' => $box->label(),
                'hint' => collect([$box->branch?->name, $box->subArea?->name])->filter()->implode(' · '),
                'sub_area_id' => $box->sub_area_id,
                'branch_id' => $box->branch_id,
            ])->values(),
            'hasMore' => $boxes->count() > self::LIMIT,
        ]);
    }
}
