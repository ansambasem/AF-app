<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The production host's CGI handler withholds the Authorization header from
 * PHP, so the app's token may arrive as the same "Bearer <token>" value in
 * X-Mobile-Authorization. It is checked exactly like the bearer header, only
 * when that header is absent, and only by the app's API.
 */
class MobileFallbackAuthorizationHeaderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function fallback(string $token): array
    {
        return ['X-Mobile-Authorization' => 'Bearer '.$token];
    }

    public function test_the_fallback_header_signs_the_app_in_as_the_tokens_user(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson(route('mobile.login'), ['username' => $user->username, 'password' => 'password'])->assertOk()->json('token');

        $this->getJson(route('mobile.me'), $this->fallback($token))->assertOk()->assertJsonPath('user.id', $user->id);

        $this->assertNotNull(MobileAccessToken::query()->where('token_hash', hash('sha256', $token))->value('last_used_at'));
    }

    #[TestWith(['forged'])]
    #[TestWith(['expired'])]
    #[TestWith(['signed out'])]
    #[TestWith(['stopped account'])]
    public function test_the_fallback_header_is_refused_for_a_token_the_bearer_header_would_refuse(string $case): void
    {
        $user = User::factory()->create();
        $token = MobileAccessToken::issue($user);
        match ($case) {
            'forged' => $token = str_repeat('x', 64),
            'expired' => MobileAccessToken::query()->update(['expires_at' => now()->subSecond()]),
            'signed out' => $this->postJson(route('mobile.logout'), [], $this->fallback($token))->assertOk(),
            'stopped account' => $user->update(['is_active' => false]),
        };

        $this->getJson(route('mobile.me'), $this->fallback($token))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[TestWith(['Token abc'])]
    #[TestWith(['Bearer '])]
    #[TestWith(['Bearer    '])]
    public function test_a_fallback_header_without_a_bearer_token_is_refused(string $value): void
    {
        $this->getJson(route('mobile.me'), ['X-Mobile-Authorization' => $value])->assertUnauthorized();
    }

    public function test_the_bearer_header_wins_whenever_both_are_sent(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownersToken = MobileAccessToken::issue($owner);
        $othersToken = MobileAccessToken::issue($other);

        $this->getJson(route('mobile.me'), ['Authorization' => 'Bearer '.$ownersToken, ...$this->fallback($othersToken)])->assertOk()->assertJsonPath('user.id', $owner->id);
        $this->getJson(route('mobile.me'), ['Authorization' => 'Bearer '.$ownersToken, ...$this->fallback(str_repeat('x', 64))])->assertOk()->assertJsonPath('user.id', $owner->id);
        // A refused bearer token is not rescued by a valid fallback one.
        $this->getJson(route('mobile.me'), ['Authorization' => 'Bearer '.str_repeat('x', 64), ...$this->fallback($ownersToken)])->assertUnauthorized();
    }

    public function test_the_device_limit_still_signs_the_older_phone_out(): void
    {
        $user = User::factory()->create(['max_devices' => 1]);
        $credentials = ['username' => $user->username, 'password' => 'password'];
        $olderPhone = $this->postJson(route('mobile.login'), $credentials)->json('token');
        $newerPhone = $this->postJson(route('mobile.login'), $credentials)->json('token');

        $this->getJson(route('mobile.me'), $this->fallback($olderPhone))->assertUnauthorized();
        $this->getJson(route('mobile.me'), $this->fallback($newerPhone))->assertOk();
    }

    public function test_signing_in_again_with_the_fallback_header_keeps_the_phones_token(): void
    {
        $user = User::factory()->create();
        $credentials = ['username' => $user->username, 'password' => 'password'];
        $token = $this->postJson(route('mobile.login'), $credentials)->json('token');

        $this->postJson(route('mobile.login'), $credentials, $this->fallback($token))->assertOk()->assertJsonPath('token', $token);
        $this->assertSame(1, MobileAccessToken::query()->where('user_id', $user->id)->count());
    }

    public function test_the_fallback_header_keeps_the_users_permissions_and_opens_no_website_page(): void
    {
        $collector = User::factory()->collector()->create();
        $collector->permissions()->detach();
        $token = MobileAccessToken::issue($collector);

        // The website first: the app's middleware signs its user in for the rest of the test's application.
        $this->get(route('dashboard'), $this->fallback($token))->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->getJson(route('mobile.readings.index'), $this->fallback($token))->assertForbidden();
    }

    public function test_no_token_reaches_the_log(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });
        $user = User::factory()->create();
        $token = $this->postJson(route('mobile.login'), ['username' => $user->username, 'password' => 'password'])->json('token');
        $forged = str_repeat('z', 64);

        $this->getJson(route('mobile.me'), $this->fallback($token))->assertOk();
        $this->getJson(route('mobile.me'), $this->fallback($forged))->assertUnauthorized();
        $this->postJson(route('mobile.logout'), [], $this->fallback($token))->assertOk();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
            $this->assertStringNotContainsString($forged, $line);
        }
    }
}
