<?php

namespace App\Support\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends messages through any SMS provider with a plain HTTP API: the
 * address, the method, and the names of its "to", "message" and "sender"
 * fields come from the dashboard's SMS settings (SmsSetting) or
 * `services.sms.http`, with the account's user name and password under the
 * provider's own field names, a bearer token, and any fixed fields it
 * needs (an API key, a message type). A reply that isn't 2xx — or, when
 * `success_match` is set, doesn't contain that text — counts as refused.
 * No connection, a 429 or a 5xx is a temporary refusal, worth trying again.
 */
class HttpSmsGateway implements SmsGateway
{
    /**
     * @param  array{url: ?string, method: string, token: ?string, username_param?: ?string, username?: ?string, password_param?: ?string, password?: ?string, to_param: string, message_param: string, sender_param: ?string, sender: ?string, extra_params: ?string, success_match: ?string, phone_format: string}  $config
     */
    public function __construct(private array $config, private string $countryCode) {}

    public function send(string $phone, string $body): void
    {
        if (blank($this->config['url'])) {
            throw new SmsDeliveryFailed('لم يُضبط رابط إرسال بوابة الرسائل.');
        }

        parse_str((string) $this->config['extra_params'], $extra);

        $fields = [
            ...$extra,
            $this->config['to_param'] => $this->config['phone_format'] === 'local'
                ? PhoneNumber::digits($phone)
                : PhoneNumber::international($phone, $this->countryCode),
            $this->config['message_param'] => $body,
        ];

        if (filled($this->config['sender']) && filled($this->config['sender_param'])) {
            $fields[$this->config['sender_param']] = $this->config['sender'];
        }

        foreach (['username', 'password'] as $credential) {
            if (filled($this->config[$credential.'_param'] ?? null) && filled($this->config[$credential] ?? null)) {
                $fields[$this->config[$credential.'_param']] = $this->config[$credential];
            }
        }

        $request = Http::timeout(20)->acceptJson();

        if (filled($this->config['token'])) {
            $request = $request->withToken($this->config['token']);
        }

        try {
            $response = strtolower($this->config['method']) === 'get'
                ? $request->get($this->config['url'], $fields)
                : $request->asForm()->post($this->config['url'], $fields);
        } catch (ConnectionException $exception) {
            throw new SmsDeliveryFailed('تعذّر الاتصال ببوابة الرسائل: '.$exception->getMessage(), isTemporary: true, previous: $exception);
        }

        $successMatch = $this->config['success_match'];

        if (! $response->successful() || (filled($successMatch) && ! str_contains($response->body(), $successMatch))) {
            throw new SmsDeliveryFailed(
                'رفضت بوابة الرسائل الإرسال ('.$response->status().'): '.Str::limit($response->body(), 150),
                isTemporary: $response->tooManyRequests() || $response->serverError(),
            );
        }
    }

    public function deliversMessages(): bool
    {
        return true;
    }
}
