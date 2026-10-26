<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_that_does_not_exist_is_an_arabic_page_with_the_way_back_for_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->branchAdmin()->create())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404)->has('auth.user'));
    }

    public function test_a_record_that_does_not_exist_is_the_same_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/subscriptions/999999/edit')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404));
    }

    public function test_opening_a_page_without_permission_shows_it_blank_rather_than_the_default_english_403(): void
    {
        $collector = User::factory()->collector()->create();

        $response = $this->actingAs($collector)->get('/users')->assertForbidden();

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 403)->where('blank', true));
        $this->assertStringNotContainsString('This action is unauthorized', $response->getContent());
    }

    public function test_a_forbidden_action_still_says_it_is_not_permitted(): void
    {
        $collector = User::factory()->collector()->create();

        $this->actingAs($collector)->post('/users', [])->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 403)->where('blank', false));
    }

    /**
     * The top bar shows every page to everyone (lib/navigation.js), so each
     * one opens blank, in the app's layout, for those who may not open it,
     * rather than a broken screen.
     */
    public function test_every_page_in_the_top_bar_opens_blank_for_a_user_without_its_permission(): void
    {
        $user = User::factory()->collector()->create();
        $user->permissions()->sync([]);
        $this->actingAs($user);

        foreach (['/payments', '/payments/summary', '/payments/cash-boxes', '/ledger', '/receivables', '/transaction-audit', '/branch-performance', '/reports', '/company-forms',
            '/subscriptions', '/meter-readings', '/reading-cycles', '/disconnection-lists', '/messages', '/users', '/settings/payments', '/branches', '/tariffs',
            '/circuit-breakers', '/meter-boxes', '/governorates', '/settings/permissions', '/settings/reading-schedule', '/settings/print-templates', '/settings/sms'] as $path) {
            $this->get($path)->assertForbidden()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 403)->where('blank', true)->has('auth.user'));
        }
    }

    public function test_an_inertia_visit_to_a_forbidden_page_gets_the_error_component_too(): void
    {
        $version = app(HandleInertiaRequests::class)->version(Request::create('/users'));

        $this->actingAs(User::factory()->collector()->create())
            ->get('/users', ['Accept' => 'text/html, application/xhtml+xml', 'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertForbidden()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.status', 403);
    }

    public function test_a_visitor_who_is_not_signed_in_gets_the_page_with_a_sign_in_instead(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 404)->where('auth', null));
    }

    public function test_a_real_address_asked_with_the_wrong_method_is_a_405_with_the_allowed_ones(): void
    {
        $response = $this->actingAs(User::factory()->branchAdmin()->create())->delete('/dashboard')->assertStatus(405);

        $this->assertStringContainsString('GET', $response->headers->get('Allow'));
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Error')->where('status', 405));
    }

    public function test_a_request_of_any_kind_to_an_unknown_address_is_a_404_not_a_405(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs(User::factory()->branchAdmin()->create())->{$method}('/no-such-page')->assertNotFound();
        }
    }

    public function test_json_requests_and_the_mobile_api_keep_their_json_errors(): void
    {
        $this->actingAs(User::factory()->collector()->create())->getJson('/users')->assertForbidden()->assertJsonStructure(['message']);
        $this->getJson('/api/no-such-endpoint')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api/no-such-endpoint')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }
}
