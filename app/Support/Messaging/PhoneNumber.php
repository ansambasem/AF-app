<?php

namespace App\Support\Messaging;

/**
 * A phone number as a gateway or WhatsApp wants it. Numbers are stored as
 * people type them, usually local ("0599123456").
 */
class PhoneNumber
{
    /**
     * Only the digits, with Arabic-Indic digits turned into 0-9.
     */
    public static function digits(string $phone): string
    {
        $western = strtr($phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/\D+/', '', $western) ?? '';
    }

    /**
     * The number with its country code and no leading zeros or "+", e.g.
     * "0599123456" → "970599123456". A number already written with a
     * country code ("+970…" or "00970…") keeps it.
     */
    public static function international(string $phone, string $countryCode): string
    {
        $trimmed = trim($phone);
        $digits = self::digits($trimmed);

        if (str_starts_with($trimmed, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return $countryCode.substr($digits, 1);
        }

        return str_starts_with($digits, $countryCode) ? $digits : $countryCode.$digits;
    }

    /**
     * A WhatsApp link that opens a chat with the number, the text already written.
     */
    public static function whatsAppLink(string $phone, string $body, string $countryCode): string
    {
        return 'https://wa.me/'.self::international($phone, $countryCode).'?text='.rawurlencode($body);
    }
}
