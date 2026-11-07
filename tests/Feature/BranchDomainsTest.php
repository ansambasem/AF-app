<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\ClosingSetting;
use App\Models\Permission;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BranchDomainsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $north;

    private Branch $south;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://admin.company.test', 'powercollect.branch_domains_enabled' => true]);
        $this->north = Branch::factory()->create(['name' => 'فرع الشمال', 'domain' => 'north.company.test', 'domain_enabled' => true]);
        $this->south = Branch::factory()->create(['name' => 'فرع الجنوب', 'domain' => 'south.example.test', 'domain_enabled' => true]);
        $this->admin = User::factory()->superAdmin()->create();
    }

    public function test_central_domain_management_normalizes_the_hostname_and_keeps_financial_data(): void
    {
        $subscription = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $this->actingAs($this->admin)->put('https://admin.company.test/branch-domains/'.$this->north->id, ['domain' => ' New.Company.Test. ', 'domain_enabled' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('branches', ['id' => $this->north->id, 'domain' => 'new.company.test', 'domain_enabled' => true]);
        $this->assertModelExists($subscription);
    }

    public function test_the_branch_home_page_redirects_to_login_on_the_same_domain(): void
    {
        $response = $this->get('https://north.company.test/');

        $response->assertRedirect('https://north.company.test/login');
    }

    #[TestWith(['https://north.company.test/path'])]
    #[TestWith(['admin.company.test'])]
    #[TestWith(['south.example.test'])]
    #[TestWith(['127.0.0.1'])]
    #[TestWith(['invalid-host'])]
    public function test_domain_management_rejects_paths_reserved_duplicate_and_invalid_hosts(string $domain): void
    {
        $this->actingAs($this->admin)->put('https://admin.company.test/branch-domains/'.$this->north->id, ['domain' => $domain, 'domain_enabled' => true])->assertSessionHasErrors('domain');
        $this->assertDatabaseHas('branches', ['id' => $this->north->id, 'domain' => 'north.company.test']);
    }

    public function test_domain_management_requires_its_own_revocable_permission(): void
    {
        $employee = User::factory()->branchAdmin()->create(['branch_id' => $this->north->id]);
        $url = 'https://admin.company.test/branch-domains/'.$this->north->id;
        $payload = ['domain' => 'new.company.test', 'domain_enabled' => true];
        $this->actingAs($employee)->put($url, $payload)->assertRedirect('https://admin.company.test/login');
        $this->assertDatabaseHas('branches', ['id' => $this->north->id, 'domain' => 'north.company.test']);
        $employee->permissions()->attach(Permission::idsFor([PermissionKey::ManageBranchDomains]));
        $this->actingAs($employee->fresh())->put($url, $payload)->assertSessionHasNoErrors();
    }

    public function test_unknown_disabled_and_inactive_branch_domains_do_not_open_the_application(): void
    {
        $this->south->forceFill(['domain_enabled' => false])->save();
        $this->get('https://unknown.company.test/login')->assertNotFound();
        $this->get('https://south.example.test/login')->assertNotFound();
        $this->north->update(['is_active' => false]);
        $this->get('https://north.company.test/login')->assertNotFound();
    }

    public function test_wrong_branch_login_is_rejected_but_authorized_login_succeeds(): void
    {
        $employee = User::factory()->collector()->create(['branch_id' => $this->south->id]);
        $payload = ['username' => $employee->username, 'password' => 'password'];
        $this->post('https://north.company.test/login', $payload)->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->post('https://south.example.test/login', $payload)->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($employee);
    }

    public function test_branch_staff_cannot_sign_in_on_the_central_site_and_are_told_the_details_are_wrong(): void
    {
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $this->north->id]);

        $this->post('https://admin.company.test/login', ['username' => $manager->username, 'password' => 'password'])
            ->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_the_super_admin_and_auditors_sign_in_on_the_central_site(): void
    {
        $auditor = User::factory()->financialAuditor()->create(['branch_id' => null]);

        foreach ([$this->admin, $auditor] as $user) {
            $this->post('https://admin.company.test/login', ['username' => $user->username, 'password' => 'password'])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);
            $this->post('https://admin.company.test/logout');
        }
    }

    public function test_whoever_manages_the_branches_signs_in_centrally_but_works_branches_from_their_links(): void
    {
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $this->north->id]);
        $manager->permissions()->attach(Permission::idsFor([PermissionKey::ManageBranchDomains]));

        $this->post('https://admin.company.test/login', ['username' => $manager->username, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($manager);
        $this->get('https://admin.company.test/payments')->assertRedirect('https://admin.company.test/branch-access');
    }

    public function test_staff_sign_in_on_the_central_site_while_branch_links_are_off(): void
    {
        config(['powercollect.branch_domains_enabled' => false]);
        $employee = User::factory()->collector()->create(['branch_id' => $this->north->id]);

        $this->post('http://localhost/login', ['username' => $employee->username, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($employee);
    }

    public function test_even_a_super_admin_sees_only_the_domain_branch_and_cannot_bind_foreign_records(): void
    {
        $own = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $foreign = Subscription::factory()->create(['branch_id' => $this->south->id]);
        $this->actingAs($this->admin)->get('https://north.company.test/subscriptions')->assertInertia(fn ($page) => $page
            ->where('subscriptions.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$own->id])
            ->where('branchContext.current.id', $this->north->id));
        $this->get('https://north.company.test/subscriptions/'.$foreign->id.'/edit')->assertNotFound();
        $this->get('https://admin.company.test/subscriptions')->assertInertia(fn ($page) => $page->where('subscriptions.total', 2));
    }

    public function test_an_employee_works_only_in_their_own_branch(): void
    {
        $employee = User::factory()->dataEntry()->create(['branch_id' => $this->south->id]);
        $own = Subscription::factory()->create(['branch_id' => $this->south->id]);
        Subscription::factory()->create(['branch_id' => $this->north->id]);

        $this->actingAs($employee)->get('https://south.example.test/subscriptions')->assertInertia(fn ($page) => $page
            ->where('subscriptions.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$own->id]));
        $this->get('https://north.company.test/subscriptions')->assertForbidden();
    }

    public function test_domain_rejects_foreign_branch_payload_and_company_management_routes(): void
    {
        $this->actingAs($this->admin)->post('https://north.company.test/subscriptions', ['branch_id' => $this->south->id])->assertSessionHasErrors('branch_id');
        $this->get('https://north.company.test/settings/permissions')->assertForbidden();
        $this->put('https://north.company.test/branch-domains/'.$this->south->id, ['domain' => 'new.company.test', 'domain_enabled' => true])->assertForbidden();
    }

    public function test_a_branch_manager_adds_a_subscription_on_the_branch_domain_without_choosing_a_branch(): void
    {
        $manager = User::factory()->branchAdmin()->create(['branch_id' => $this->north->id]);
        $payload = [
            'full_name' => 'أحمد محمود',
            'national_id' => '012345678',
            'phone' => '0591234567',
            'tariff_id' => Tariff::factory()->residential()->create()->id,
            'status' => 'active',
            'minimum_charge' => 0,
            'initial_reading' => 0,
            'branch_id' => '',
        ];

        $this->actingAs($manager)->post('https://north.company.test/subscriptions', $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subscriptions', ['national_id' => '012345678', 'branch_id' => $this->north->id]);
    }

    public function test_a_delegated_manager_can_manage_only_their_branch_employees_from_the_branch_domain(): void
    {
        $manager = User::factory()->collector()->withPermissions([PermissionKey::ManagePermissions, PermissionKey::ViewSubscriptions])->grantingWhatTheyHold()->create(['branch_id' => $this->north->id]);
        $employee = User::factory()->collector()->withPermissions([])->create(['branch_id' => $this->north->id]);
        $foreign = User::factory()->collector()->withPermissions([])->create(['branch_id' => $this->south->id]);
        $viewSubscriptions = Permission::query()->where('key', PermissionKey::ViewSubscriptions->value)->sole();
        $url = 'https://north.company.test/settings/permissions';

        $this->actingAs($manager)->get($url)->assertInertia(fn ($page) => $page
            ->where('scopedToOwnBranch', true)
            ->where('users.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$employee->id]));
        $this->put($url, ['permissions' => [
            $employee->id => [$viewSubscriptions->id],
            $foreign->id => [$viewSubscriptions->id],
            $manager->id => [],
        ]])->assertSessionHasNoErrors();

        $this->assertTrue($employee->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
        $this->assertFalse($foreign->fresh()->hasPermission(PermissionKey::ViewSubscriptions));
        $this->assertTrue($manager->fresh()->hasPermission(PermissionKey::ManagePermissions));
        $this->put($url, ['permissions' => [$employee->id => []]])->assertSessionHasNoErrors();
        $this->assertFalse($employee->fresh()->hasPermission(PermissionKey::ViewSubscriptions));

        $manager->permissions()->sync(Permission::idsFor([PermissionKey::ViewSubscriptions]));
        $this->actingAs($manager->fresh())->get($url)->assertForbidden();
        $this->put($url, ['permissions' => [$employee->id => [$viewSubscriptions->id]]])->assertForbidden();
    }

    public function test_branch_selector_lists_only_allowed_active_domains_and_supports_custom_domains(): void
    {
        $auditor = User::factory()->financialAuditor()->create(['branch_id' => null, 'audit_settings' => ['scope' => 'selected', 'branch_ids' => [$this->south->id], 'actions' => ['view_ledger']]]);
        $this->actingAs($auditor)->get('https://admin.company.test/branch-access')->assertInertia(fn ($page) => $page
            ->has('branches', 1)->where('branches.0.url', 'https://south.example.test/dashboard'));
    }

    public function test_saving_permissions_cannot_open_another_branch_to_an_employee(): void
    {
        $employee = User::factory()->collector()->create(['branch_id' => $this->south->id]);
        $payload = ['permissions' => [$employee->id => $employee->permissions->modelKeys()], 'work_branches' => [$employee->id => [$this->north->id]]];

        $this->actingAs($this->admin)->put('https://admin.company.test/settings/permissions', $payload)->assertSessionHasNoErrors();

        $this->actingAs($employee->fresh())->get('https://north.company.test/subscriptions')->assertForbidden();
    }

    public function test_api_requests_on_a_branch_domain_cannot_bypass_the_web_boundary(): void
    {
        $this->getJson('https://north.company.test/api/subscriptions')->assertNotFound();
    }

    public function test_legacy_access_is_unchanged_before_domain_routing_is_enabled(): void
    {
        config(['powercollect.branch_domains_enabled' => false]);
        $this->actingAs($this->admin)->get('http://localhost/dashboard')->assertOk();
    }

    public function test_an_employee_records_payments_in_their_branch_and_foreign_receipts_remain_hidden(): void
    {
        $employee = User::factory()->collector()->create(['branch_id' => $this->north->id]);
        $own = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $foreign = Subscription::factory()->create(['branch_id' => $this->south->id]);
        $foreignPayment = SubscriptionTransaction::recordPayment($foreign, $employee, ['amount' => '40', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->actingAs($employee)->post('https://north.company.test/subscriptions/'.$own->id.'/payments', ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscription_transactions', ['subscription_id' => $own->id, 'recorded_by' => $employee->id, 'amount' => '-25.00']);
        $this->get('https://north.company.test/subscriptions/'.$foreign->id.'/payments/'.$foreignPayment->id.'/receipt')->assertNotFound();
    }

    public function test_global_transfer_reference_uniqueness_is_preserved_without_disclosing_other_branch_details(): void
    {
        $own = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $foreign = Subscription::factory()->create(['branch_id' => $this->south->id, 'full_name' => 'Private Foreign Subscriber']);
        SubscriptionTransaction::recordPayment($foreign, $this->admin, ['amount' => '40', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Sender', 'reference_number' => 'GLOBAL-123']);

        $this->actingAs($this->admin)->getJson('https://north.company.test/subscriptions/'.$own->id.'/payments/reference-status?reference_number=GLOBAL-123')
            ->assertOk()->assertJsonPath('available', false)->assertJsonPath('conflict.id', null)->assertJsonPath('conflict.url', null)->assertDontSee('Private Foreign Subscriber');
        $this->post('https://north.company.test/subscriptions/'.$own->id.'/payments', ['amount' => '25', 'currency' => 'ILS', 'payment_method' => 'bank_transfer', 'bank_name' => 'بنك فلسطين', 'sender_name' => 'Sender', 'reference_number' => 'GLOBAL-123'])
            ->assertSessionHasErrors('reference_number');
        $this->assertDatabaseCount('subscription_transactions', 1);
    }

    public function test_ledger_and_box_details_stay_inside_the_domain_even_for_central_administrators(): void
    {
        $own = Subscription::factory()->create(['branch_id' => $this->north->id]);
        $foreign = Subscription::factory()->create(['branch_id' => $this->south->id]);
        $ownLine = SubscriptionTransaction::factory()->create(['subscription_id' => $own->id]);
        SubscriptionTransaction::factory()->create(['subscription_id' => $foreign->id]);
        $foreignBox = CashBox::factory()->create(['branch_id' => $this->south->id]);

        $this->actingAs($this->admin)->get('https://north.company.test/ledger')->assertInertia(fn ($page) => $page
            ->where('entries.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$ownLine->id]));
        $this->get('https://north.company.test/payments/cash-boxes/'.$foreignBox->id)->assertNotFound();
    }

    #[TestWith(['/dashboard'])]
    #[TestWith(['/payments'])]
    #[TestWith(['/payments/cash-boxes'])]
    #[TestWith(['/transaction-audit'])]
    #[TestWith(['/reports'])]
    #[TestWith(['/receivables'])]
    #[TestWith(['/meter-readings'])]
    #[TestWith(['/reading-cycles'])]
    #[TestWith(['/disconnection-lists'])]
    #[TestWith(['/messages'])]
    #[TestWith(['/users'])]
    public function test_operational_pages_render_with_the_branch_boundary(string $path): void
    {
        $this->actingAs($this->admin)->get('https://north.company.test'.$path)->assertOk();
    }

    public function test_new_account_numbers_remain_unique_across_branch_domains(): void
    {
        Subscription::factory()->create(['branch_id' => $this->south->id, 'account_number' => now()->format('Y').'99999']);
        $this->actingAs($this->admin)->get('https://north.company.test/dashboard')->assertOk();
        $this->assertSame(now()->format('Y').'100000', Subscription::nextAccountNumber());
    }

    public function test_a_staff_session_on_the_central_site_is_ended_rather_than_sent_to_a_branch_picker(): void
    {
        $employee = User::factory()->collector()->create(['branch_id' => $this->north->id]);
        $this->actingAs($employee)->get('https://admin.company.test/payments')->assertRedirect('https://admin.company.test/login');
        $this->assertGuest();

        $this->actingAs($employee)->post('https://admin.company.test/payments/split', [])->assertRedirect('https://admin.company.test/login');
        $this->assertGuest();
    }

    public function test_auditors_can_only_enter_domains_in_their_audit_scope(): void
    {
        $employee = User::factory()->financialAuditor()->create(['branch_id' => null, 'audit_settings' => ['scope' => 'selected', 'branch_ids' => [$this->north->id], 'actions' => ['view_ledger']]]);
        $this->actingAs($employee)->get('https://north.company.test/ledger')->assertOk();
        $this->get('https://south.example.test/ledger')->assertForbidden();
        $this->get('https://admin.company.test/ledger')->assertOk();
    }

    public function test_session_cookies_are_host_only_even_when_an_old_shared_domain_is_configured(): void
    {
        config(['session.domain' => '.company.test']);
        $response = $this->get('https://north.company.test/login')->assertOk();
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $this->assertSame('.company.test', config('session.domain'));
    }

    public function test_branch_settings_default_to_the_domain_branch_and_cannot_change_company_defaults(): void
    {
        $company = ClosingSetting::company()->week_starts_on;
        $this->actingAs($this->admin)->get('https://north.company.test/settings/payments')->assertInertia(fn ($page) => $page->where('boxPeriod.branch.id', $this->north->id)->where('canManagePaymentCatalog', false));
        $this->get('https://north.company.test/settings/reading-schedule')->assertInertia(fn ($page) => $page->where('branch.id', $this->north->id));
        $this->put('https://north.company.test/settings/payments', ['week_starts_on' => ($company + 1) % 7])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('closing_settings', ['branch_id' => $this->north->id, 'week_starts_on' => ($company + 1) % 7]);
        $this->assertDatabaseHas('closing_settings', ['branch_id' => null, 'week_starts_on' => $company]);
        $this->post('https://north.company.test/settings/banks', ['name' => 'Shared Bank'])->assertForbidden();
    }

    public function test_host_restrictions_keep_infrastructure_health_checks_available(): void
    {
        $this->get('https://internal-host.test/up')->assertOk();
        $this->get('https://internal-host.test/dashboard')->assertNotFound();
    }

    public function test_local_branch_links_use_the_local_server_scheme_and_port(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['app.url' => 'http://localhost:8000']);
        $this->north->forceFill(['domain' => 'camp2.localhost'])->save();
        $auditor = User::factory()->financialAuditor()->create(['branch_id' => null, 'audit_settings' => ['scope' => 'selected', 'branch_ids' => [$this->north->id], 'actions' => ['view_ledger']]]);

        $this->actingAs($auditor)->get('http://localhost:8000/branch-access')->assertInertia(fn ($page) => $page
            ->where('branches.0.url', 'http://camp2.localhost:8000/dashboard'));
    }

    public function test_production_branch_links_use_https_without_the_development_port(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.url' => 'http://localhost:8000']);

        $this->actingAs($this->admin)->get('http://localhost:8000/branch-access')->assertInertia(fn ($page) => $page
            ->where('branches.0.url', 'https://south.example.test/dashboard'));
    }

    public function test_local_loopback_alias_opens_the_main_login_when_app_url_uses_localhost(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config(['app.url' => 'http://localhost:8000']);

        $this->get('http://127.0.0.1:8000/login')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login')->where('branchContext.current', null));
    }

    public function test_disabled_branch_login_error_links_back_to_the_central_login(): void
    {
        $this->north->forceFill(['domain_enabled' => false])->save();

        $this->get('https://north.company.test/login')->assertNotFound()->assertInertia(fn ($page) => $page
            ->component('Error')->where('branchContext.recoveryUrl', 'https://admin.company.test/login'));
    }

    public function test_unknown_domains_have_a_central_login_recovery_link(): void
    {
        $this->get('https://unknown.company.test/login')->assertNotFound()->assertInertia(fn ($page) => $page
            ->where('branchContext.recoveryUrl', 'https://admin.company.test/login'));
    }

    public function test_local_loopback_alias_is_not_accepted_as_a_production_domain(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.url' => 'https://localhost']);

        $this->get('https://127.0.0.1/login')->assertNotFound();
    }
}
