<?php

namespace App\Http\Controllers;

use App\Enums\MeterReadingStatus;
use App\Enums\TariffCategory;
use App\Http\Concerns\DeletesRecords;
use App\Http\Requests\StoreTariffRequest;
use App\Http\Requests\UpdateTariffRequest;
use App\Models\MeterReading;
use App\Models\Tariff;
use App\Models\TariffRateChange;
use App\Models\TariffSegment;
use App\Models\User;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TariffController extends Controller
{
    use DeletesRecords;

    /**
     * How many recent weeks of approved readings the average consumption
     * on each tariff card covers.
     */
    private const AVERAGE_WEEKS = 4;

    /**
     * The tariffs page: a card for each tariff with its kilo price, since
     * when and who set it, its price history, its subscriptions and their
     * average weekly consumption. Below them, the customer segments.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Tariff::class);

        $actor = $request->user();
        $tariffs = Tariff::query()
            ->withCount('subscriptions')
            ->with('rateChanges.changedBy')
            ->get()
            ->sortBy(fn (Tariff $tariff): int => array_search($tariff->category, TariffCategory::cases(), true))
            ->values();
        $averages = $this->averageWeeklyConsumption();
        $missing = array_values(array_filter(
            TariffCategory::cases(),
            fn (TariffCategory $category): bool => ! $tariffs->contains('category', $category),
        ));

        return Inertia::render('Tariffs/Index', [
            'tariffs' => $tariffs->map(fn (Tariff $tariff): array => $this->card($tariff, $actor, $averages[$tariff->id] ?? null))->all(),
            'canCreate' => $missing !== [] && $actor->can('create', Tariff::class),
            'segments' => TariffSegment::query()->withCount('subscriptions')->orderBy('name')->get()->map(fn (TariffSegment $segment): array => [
                'id' => $segment->id,
                'name' => $segment->name,
                'subscriptionsCount' => $segment->subscriptions_count,
                'canUpdate' => $actor->can('update', $segment),
                'canDelete' => $actor->can('delete', $segment),
            ])->all(),
            'canCreateSegment' => $actor->can('create', TariffSegment::class),
            // Only a category with no tariff yet can be added.
            'categoryOptions' => TariffCategory::options($missing),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): InertiaResponse
    {
        $this->authorize('create', Tariff::class);

        return Inertia::render('Tariffs/Create', [
            'categoryOptions' => TariffCategory::options(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTariffRequest $request): RedirectResponse
    {
        $tariff = Tariff::create($request->validated());
        $tariff->recordRateChange($request->user());
        $request->user()->notify(new ActionCompleted('tariff-created', __($tariff->category->label())));

        return redirect()->route('tariffs.index')->with('status', 'tariff-created');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Tariff $tariff): InertiaResponse
    {
        $this->authorize('update', $tariff);

        return Inertia::render('Tariffs/Edit', [
            'tariff' => $this->editableFields($tariff),
            'categoryOptions' => TariffCategory::options(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTariffRequest $request, Tariff $tariff): RedirectResponse
    {
        $tariff->update($request->validated());
        $tariff->recordRateChange($request->user());
        $request->user()->notify(new ActionCompleted('tariff-updated', __($tariff->category->label())));

        return redirect()->route('tariffs.index')->with('status', 'tariff-updated');
    }

    /**
     * Delete the tariff, once nothing uses it any more.
     */
    public function destroy(Request $request, Tariff $tariff): RedirectResponse
    {
        return $this->deleteRecord($request, $tariff, 'tariff-deleted', __($tariff->category->label()));
    }

    /**
     * One tariff's card on the index page.
     *
     * @return array<string, mixed>
     */
    private function card(Tariff $tariff, User $actor, ?float $averageConsumption): array
    {
        $latest = $tariff->rateChanges->first();

        return [
            ...$this->editableFields($tariff),
            'categoryLabel' => __($tariff->category->label()),
            'subscriptionsCount' => $tariff->subscriptions_count,
            'averageConsumption' => $averageConsumption === null ? null : round($averageConsumption, 1),
            'rateSince' => $latest?->created_at->locale('ar')->translatedFormat('j F Y'),
            'rateChangedBy' => $latest?->changedBy?->name,
            // Oldest first, the last few prices it has had.
            'history' => $tariff->rateChanges->take(5)->reverse()->values()->map(fn (TariffRateChange $change): array => [
                'id' => $change->id,
                'date' => $change->created_at->locale('ar')->translatedFormat('j F Y'),
                'rate' => $change->rate,
            ])->all(),
            'canUpdate' => $actor->can('update', $tariff),
            'canDelete' => $actor->can('delete', $tariff),
        ];
    }

    /**
     * The average weekly consumption, in kilos, of each tariff's approved
     * readings over the last few weeks.
     *
     * @return array<int, float> tariff id => kilos
     */
    private function averageWeeklyConsumption(): array
    {
        return MeterReading::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'meter_readings.subscription_id')
            ->where('meter_readings.status', MeterReadingStatus::Approved)
            ->where('meter_readings.week_start', '>=', now()->subWeeks(self::AVERAGE_WEEKS)->toDateString())
            ->groupBy('subscriptions.tariff_id')
            ->toBase()
            ->selectRaw('subscriptions.tariff_id, avg(meter_readings.consumption) as average')
            ->pluck('average', 'tariff_id')
            ->map(fn ($average): float => (float) $average)
            ->all();
    }

    /**
     * A tariff's editable fields — used both for the dedicated edit page
     * and for the edit modal's initial form data on the index page.
     *
     * @return array<string, mixed>
     */
    private function editableFields(Tariff $tariff): array
    {
        return [
            'id' => $tariff->id,
            'category' => $tariff->category->value,
            'rate' => $tariff->rate,
        ];
    }
}
