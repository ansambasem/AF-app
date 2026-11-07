<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSubscriptionPersonalDetailsRequest;
use App\Models\Subscription;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Changing a subscriber's personal details — name, identity number,
 * mobile number and address — straight from the subscriptions list, without
 * opening their whole form. They belong to the person, so every
 * subscription of theirs changes with them.
 */
class SubscriptionPersonalDetailsController extends Controller
{
    public function update(UpdateSubscriptionPersonalDetailsRequest $request, Subscription $subscription): RedirectResponse
    {
        DB::transaction(function () use ($subscription, $request): void {
            $subscription->profile()->lockForUpdate()->first();
            $subscription->update($request->validated());
        });

        $request->user()->notify(new ActionCompleted('subscription-personal-details-updated', $subscription->displayName()));

        return back()->with('status', 'subscription-personal-details-updated');
    }
}
