<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSubscriptionStandingDiscountRequest;
use App\Models\Subscription;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubscriptionStandingDiscountController extends Controller
{
    /**
     * Give the subscription a standing discount, or change theirs: it is
     * taken off every weekly reading recorded after it, by percentage, free
     * kilowatts or shekels off the kilo price. Readings already recorded keep
     * the discount they were recorded with.
     */
    public function update(UpdateSubscriptionStandingDiscountRequest $request, Subscription $subscription): RedirectResponse
    {
        $discount = $subscription->standingDiscount()->updateOrCreate([], [
            'method' => $request->validated('method'),
            'value' => $request->validated('value'),
            'segment' => $request->validated('segment'),
            'notes' => $request->validated('notes'),
            'granted_by' => $request->user()->id,
        ]);

        $request->user()->notify(new ActionCompleted('standing-discount-saved', $subscription->displayName().' — '.$discount->summary()));

        return back()->with('status', 'standing-discount-saved');
    }

    /**
     * Stop the subscription's standing discount for the readings recorded
     * after it. Readings already recorded keep theirs.
     */
    public function destroy(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorize('addDiscount', $subscription);

        if ($subscription->standingDiscount()->delete()) {
            $request->user()->notify(new ActionCompleted('standing-discount-stopped', $subscription->displayName()));
        }

        return back()->with('status', 'standing-discount-stopped');
    }
}
