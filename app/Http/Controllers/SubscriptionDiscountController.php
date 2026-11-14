<?php

namespace App\Http\Controllers;

use App\Enums\DiscountMethod;
use App\Http\Requests\StoreSubscriptionDiscountRequest;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Notifications\ActionCompleted;
use Illuminate\Http\RedirectResponse;

class SubscriptionDiscountController extends Controller
{
    /**
     * Take a discount off what the subscription owes, by percentage,
     * kilowatts or shekels; it lowers the balance straight away.
     */
    public function store(StoreSubscriptionDiscountRequest $request, Subscription $subscription): RedirectResponse
    {
        $discount = SubscriptionTransaction::recordDiscount(
            $subscription,
            $request->user(),
            DiscountMethod::from($request->validated('method')),
            $request->validated('value'),
            $request->validated('notes'),
        );

        $request->user()->notify(new ActionCompleted(
            'discount-recorded',
            sprintf('%s — %s شيكل', $subscription->displayName(), SubscriptionTransaction::formatAmount(ltrim($discount->amount, '-'))),
        ));

        return back()->with('status', 'discount-recorded');
    }
}
