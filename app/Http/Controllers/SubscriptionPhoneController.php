<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSubscriptionPhoneRequest;
use App\Models\Subscription;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Changing a subscription's mobile number straight from the subscriptions
 * list, without opening their form.
 */
class SubscriptionPhoneController extends Controller
{
    /**
     * Change the number the list shows: the subscription's own number when
     * it has one, otherwise the personal number (which every subscription
     * of the same person shares).
     */
    public function update(UpdateSubscriptionPhoneRequest $request, Subscription $subscription): RedirectResponse
    {
        $field = filled($subscription->subscription_phone) ? 'subscription_phone' : 'phone';

        DB::transaction(function () use ($subscription, $field, $request): void {
            $subscription->profile()->lockForUpdate()->first();
            $subscription->update([$field => $request->validated('phone')]);
        });

        $request->user()->notify(new ActionCompleted('subscription-phone-updated', $subscription->displayName()));

        return back()->with('status', 'subscription-phone-updated');
    }
}
