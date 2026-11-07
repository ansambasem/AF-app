<?php

namespace App\Models;

use App\Enums\Currency;
use Database\Factories\CurrencyRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['currency', 'rate', 'updated_by'])]
class CurrencyRate extends Model
{
    /** @use HasFactory<CurrencyRateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'rate' => 'decimal:4'];
    }

    public static function forPayment(mixed $code): ?string
    {
        $currency = is_string($code) ? Currency::tryFrom($code) : null;
        if ($currency === null) {
            return null;
        }

        return $currency === Currency::Shekel ? '1.0000' : static::query()->where('currency', $currency->value)->value('rate');
    }

    /** @return list<array{code: string, label: string, rate: ?string}> */
    public static function options(): array
    {
        $rates = static::query()->pluck('rate', 'currency');

        return collect(Currency::cases())->map(fn (Currency $currency): array => [
            'code' => $currency->value, 'label' => __($currency->label()),
            'rate' => $currency === Currency::Shekel ? '1.0000' : $rates->get($currency->value),
        ])->all();
    }
}
