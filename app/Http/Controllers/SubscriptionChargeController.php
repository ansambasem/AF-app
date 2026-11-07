<?php

namespace App\Http\Controllers;

use App\Enums\ChargeType;
use App\Http\Requests\StoreSubscriptionChargeRequest;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;

class SubscriptionChargeController extends Controller
{
    /**
     * Record a manual charge, raising the subscription's balance straight away.
     */
    public function store(StoreSubscriptionChargeRequest $request, Subscription $subscription): RedirectResponse
    {
        $type = ChargeType::from($request->validated('type'));
        $charge = SubscriptionTransaction::recordCharge($subscription, $request->user(), $type, $request->validated('amount'), $request->validated('notes'));

        $request->user()->notify(new ActionCompleted(
            'charge-recorded',
            sprintf('%s — %s %s شيكل', $subscription->displayName(), __($type->label()), SubscriptionTransaction::formatAmount($charge->amount)),
        ));

        return back()->with('status', 'charge-recorded');
    }
}
