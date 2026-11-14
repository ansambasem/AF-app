<?php

namespace App\Models;

use App\Enums\DiscountMethod;
use Database\Factories\StandingDiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription's standing discount (خصم القراءات الأسبوعية): an advantage taken off every
 * weekly reading recorded while it lasts — a percentage of the reading,
 * free kilowatts of its consumption, or shekels off the kilo price — given
 * to a customer segment typed with it, such as موظفو أبو زايد. Each reading
 * keeps the discount it was recorded with.
 */
#[Fillable(['subscription_id', 'method', 'value', 'segment', 'notes', 'granted_by'])]
class StandingDiscount extends Model
{
    /** @use HasFactory<StandingDiscountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => DiscountMethod::class,
            'value' => 'decimal:2',
        ];
    }

    /**
     * How a standing discount reads on a weekly reading: "10%", "3 كيلو"
     * or "5 شيكل من سعر الكيلو".
     */
    public static function termsFor(DiscountMethod $method, float|string $value): string
    {
        $value = SubscriptionTransaction::formatAmount($value);

        return match ($method) {
            DiscountMethod::Percentage => $value.'%',
            DiscountMethod::Kilowatt => $value.' كيلو',
            DiscountMethod::Shekel => $value.' شيكل من سعر الكيلو',
        };
    }

    public function terms(): string
    {
        return self::termsFor($this->method, $this->value);
    }

    /**
     * Its terms and the customer segment it was given to, as listed with
     * the subscription: "3 كيلو · موظفو أبو زايد".
     */
    public function summary(): string
    {
        return $this->segment ? $this->terms().' · '.$this->segment : $this->terms();
    }

    /**
     * The customer segments offered while typing one: those already given
     * a standing discount and the tariffs' own customer segments.
     *
     * @return array<int, string>
     */
    public static function segmentSuggestions(): array
    {
        return self::query()->whereNotNull('segment')->distinct()->pluck('segment')
            ->merge(TariffSegment::query()->distinct()->pluck('name'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
