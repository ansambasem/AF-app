<?php

namespace App\Support;

use App\Models\ReadingAssignment;
use App\Models\User;

/**
 * The signed work authorisation the field app gets with each assignment it
 * downloads: which reader may read which version of which box, on which
 * day, until when. It is checked again when readings are uploaded, so a
 * reading never rests on the phone's clock or a copied assignment alone.
 * (Offline, no lock on a phone is tamper-proof; out-of-window readings are
 * held for review instead of being trusted.)
 */
class FieldReadingAuthorization
{
    /**
     * The authorisation for the assignment as it stands, for its reader.
     */
    public static function issue(ReadingAssignment $assignment): string
    {
        $payload = self::encode([
            'assignment' => $assignment->id,
            'version' => $assignment->version,
            'user' => $assignment->user_id,
            'reading_date' => $assignment->cycle->reading_date->toDateString(),
            'expires' => $assignment->cycle->syncDeadline()->getTimestamp(),
        ]);

        return $payload.'.'.self::sign($payload);
    }

    /**
     * The authorisation's content when it is genuine and was issued to the
     * reader for this assignment, or null. Its version may be older than the
     * assignment's: the caller decides what that means.
     *
     * @return array{assignment: int, version: int, user: int, reading_date: string, expires: int}|null
     */
    public static function verify(string $token, ReadingAssignment $assignment, User $reader): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if ($payload === '' || ! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($claims) || ($claims['assignment'] ?? null) !== $assignment->id || ($claims['user'] ?? null) !== $reader->id) {
            return null;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function encode(array $claims): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'field-reading|'.$payload, (string) config('app.key'));
    }
}
