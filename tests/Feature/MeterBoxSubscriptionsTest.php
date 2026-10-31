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
        $this->get(route('meter-boxes.index', ['filter' => ['branch_id' => '']]))->assertInertia(fn ($page) => $page
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

    public function test_account_numbers_sort_by_their_value_when_their_lengths_differ(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $box = MeterBox::factory()->create(['branch_id' => $actor->branch_id]);
        $longer = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'account_number' => '100']);
        $shorter = Subscription::factory()->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'account_number' => '99']);

        $this->actingAs($actor)->getJson(route('meter-boxes.subscriptions.index', [$box, 'sort' => 'account_number']))
            ->assertJsonPath('data.0.id', $shorter->id)->assertJsonPath('data.1.id', $longer->id);
    }

    public function test_grouping_by_region_lists_each_regions_boxes_together_with_unplaced_boxes_last(): void
    {
        $area = Area::factory()->create();
        $actor = User::factory()->branchAdmin()->for(Branch::factory()->inArea($area))->create();
        $market = SubArea::factory()->for($area)->create(['name' => 'السوق']);
        $camp = SubArea::factory()->for($area)->create(['name' => 'المخيم']);
        $unplaced = MeterBox::factory()->for($actor->branch)->create(['box_number' => '1']);
        $campBox = MeterBox::factory()->for($actor->branch)->create(['box_number' => '2', 'sub_area_id' => $camp->id]);
        $marketBox = MeterBox::factory()->for($actor->branch)->create(['box_number' => '3', 'sub_area_id' => $market->id]);

        $this->actingAs($actor)->get(route('meter-boxes.index', ['group' => 'region']))->assertInertia(fn ($page) => $page
            ->where('groupByRegion', true)
            ->where('meterBoxes.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$marketBox->id, $campBox->id, $unplaced->id]));
        $this->get(route('meter-boxes.index'))->assertInertia(fn ($page) => $page
            ->where('groupByRegion', false)
            ->where('meterBoxes.data', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$unplaced->id, $campBox->id, $marketBox->id]));
    }

    public function test_boxes_print_with_their_subscribers_under_their_region_in_the_chosen_order(): void
    {
        $area = Area::factory()->create();
        $actor = User::factory()->branchAdmin()->for(Branch::factory()->inArea($area))->create();
        $camp = SubArea::factory()->for($area)->create(['name' => 'المخيم']);
        $box = MeterBox::factory()->for($actor->branch)->create(['name' => 'ابو ذر', 'name_suffix' => '8', 'box_number' => '205883', 'sub_area_id' => $camp->id]);
        $unplaced = MeterBox::factory()->for($actor->branch)->create(['name' => 'الجامع', 'box_number' => '100']);
        MeterBox::factory()->for($actor->branch)->create(['name' => 'فارغ', 'box_number' => '300']);
        $breaker = CircuitBreaker::factory()->create(['ampere' => 16]);
        Subscription::factory()->recycle($breaker)->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'full_name' => 'باسم', 'initial_reading' => 120]);
        Subscription::factory()->recycle($breaker)->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $box->id, 'full_name' => 'سالم', 'phone' => '0599000000']);
        Subscription::factory()->recycle($breaker)->create(['branch_id' => $actor->branch_id, 'meter_box_id' => $unplaced->id, 'full_name' => 'أحمد']);
        Subscription::factory()->recycle($breaker)->create(['meter_box_id' => $box->id, 'full_name' => 'من فرع آخر']);
        MeterBox::factory()->create(['name' => 'ابو ذر', 'box_number' => '999']);

        $this->actingAs($actor)->get(route('meter-boxes.subscriptions.print', ['print' => 1, 'search' => 'ابو', 'subscription_direction' => 'desc']))
            ->assertInertia(fn ($page) => $page->component('Print/ReadingReport')
                ->where('printData.total', 2)
                ->has('printData.rows', 2)
                ->where('printData.rows.0.cells.المشترك', 'سالم')
                ->where('printData.rows.0.cells.#', '1')
                ->where('printData.rows.0.cells.field:phone', '0599000000')
                ->where('printData.rows.1.cells.المشترك', 'باسم')
                ->where('printData.rows.1.cells.#', '2')
                ->where('printData.rows.1.cells.القراءة السابقة', '120')
                ->where('printData.rows.1.cells.القاطع', '16 أمبير')
                ->where('printData.rows.1.cells.field:subAreaName', 'المخيم')
                ->where('printData.rows.1.cells.field:boxLabel', 'ابو ذر 8 — 205883')
                ->where('printData.context.templatePage', '/meter-boxes/subscriptions-print')
                ->where('printData.defaults.group', ['key' => 'field:subAreaName', 'then' => ['field:boxLabel'], 'headings' => 'stacked', 'align' => 'center', 'labels' => false, 'subtotals' => false, 'unit' => 'مشترك', 'repeatHeader' => true]));

        $this->get(route('meter-boxes.subscriptions.print', ['print' => 1]))->assertInertia(fn ($page) => $page
            ->where('printData.total', 3)
            ->where('printData.rows', fn ($rows): bool => collect($rows)->pluck('cells.field:subAreaName')->all() === ['بلا منطقة', 'المخيم', 'المخيم'])
            ->where('printData.summary', '2 طبلون · 3 مشترك · 1 طبلون بلا مشتركين لم يُطبع'));
        $this->get(route('meter-boxes.subscriptions.print', ['print' => 1, 'subscription_sort' => 'phone']))->assertSessionHasErrors('subscription_sort');
        $this->get(route('meter-boxes.subscriptions.print', ['search' => 'ابو']))->assertRedirect(route('meter-boxes.index', ['search' => 'ابو']));
    }

    public function test_printing_boxes_with_subscribers_needs_access_to_both(): void
    {
        $boxesOnly = User::factory()->collector()->withPermissions([PermissionKey::ViewMeterBoxes])->create();
        $subscriptionsOnly = User::factory()->collector()->withPermissions([PermissionKey::ViewSubscriptions])->create();

        $this->actingAs($boxesOnly)->get(route('meter-boxes.subscriptions.print', ['print' => 1]))->assertForbidden();
        $this->actingAs($subscriptionsOnly)->get(route('meter-boxes.subscriptions.print', ['print' => 1]))->assertForbidden();
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
