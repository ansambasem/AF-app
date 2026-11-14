<?php

namespace App\Support;

/**
 * The ways a username and password may have been meant. A phone's Arabic
 * keyboard types its digits as ٠١٢٣٤٥٦٧٨٩ (a Persian one as ۰۱۲۳۴۵۶۷۸۹) and
 * may slip in an invisible direction mark, where a computer types 0-9 and
 * nothing more: the same password then looks the same but is other
 * characters, and is refused although it is right. Signing in tries them
 * as typed first, then as a computer keyboard would have typed them, each
 * against the stored password as it is (nothing is rehashed or changed).
 */
final class LoginCredentials
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** Zero-width characters and direction marks: invisible, never meant as part of a username or password. */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * The username and password to try, in order: as typed, then each of
     * them as a computer keyboard would have typed it, without repeats.
     *
     * @return list<array{username: string, password: string}>
     */
    public static function variants(string $username, string $password): array
    {
        $usernames = array_values(array_unique([$username, self::asTypedOnComputer($username)]));
        $passwords = array_values(array_unique([$password, self::asTypedOnComputer($password)]));
        $variants = [];

        foreach ($usernames as $name) {
            foreach ($passwords as $secret) {
                $variants[] = ['username' => $name, 'password' => $secret];
            }
        }

        return $variants;
    }

    /** The text as a computer keyboard types it: digits 0-9, no invisible marks, no spaces at its ends. */
    public static function asTypedOnComputer(string $text): string
    {
        return trim((string) preg_replace(self::INVISIBLE, '', strtr($text, self::DIGITS)));
    }
}
