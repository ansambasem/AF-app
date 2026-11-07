<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription's figures for an approved billing period, kept as they
 * were when it was approved: the balance it opened and closed with, and
 * what was billed, discounted, charged and paid in it. The next period's
 * accumulated amount (a positive closing) or advance (a negative one)
 * starts from it.
 */
#[Fillable(['billing_period_id', 'subscription_id', 'opening', 'invoice', 'discounts', 'charges', 'payments', 'closing'])]
class SubscriberPeriodBalance extends Model
{
    protected function casts(): array
    {
        return [
            'opening' => 'decimal:2',
            'invoice' => 'decimal:2',
            'discounts' => 'decimal:2',
            'charges' => 'decimal:2',
            'payments' => 'decimal:2',
            'closing' => 'decimal:2',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class, 'billing_period_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
