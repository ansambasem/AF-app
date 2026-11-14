<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TariffCategory: string
{
    use HasOptions;

    case Residential = 'residential';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Residential',
            self::Commercial => 'Commercial',
        };
    }
}
