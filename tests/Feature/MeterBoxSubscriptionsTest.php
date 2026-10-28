<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\PermissionKey;
use App\Enums\SubscriptionStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\Governorate;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeterBoxSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_meter_box_totals_use_only_visible_subscriptions_and_do_not_include_debt(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'box_number' => 'BOX-001']);
        MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'box_number' => 'BOX-002']);
        $debtor = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id]);
        $creditor = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'status' => SubscriptionStatus::Disconnected]);
        SubscriptionTransaction::recordCharge($debtor, $actor, ChargeType::Penalty, '100', 'Charge');
        SubscriptionTransaction::recordPayment($debtor, $actor, ['amount' => '20', 'currency' => 'ILS', 'payment_method' => 'cash']);
        SubscriptionTransaction::recordPayment($creditor, $actor, ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash']);
        Subscription::factory()->create(['meter_box_id' => $box->id]);

        $this->actingAs($actor)->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page
            ->where('summary', ['total' => 2, 'subscriptions' => 2, 'active' => 1, 'empty' => 1, 'regions' => 0, 'located' => 0])
            ->where('meterBoxes.total', 2)
            ->where('meterBoxes.data.0.subscriptionsCount', 2)
            ->where('meterBoxes.data.0.activeSubscriptionsCount', 1)
            ->missing('meterBoxes.data.0.debt')
            ->missing('meterBoxes.data.0.subscriptions'));

        $this->get(route('meter-boxes.index', ['search' => 'BOX-002']))->assertInertia(fn ($page) => $page
            ->where('summary', ['total' => 1, 'subscriptions' => 0, 'active' => 0, 'empty' => 1, 'regions' => 0, 'located' => 0]));
    }

    public function test_expanded_rows_show_subscription_identity_and_latest_reading_without_financial_balances(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $subscription = Subscription::factory()->create([
            'branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'full_name' => 'Personal name',
            'subscription_name' => 'Subscription name', 'initial_reading' => 0,
        ]);
        Subscription::factory()->create(['branch_id' => $actor->branch_id]);
        SubscriptionTransaction::recordPayment($subscription, $actor, ['amount' => '50', 'currency' => 'ILS', 'payment_method' => 'cash']);

        $this->actingAs($actor)->getJson(route('meter-boxes.subscriptions.index', $box))->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.display_name', 'Subscription name')
            ->assertJsonPath('data.0.account_number', $subscription->account_number)
            ->assertJsonMissingPath('data.0.outstandingBalance')
            ->assertJsonPath('data.0.lastReading', 0)
            ->assertJsonPath('data.0.statementUrl', route('subscriptions.statement', $subscription, absolute: false));
    }

    public function test_subscription_rows_are_paginated_instead_of_loading_every_account(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $breaker = CircuitBreaker::factory()->create();
        Subscription::factory()->recycle($breaker)->count(16)
            ->sequence(...array_map(fn (int $number): array => ['subscription_name' => sprintf('مشترك %02d', $number)], range(1, 16)))
            ->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id]);

        $this->actingAs($actor)->getJson(route('meter-boxes.subscriptions.index', $box))
            ->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('total', 16)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.display_name', 'مشترك 01');
        $this->getJson(route('meter-boxes.subscriptions.index', [$box, 'page' => 2]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('current_page', 2)->assertJsonPath('data.0.display_name', 'مشترك 16');
        $this->getJson(route('meter-boxes.subscriptions.index', [$box, 'sort' => 'name', 'direction' => 'desc', 'page' => 2]))
            ->assertOk()->assertJsonPath('data.0.display_name', 'مشترك 01');
    }

    public function test_viewing_meter_boxes_alone_does_not_reveal_subscription_details_or_debt(): void
    {
        $actor = User::factory()->collector()->withPermissions([PermissionKey::ViewMeterBoxes])->create();
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id]);

        $this->actingAs($actor)->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page
            ->where('canViewSubscriptions', false)->missing('summary.debt')->missing('meterBoxes.data.0.debt'));
        $this->getJson(route('meter-boxes.subscriptions.index', $box))->assertForbidden();
    }

    public function test_subscription_access_alone_does_not_allow_opening_meter_box_rows(): void
    {
        $actor = User::factory()->collector()->withPermissions([PermissionKey::ViewSubscriptions])->create();
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);

        $this->actingAs($actor)->getJson(route('meter-boxes.subscriptions.index', $box))->assertForbidden();
    }

    public function test_another_branchs_meter_box_cannot_be_opened(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create();

        $this->actingAs($actor)->getJson(route('meter-boxes.subscriptions.index', $box))->assertNotFound();
    }

    public function test_meter_box_names_and_suffixes_sort_in_both_directions(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $last = MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'name' => 'ياء']);
        $second = MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'name' => 'ألف', 'name_suffix' => 'ياء']);
        $first = MeterBox::factory()->create(['branch_id' => $actor->branch_id, 'name' => 'ألف', 'name_suffix' => 'ألف']);

        $this->actingAs($actor)->get(route('meter-boxes.index', ['sort' => 'name', 'direction' => 'asc']))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$first->id, $second->id, $last->id]));
        $this->get(route('meter-boxes.index', ['sort' => 'name', 'direction' => 'desc']))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$last->id, $second->id, $first->id]));
    }

    public function test_a_selected_branch_supplies_its_own_area_and_only_its_subareas(): void
    {
        $governorate = Governorate::factory()->create();
        $area = Area::factory()->create(['governorate_id' => $governorate->id]);
        $branch = Branch::factory()->inArea($area)->create();
        $subArea = SubArea::factory()->create(['area_id' => $area->id]);
        SubArea::factory()->create(['area_id' => Area::factory()->create()->id]);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->get(route('meter-boxes.index', ['filter' => ['branch_id' => $branch->id]]))->assertInertia(fn ($page) => $page
            ->where('selectedBranch', ['id' => $branch->id, 'area_id' => $area->id])
            ->has('filterOptions.0.options', 1)->where('filterOptions.0.options.0.value', (string) $subArea->id));
        $this->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page
            ->where('selectedBranch', null)->has('filterOptions.0.options', 2));
    }

    public function test_box_name_and_number_filters_update_region_totals_without_including_other_branches(): void
    {
        $area = Area::factory()->create();
        $actor = User::factory()->branchAdmin()->for(Branch::factory()->inArea($area))->create();
        $firstArea = SubArea::factory()->for($area)->create();
        $secondArea = SubArea::factory()->for($area)->create();
        $first = MeterBox::factory()->for($actor->branch)->create(['name' => 'السوق', 'name_suffix' => 'أ', 'sub_area_id' => $firstArea->id]);
        $second = MeterBox::factory()->for($actor->branch)->create(['name' => 'السوق', 'name_suffix' => 'ب', 'sub_area_id' => $firstArea->id]);
        MeterBox::factory()->for($actor->branch)->create(['name' => 'المدرسة', 'sub_area_id' => $secondArea->id]);
        MeterBox::factory()->for($actor->branch)->create(['name' => 'بدون منطقة']);
        $foreign = MeterBox::factory()->create(['name' => 'السوق', 'sub_area_id' => $firstArea->id]);

        $this->actingAs($actor)->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.total', 4)->where('summary.regions', 2)->where('summary.located', 3)
            ->has('filterOptions.1.options', 3)->has('filterOptions.2.options', 0));
        $this->get(route('meter-boxes.index', ['filter' => ['meter_box_name' => 'السوق']]))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.total', 2)->where('summary.regions', 1)->where('summary.located', 2)
            ->has('filterOptions.2.options', 2));
        $this->get(route('meter-boxes.index', ['filter' => ['meter_box_name' => 'السوق', 'meter_box_id' => $second->id]]))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.total', 1)->where('meterBoxes.data.0.id', $second->id)->where('summary.located', 1));
        $this->get(route('meter-boxes.index', ['filter' => ['meter_box_id' => $first->id]]))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.total', 1)->where('meterBoxes.data.0.id', $first->id)->has('filterOptions.2.options', 2));
        $this->get(route('meter-boxes.index', ['filter' => ['sub_area_id' => $secondArea->id]]))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.total', 1)->where('summary.regions', 1)->where('summary.located', 1));
        $this->get(route('meter-boxes.index', ['filter' => ['meter_box_id' => $foreign->id]]))->assertInertia(fn ($page) => $page
            ->where('meterBoxes.total', 0)->where('summary.regions', 0)->where('summary.located', 0));
    }

    public function test_subscribers_sort_by_the_displayed_name_or_account_number_with_validated_directions(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $last = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'full_name' => 'سالم']);
        $second = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'full_name' => 'باسم']);
        $first = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'full_name' => 'زياد', 'subscription_name' => 'أحمد']);
        $url = route('meter-boxes.subscriptions.index', $box);

        $this->actingAs($actor)->getJson($url.'?sort=name&direction=asc')->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.2.id', $last->id);
        $this->getJson($url.'?sort=name&direction=desc')->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.2.id', $first->id);
        $this->getJson($url.'?sort=account_number&direction=asc')->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.1.id', $second->id);
        $this->getJson($url.'?sort=name&direction=invalid')->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->getJson($url.'?sort=unknown')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_empty_meter_boxes_and_missing_authentication_have_clear_responses(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $box = MeterBox::factory()->create();
        $this->getJson(route('meter-boxes.subscriptions.index', $box))->assertUnauthorized();

        $this->actingAs($actor)->getJson(route('meter-boxes.subscriptions.index', $box))
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }
}
