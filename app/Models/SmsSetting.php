<?php

namespace App\Models;

use Database\Factories\SmsSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * The company's SMS provider, set up from the dashboard (one row): the
 * address and method of its HTTP API, the names of its fields, the sender
 * name, and the credentials — the password and token encrypted at rest and
 * never sent back to the page. Turned on, it is the gateway messages go
 * out through (see AppServiceProvider); turned off or missing, the one set
 * in the server's environment is used, if any.
 */
#[Fillable([
    'enabled', 'provider_name', 'url', 'method', 'username_param', 'username', 'password_param', 'password', 'token',
    'to_param', 'message_param', 'sender_param', 'sender', 'phone_format', 'country_code', 'extra_params', 'success_match',
    'last_test_at', 'last_test_ok', 'last_test_error', 'updated_by',
])]
class SmsSetting extends Model
{
    /** @use HasFactory<SmsSettingFactory> */
    use HasFactory;

    /** What a provider not set up yet starts from: the most common field names. */
    public const DEFAULTS = [
        'enabled' => false,
        'method' => 'post',
        'to_param' => 'to',
        'message_param' => 'message',
        'sender_param' => 'sender',
        'phone_format' => 'international',
        'country_code' => '970',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'password' => 'encrypted',
            'token' => 'encrypted',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * The company's settings, or unsaved defaults before any were saved.
     */
    public static function current(): self
    {
        return self::query()->first() ?? new self(self::DEFAULTS);
    }

    /**
     * The settings messages go out through: saved, turned on and with an
     * address. Null otherwise — or before the table exists (a fresh
     * install still migrating), when nothing can be sent from here yet.
     */
    public static function active(): ?self
    {
        try {
            return self::query()->where('enabled', true)->whereNotNull('url')->first();
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * The country code phone numbers are completed with: the dashboard's
     * while it is on, else the server's.
     */
    public static function countryCode(): string
    {
        return self::active()?->country_code ?? (string) config('services.sms.country_code');
    }

    /**
     * The settings as HttpSmsGateway reads them.
     *
     * @return array{url: ?string, method: string, token: ?string, username_param: ?string, username: ?string, password_param: ?string, password: ?string, to_param: string, message_param: string, sender_param: ?string, sender: ?string, extra_params: ?string, success_match: ?string, phone_format: string}
     */
    public function gatewayConfig(): array
    {
        return [
            'url' => $this->url,
            'method' => $this->method,
            'token' => $this->token,
            'username_param' => $this->username_param,
            'username' => $this->username,
            'password_param' => $this->password_param,
            'password' => $this->password,
            'to_param' => $this->to_param,
            'message_param' => $this->message_param,
            'sender_param' => $this->sender_param,
            'sender' => $this->sender,
            'extra_params' => $this->extra_params,
            'success_match' => $this->success_match,
            'phone_format' => $this->phone_format,
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
