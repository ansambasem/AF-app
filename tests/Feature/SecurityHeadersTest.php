<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function inProduction(array $config = []): void
    {
        $this->app['env'] = 'production';
        config($config);
    }

    public function test_every_response_carries_the_basic_security_headers_and_the_error_pages_too(): void
    {
        foreach (['/login', '/no-such-page', '/api/mobile/no-such-endpoint'] as $path) {
            $response = $this->get($path);

            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'), $path);
        }
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_x_powered_by_and_the_inertia_devtools_headers_are_not_given_away(): void
    {
        $this->inProduction();
        Route::get('/_headers-probe', fn () => response('ok')->withHeaders(['X-Powered-By' => 'PHP/8.4', 'X-Inertia-Devtools-Id' => 'abc', 'X-Custom' => 'kept']));

        $this->get('/_headers-probe')->assertHeaderMissing('X-Powered-By')->assertHeaderMissing('X-Inertia-Devtools-Id')->assertHeader('X-Custom', 'kept');
    }

    public function test_a_header_the_response_already_set_is_left_alone(): void
    {
        Route::get('/_frame-probe', fn () => response('ok')->header('X-Frame-Options', 'DENY'));

        $this->get('/_frame-probe')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_content_security_policy_is_not_sent_in_development(): void
    {
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_in_production_the_policy_allows_only_the_apps_scripts_and_the_one_nonced_inline_script(): void
    {
        $this->inProduction(['powercollect.security.csp' => 'enforce']);

        $response = $this->get('/login')->assertOk();
        $policy = $response->headers->get('Content-Security-Policy');

        preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $matches);
        $this->assertNotEmpty($matches[1] ?? null, $policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $response->assertSee('<script nonce="'.$matches[1].'">', false);
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_every_page_gets_its_own_nonce(): void
    {
        $this->inProduction(['powercollect.security.csp' => 'enforce']);

        $nonceOf = function (): string {
            preg_match("/'nonce-([^']+)'/", $this->get('/login')->headers->get('Content-Security-Policy'), $matches);

            return $matches[1];
        };

        $this->assertNotSame($nonceOf(), $nonceOf());
    }

    public function test_the_policy_can_be_tried_without_blocking_or_switched_off(): void
    {
        $this->inProduction(['powercollect.security.csp' => 'report-only']);
        $this->get('/login')->assertHeader('Content-Security-Policy-Report-Only')->assertHeaderMissing('Content-Security-Policy');

        config(['powercollect.security.csp' => 'off']);
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
