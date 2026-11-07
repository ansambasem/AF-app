<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_super_admins_branch_choice_follows_them_between_pages_without_changing_their_account(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $owner = User::factory()->superAdmin()->for($first)->create();
        $originalBranchId = $owner->branch_id;
        $firstSubscription = Subscription::factory()->for($first)->create();
        $secondSubscription = Subscription::factory()->for($second)->create();
        $firstBox = MeterBox::factory()->for($first)->create();
        $secondBox = MeterBox::factory()->for($second)->create();

        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $second->id])->assertRedirect(route('dashboard'));
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('branchView.current.id', $second->id)
            ->where('branchView.options', fn ($options): bool => collect($options)->pluck('id')->contains($first->id) && collect($options)->pluck('id')->contains($second->id))
            ->where('subscriptions.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$secondSubscription->id]));
        $this->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page
            ->where('branchView.current.id', $second->id)
            ->where('meterBoxes.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$secondBox->id]));
        $this->get(route('settings.reading-schedule.edit'))->assertInertia(fn ($page) => $page
            ->where('branch.id', $second->id));
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('branchScope.name', $second->name));
        $this->get(route('subscriptions.statement', $firstSubscription))->assertNotFound();

        $this->assertSame($originalBranchId, $owner->fresh()->branch_id);
        $this->assertTrue($owner->fresh()->isSuperAdmin());
        $this->assertDatabaseHas('subscriptions', ['id' => $firstSubscription->id]);
        $this->assertDatabaseHas('meter_boxes', ['id' => $firstBox->id]);
    }

    public function test_switching_branches_and_returning_to_all_replaces_the_persisted_view(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $owner = User::factory()->superAdmin()->for($first)->create();
        Subscription::factory()->for($first)->create();
        Subscription::factory()->for($second)->create();

        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $first->id])->assertRedirect();
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->where('branchView.current.id', $first->id)->has('subscriptions.data', 1));
        $this->put(route('branch-view.update'), ['branch_id' => $second->id])->assertRedirect();
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->where('branchView.current.id', $second->id)->has('subscriptions.data', 1));
        $this->put(route('branch-view.update'), ['branch_id' => null])->assertRedirect();
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->where('branchView.current', null)->has('subscriptions.data', 2));
    }

    public function test_an_existing_page_branch_picker_also_updates_the_persisted_view(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $owner = User::factory()->superAdmin()->for($first)->create();
        Subscription::factory()->for($first)->create();
        Subscription::factory()->for($second)->create();
        $this->actingAs($owner)->get(route('subscriptions.index', ['filter' => ['branch_id' => $second->id]]))
            ->assertInertia(fn ($page) => $page->where('branchView.current.id', $second->id));
        $this->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page->where('branchView.current.id', $second->id));
        $this->get(route('subscriptions.index', ['filter' => ['branch_id' => ''], 'branch_id' => $second->id, 'branch' => (string) $second->id]))
            ->assertInertia(fn ($page) => $page->where('branchView.current', null)->has('subscriptions.data', 2));
        $this->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page->where('branchView.current', null));
    }

    public function test_invalid_branch_choices_leave_the_current_view_unchanged(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->superAdmin()->for($branch)->create();
        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $branch->id])->assertRedirect();
        $this->put(route('branch-view.update'), ['branch_id' => 999999])->assertSessionHasErrors('branch_id');
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->where('branchView.current.id', $branch->id));
    }

    public function test_a_selected_branch_does_not_hide_tariff_dependencies_in_other_branches_when_deleting(): void
    {
        [$viewed, $other] = Branch::factory()->count(2)->create();
        $owner = User::factory()->superAdmin()->for($viewed)->create();
        $tariff = Tariff::factory()->create();
        $subscription = Subscription::factory()->for($other)->for($tariff)->create();
        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $viewed->id])->assertRedirect();
        $this->get(route('tariffs.index'))->assertInertia(fn ($page) => $page->where('tariffs.0.canDelete', true));

        $this->from(route('tariffs.index'))->delete(route('tariffs.destroy', $tariff))
            ->assertRedirect(route('tariffs.index'))
            ->assertSessionHasErrors(['delete' => 'لا يمكن حذف التعرفة لوجود سجلات مرتبطة به — المشتركون: 1.']);

        $this->assertModelExists($tariff);
        $this->assertModelExists($subscription);
        $this->assertTrue($owner->fresh()->isSuperAdmin());
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->where('branchView.current.id', $viewed->id)->has('subscriptions.data', 0));
    }

    public function test_a_selected_branch_does_not_hide_other_shared_records_dependencies_when_deleting(): void
    {
        [$viewed, $other] = Branch::factory()->count(2)->create();
        $owner = User::factory()->superAdmin()->for($viewed)->create();
        $subscription = Subscription::factory()->for($other)->create();
        $breaker = $subscription->circuitBreaker;
        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $viewed->id])->assertRedirect();

        $this->from(route('circuit-breakers.index'))->delete(route('circuit-breakers.destroy', $breaker))
            ->assertRedirect(route('circuit-breakers.index'))
            ->assertSessionHasErrors(['delete' => 'لا يمكن حذف القاطع لوجود سجلات مرتبطة به — المشتركون: 1.']);

        $this->assertModelExists($breaker);
        $this->assertModelExists($subscription);
    }

    public function test_a_super_admin_can_still_delete_an_unused_shared_record_while_viewing_one_branch(): void
    {
        $branch = Branch::factory()->create();
        $tariff = Tariff::factory()->create();
        $owner = User::factory()->superAdmin()->for($branch)->create();
        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $branch->id])->assertRedirect();

        $this->from(route('tariffs.index'))->delete(route('tariffs.destroy', $tariff))
            ->assertRedirect(route('tariffs.index'))->assertSessionHasNoErrors()->assertSessionHas('status', 'tariff-deleted');

        $this->assertModelMissing($tariff);
        $this->assertTrue($owner->fresh()->isSuperAdmin());
    }

    public function test_the_selected_view_does_not_restrict_a_super_admins_mutation_permissions(): void
    {
        [$viewed, $other] = Branch::factory()->count(2)->create();
        $box = MeterBox::factory()->for($other)->create();
        $owner = User::factory()->superAdmin()->for($viewed)->create();
        $this->actingAs($owner)->put(route('branch-view.update'), ['branch_id' => $viewed->id])->assertRedirect();

        $this->from(route('meter-boxes.index'))->delete(route('meter-boxes.destroy', $box))
            ->assertRedirect(route('meter-boxes.index'))->assertSessionHasNoErrors();

        $this->assertModelMissing($box);
        $this->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page->where('branchView.current.id', $viewed->id));
    }

    public function test_other_staff_cannot_select_a_global_branch_view(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $staff = User::factory()->branchAdmin()->for($first)->create();
        $this->actingAs($staff)->put(route('branch-view.update'), ['branch_id' => $second->id])->assertForbidden();
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page->where('branchView', null));
    }

    public function test_the_branch_choice_is_shared_even_on_partial_page_reloads(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs(User::factory()->superAdmin()->for($branch)->create())
            ->put(route('branch-view.update'), ['branch_id' => $branch->id])->assertRedirect();
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Inertia-Partial-Component' => 'Subscriptions/Index', 'X-Inertia-Partial-Data' => 'subscriptions'])
            ->get(route('subscriptions.index'))->assertJsonPath('props.branchView.current.id', $branch->id);
    }

    public function test_a_prefetched_page_does_not_change_the_persisted_branch_choice(): void
    {
        [$first, $second] = Branch::factory()->count(2)->create();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->put(route('branch-view.update'), ['branch_id' => $first->id])->assertRedirect();
        $this->get(route('subscriptions.index', ['filter' => ['branch_id' => $second->id]]), ['Purpose' => 'prefetch']);
        $this->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page->where('branchView.current.id', $first->id));
    }

    public function test_a_branch_domain_keeps_its_own_scope_and_disables_the_global_switcher(): void
    {
        config(['app.url' => 'https://company.test', 'powercollect.branch_domains_enabled' => true]);
        $first = Branch::factory()->create(['domain' => 'first.company.test', 'domain_enabled' => true]);
        $second = Branch::factory()->create();
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner)->put('https://company.test/branch-view', ['branch_id' => $second->id])->assertRedirect();
        $this->get('https://first.company.test/dashboard')->assertInertia(fn ($page) => $page
            ->where('branchView', null)
            ->where('branchContext.current.id', $first->id));
        $this->put('https://first.company.test/branch-view', ['branch_id' => $first->id])->assertForbidden();
        $this->get('https://company.test/subscriptions')->assertInertia(fn ($page) => $page->where('branchView.current.id', $second->id));
    }

    public function test_unauthenticated_branch_changes_redirect_to_login(): void
    {
        $this->put(route('branch-view.update'), ['branch_id' => null])->assertRedirect(route('login'));
    }
}
