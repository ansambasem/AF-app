<?php

namespace App\Http\Controllers;

use App\Http\Concerns\DeletesRecords;
use App\Http\Requests\StoreTariffSegmentRequest;
use App\Http\Requests\UpdateTariffSegmentRequest;
use App\Models\TariffSegment;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Customer segments are added and renamed from the Tariffs page.
 */
class TariffSegmentController extends Controller
{
    use DeletesRecords;

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTariffSegmentRequest $request): RedirectResponse
    {
        $segment = TariffSegment::create($request->validated());
        $request->user()->notify(new ActionCompleted('tariff-segment-created', $segment->label()));

        return redirect()->route('tariffs.index')->with('status', 'tariff-segment-created');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTariffSegmentRequest $request, TariffSegment $tariffSegment): RedirectResponse
    {
        $tariffSegment->update($request->validated());
        $request->user()->notify(new ActionCompleted('tariff-segment-updated', $tariffSegment->label()));

        return redirect()->route('tariffs.index')->with('status', 'tariff-segment-updated');
    }

    /**
     * Delete the customer segment, once nothing uses it any more.
     */
    public function destroy(Request $request, TariffSegment $tariffSegment): RedirectResponse
    {
        return $this->deleteRecord($request, $tariffSegment, 'tariff-segment-deleted', $tariffSegment->label());
    }
}
