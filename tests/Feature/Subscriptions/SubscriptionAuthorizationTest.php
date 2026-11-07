<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_all_subscriptions_across_branches(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $tariff = Tariff::factory()->residential()->create();
        Subscription::factory()->create(['branch_id' => $branchA->id, 'tariff_id' => $tariff->id, 'full_name' => 'From Branch A']);
        Subscription::factory()->create(['branch_id' => $branchB->id, 'tariff_id' => $tariff->id, 'full_name' => 'From Branch B']);

        $response = $this->actingAs($superAdmin)->get(route('subscriptions.index'));

        $response->assertOk();
        $response->assertSee('From Branch A');
        $response->assertSee('From Branch B');
    }

    public function test_data_entry_only_sees_subscriptions_in_their_own_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branchA->id]);
        $tariff = Tariff::factory()->residential()->create();
        Subscription::factory()->create(['branch_id' => $branchA->id, 'tariff_id' => $tariff->id, 'full_name' => 'In My Branch']);
        Subscription::factory()->create(['branch_id' => $branchB->id, 'tariff_id' => $tariff->id, 'full_name' => 'In Other Branch']);

        $response = $this->actingAs($dataEntry)->get(route('subscriptions.index'));

        $response->assertOk();
        $response->assertSee('In My Branch');
        $response->assertDontSee('In Other Branch');
    }

    public function test_data_entry_can_register_a_subscription_in_their_own_branch(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $box = MeterBox::factory()->create(['branch_id' => $branch->id]);
        $tariff = Tariff::factory()->residential()->create();

        $response = $this->actingAs($dataEntry)->post(route('subscriptions.store'), [
            'full_name' => 'New Customer',
            'national_id' => '123456789',
            'initial_reading' => 100,
            'phone' => '0590000000',
            'address' => 'Some street',
            'meter_box_id' => $box->id,
            'tariff_id' => $tariff->id,
            'status' => SubscriptionStatus::Active->value,
            'minimum_charge' => 10,
            'notes' => 'No notes',
            // Attempt to tamper: request a different branch — must be ignored.
            'branch_id' => $otherBranch->id,
        ]);

        $response->assertRedirect(route('subscriptions.index'));
        $this->assertDatabaseHas('subscriptions', [
            'national_id' => '123456789',
            'branch_id' => $branch->id,
            'registered_by' => $dataEntry->id,
        ]);
    }

    public function test_a_subscription_is_accounted_weekly_unless_monthly_is_chosen(): void
    {
        $branch = Branch::factory()->create();
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $tariff = Tariff::factory()->residential()->create();
        $payload = [
            'full_name' => 'New Customer',
            'national_id' => '123456789',
            'initial_reading' => 100,
            'phone' => '0590000000',
            'tariff_id' => $tariff->id,
            'status' => SubscriptionStatus::Active->value,
            'minimum_charge' => 10,
        ];

        $this->actingAs($dataEntry)->post(route('subscriptions.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($dataEntry)->post(route('subscriptions.store'), [...$payload, 'national_id' => '987654321', 'accounting_type' => 'monthly'])->assertSessionHasNoErrors();
        $this->actingAs($dataEntry)->post(route('subscriptions.store'), [...$payload, 'national_id' => '555555555', 'accounting_type' => 'daily'])->assertSessionHasErrors('accounting_type');

        $this->assertDatabaseHas('subscriptions', ['national_id' => '123456789', 'accounting_type' => 'weekly']);
        $this->assertDatabaseHas('subscriptions', ['national_id' => '987654321', 'accounting_type' => 'monthly']);
        $this->assertDatabaseMissing('subscriptions', ['national_id' => '555555555']);
    }

    public function test_create_form_exposes_the_own_branch_area_and_subarea_and_no_meter_box_list(): void
    {
        $area = Area::factory()->create();
        $branch = Branch::factory()->inArea($area)->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        MeterBox::factory()->create(['branch_id' => $branch->id, 'sub_area_id' => $subArea->id]);
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);

        $this->actingAs($dataEntry)
            ->get(route('subscriptions.create'))
            ->assertInertia(fn ($page) => $page
                ->where('currentBranchAreaId', $area->id)
                ->where('currentBranchAreaName', $area->name)
                ->has('subAreas', 1)
                ->where('subAreas.0.id', $subArea->id)
                // The form finds boxes as the user types, so the page never carries them all.
                ->missing('meterBoxes'));
    }

    public function test_the_edit_form_carries_the_box_the_subscription_is_on_and_none_when_it_has_no_box(): void
    {
        $branch = Branch::factory()->create();
        $subArea = SubArea::factory()->create();
        $box = MeterBox::factory()->create(['branch_id' => $branch->id, 'sub_area_id' => $subArea->id, 'name' => 'camp', 'name_suffix' => '2A', 'box_number' => '9897']);
        $onBox = Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id]);
        $withoutBox = Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => null]);
        $admin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);

        $this->actingAs($admin)->get(route('subscriptions.edit', $onBox))->assertInertia(fn ($page) => $page
            ->where('subscription.meter_box', ['value' => (string) $box->id, 'label' => 'camp 2A - (9897)', 'sub_area_id' => $subArea->id]));
        $this->get(route('subscriptions.edit', $withoutBox))->assertInertia(fn ($page) => $page->where('subscription.meter_box', null));
        $this->get(route('subscriptions.index'))->assertInertia(fn ($page) => $page
            ->where('subscriptions.data', fn ($rows): bool => collect($rows)->firstWhere('id', $onBox->id)['meter_box']['value'] === (string) $box->id));
    }

    public function test_data_entry_can_register_a_subscription_without_a_meter_box_yet(): void
    {
        $branch = Branch::factory()->create();
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $branch->id]);
        $tariff = Tariff::factory()->commercial()->create();

        $response = $this->actingAs($dataEntry)->post(route('subscriptions.store'), [
            'full_name' => 'No Box Yet',
            'national_id' => '987654321',
            'initial_reading' => 100,
            'phone' => '0560000001',
            'address' => 'Some other street',
            'tariff_id' => $tariff->id,
            'status' => SubscriptionStatus::Active->value,
            'minimum_charge' => 10,
            'notes' => 'No notes',
        ]);

        $response->assertRedirect(route('subscriptions.index'));
        $this->assertDatabaseHas('subscriptions', [
            'national_id' => '987654321',
            'meter_box_id' => null,
        ]);
    }

    public function test_branch_admin_can_manage_subscriptions_in_their_branch(): void
    {
        $branch = Branch::factory()->create();
        $branchAdmin = User::factory()->branchAdmin()->create(['branch_id' => $branch->id]);
        $subscription = Subscription::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($branchAdmin)->get(route('subscriptions.index'))->assertOk();
        $this->actingAs($branchAdmin)->get(route('subscriptions.edit', $subscription))->assertOk();
    }

    public function test_data_entry_cannot_update_a_subscription_from_another_branch(): void
    {
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $dataEntry = User::factory()->dataEntry()->create(['branch_id' => $ownBranch->id]);
        $foreignSubscription = Subscription::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($dataEntry)
            ->get(route('subscriptions.edit', $foreignSubscription))
            ->assertForbidden();

        $this->actingAs($dataEntry)
            ->put(route('subscriptions.update', $foreignSubscription), ['full_name' => 'Hacked Name'])
            ->assertForbidden();

        $this->assertDatabaseMissing('subscriptions', [
            'id' => $foreignSubscription->id,
            'full_name' => 'Hacked Name',
        ]);
    }

    public function test_collector_has_no_access_to_subscriptions_by_default(): void
    {
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->create(['branch_id' => $branch->id]);

        $this->actingAs($collector)->get(route('subscriptions.index'))->assertForbidden();
        $this->actingAs($collector)->get(route('subscriptions.create'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('subscriptions.index'))
            ->assertRedirect(route('login'));
    }

    public function test_subscriptions_page_can_be_filtered_by_tariff(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $residential = Tariff::factory()->residential()->create();
        $commercial = Tariff::factory()->commercial()->create();
        Subscription::factory()->create(['branch_id' => $branch->id, 'tariff_id' => $residential->id, 'full_name' => 'Residential Subscription']);
        Subscription::factory()->create(['branch_id' => $branch->id, 'tariff_id' => $commercial->id, 'full_name' => 'Commercial Subscription']);

        $response = $this->actingAs($superAdmin)->get(route('subscriptions.index', ['filter' => ['tariff_id' => $residential->id]]));

        $response->assertOk();
        $response->assertSee('Residential Subscription');
        $response->assertDontSee('Commercial Subscription');
    }

    public function test_a_box_without_a_name_adds_no_blank_option_to_the_box_name_filter(): void
    {
        $branch = Branch::factory()->create();
        MeterBox::factory()->create(['branch_id' => $branch->id, 'name' => null, 'name_suffix' => null, 'box_number' => '111333']);
        MeterBox::factory()->create(['branch_id' => $branch->id, 'name' => 'camp', 'name_suffix' => '1', 'box_number' => '1234']);

        $this->actingAs(User::factory()->superAdmin()->create())->get(route('subscriptions.index'))
            ->assertInertia(function ($page): void {
                $names = collect(collect($page->toArray()['props']['filterOptions'])->firstWhere('key', 'meter_box_name')['options']);
                $this->assertSame(['camp'], $names->pluck('label')->all());
            });
    }

    public function test_subscriptions_can_be_filtered_by_box_name_then_by_one_of_its_boxes(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $campOne = MeterBox::factory()->create(['branch_id' => $branch->id, 'name' => 'camp', 'name_suffix' => '1', 'box_number' => '1234']);
        $campTwo = MeterBox::factory()->create(['branch_id' => $branch->id, 'name' => 'camp', 'name_suffix' => '2', 'box_number' => '1243']);
        $club = MeterBox::factory()->create(['branch_id' => $branch->id, 'name' => 'club', 'name_suffix' => null, 'box_number' => '5000']);
        foreach (['Camp One Subscription' => $campOne, 'Camp Two Subscription' => $campTwo, 'Club Subscription' => $club] as $name => $box) {
            Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'full_name' => $name]);
        }

        $this->actingAs($superAdmin)->get(route('subscriptions.index', ['filter' => ['meter_box_name' => 'camp']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('subscriptions.data', 2)
                ->where('filterOptions', function ($groups) use ($branch, $campOne, $campTwo): bool {
                    $groups = collect($groups);
                    $numbers = $groups->firstWhere('key', 'meter_box_id');

                    $withoutScope = fn (array $options): array => collect($options)->map(fn (array $option) => collect($option)->except('scope')->all())->all();

                    return $withoutScope($groups->firstWhere('key', 'meter_box_name')['options']) === [
                        ['value' => 'camp', 'label' => 'camp'],
                        ['value' => 'club', 'label' => 'club'],
                    ] && $numbers['dependsOn'] === 'meter_box_name' && $withoutScope($numbers['options']) === [
                        ['value' => (string) $campOne->id, 'label' => "1 (1234) — {$branch->name}", 'parent' => 'camp'],
                        ['value' => (string) $campTwo->id, 'label' => "2 (1243) — {$branch->name}", 'parent' => 'camp'],
                    ] && collect($numbers['options'])->every(fn (array $option): bool => $option['scope']['branch_id'] === (string) $branch->id);
                }));

        $this->actingAs($superAdmin)->get(route('subscriptions.index', ['filter' => ['meter_box_name' => 'camp', 'meter_box_id' => $campTwo->id]]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.full_name', 'Camp Two Subscription'));
    }

    public function test_subscriptions_index_carries_the_detail_fields_the_view_modal_needs(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $branch = Branch::factory()->create();
        $governorate = Governorate::factory()->create();
        $branch->update(['governorate_id' => $governorate->id]);
        $tariff = Tariff::factory()->residential()->create();
        Subscription::factory()->create(['branch_id' => $branch->id, 'tariff_id' => $tariff->id, 'full_name' => 'Detail Fields Subscription']);

        $response = $this->actingAs($superAdmin)->get(route('subscriptions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.full_name', 'Detail Fields Subscription')
            ->where('subscriptions.data.0.tariffRate', (string) $tariff->rate)
            ->where('subscriptions.data.0.governorateName', $governorate->name));
    }
}
