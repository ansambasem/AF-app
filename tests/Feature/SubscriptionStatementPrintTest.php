<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\PermissionKey;
use App\Models\PrintTemplate;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionStatementPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_printing_keeps_full_history_and_balances_with_the_requested_view_and_filters(): void
    {
        $actor = User::factory()->branchAdmin()->create();
        $subscription = Subscription::factory()->for($actor->branch)->create();
        $charge = SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::Penalty, '50', 'غرامة قديمة');
        SubscriptionTransaction::recordCharge($subscription, $actor, ChargeType::DisconnectionFee, '20', null);
        $this->actingAs($actor)->post(route('subscriptions.transactions.actions.store', [$subscription, $charge]), [
            'action' => 'cancel', 'correction_reason' => 'other', 'correction_notes' => 'أُضيفت بالخطأ',
        ])->assertSessionHasNoErrors();

        $this->get(route('subscriptions.statement.print', [
            'subscription' => $subscription->id, 'print' => 1, 'view' => 'compact',
            'filter' => ['type' => 'debit', 'search' => 'رسوم', 'dateFrom' => '2026-01-01', 'sort' => 'bank_desc'], 'history' => [$charge->id],
        ]))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Subscriptions/StatementPrint')->where('subscription.id', $subscription->id)
            ->has('entries', 3)->where('entries.0.id', $charge->id)->where('entries.0.cancellation.notes', 'أُضيفت بالخطأ')
            ->where('summary.balance', '20.00')->where('printOptions.view', 'compact')
            ->where('printOptions.filters', ['search' => 'رسوم', 'type' => 'debit', 'method' => '', 'dateFrom' => '2026-01-01', 'dateTo' => '', 'sort' => 'bank_desc'])
            ->where('printOptions.openHistories', [$charge->id])->where('printTemplates', []));
        $this->assertDatabaseCount('subscription_transactions', 3);
    }

    public function test_printing_is_scoped_to_the_readers_branch_and_auditors_can_read_other_branches(): void
    {
        $reader = User::factory()->branchAdmin()->create();
        $local = Subscription::factory()->for($reader->branch)->create();
        $foreign = Subscription::factory()->create();
        $auditor = User::factory()->financialAuditor()->create();
        $this->actingAs($reader)->get(route('subscriptions.statement.print', ['subscription' => $local->id, 'print' => 1]))->assertOk();
        $this->get(route('subscriptions.statement.print', ['subscription' => $foreign->id]))->assertNotFound();
        $this->get(route('subscriptions.statement.print', ['print' => 1]))->assertInertia(fn ($page) => $page->where('subscription.id', $local->id));
        $this->actingAs($auditor)->get(route('subscriptions.statement.print', ['subscription' => $foreign->id, 'print' => 1]))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Subscriptions/StatementPrint')->where('subscription.id', $foreign->id)->where('printOptions.view', 'full'));
    }

    public function test_printing_requires_authentication_and_statement_access(): void
    {
        $this->get(route('subscriptions.statement.print'))->assertRedirect(route('login'));
        $reader = User::factory()->collector()->create();
        $reader->permissions()->sync([]);
        $this->actingAs($reader)->get(route('subscriptions.statement.print'))->assertForbidden();
    }

    public function test_invalid_print_options_are_refused(): void
    {
        $reader = User::factory()->branchAdmin()->create();
        $this->actingAs($reader)->getJson(route('subscriptions.statement.print', [
            'subscription' => 'invalid', 'view' => 'invalid', 'filter' => ['dateFrom' => 'not-a-date', 'sort' => 'invalid'], 'history' => ['invalid'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['subscription', 'view', 'filter.dateFrom', 'filter.sort', 'history.0']);
    }

    public function test_shared_statement_layouts_can_be_saved_and_used_for_different_subscribers(): void
    {
        $manager = User::factory()->superAdmin()->create();
        $layout = ['paper' => 'A3', 'orientation' => 'landscape', 'columns' => [
            ['key' => 'المبلغ', 'label' => 'القيمة', 'visible' => true],
        ], 'header' => ['title' => 'كشف حساب', 'reportFields' => ['subscriber', 'account']], 'footer' => ['signatures' => ['المحاسب']]];
        $this->actingAs($manager)->post(route('print-templates.store'), [
            'page' => '/subscriptions/statement-print', 'name' => 'كشف حساب مخصص', 'layout' => $layout, 'is_default' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame($layout, PrintTemplate::query()->sole()->layout);
        $reader = User::factory()->branchAdmin()->create();
        $first = Subscription::factory()->for($reader->branch)->create();
        $second = Subscription::factory()->for($reader->branch)->create();
        foreach ([$first, $second] as $subscription) {
            $this->actingAs($reader)->get(route('subscriptions.statement.print', ['subscription' => $subscription->id, 'print' => 1]))->assertInertia(fn ($page) => $page
                ->where('subscription.id', $subscription->id)->where('printTemplates.0.name', 'كشف حساب مخصص')
                ->where('printTemplates.0.isDefault', true)->where('printTemplates.0.layout', $layout)->where('can.managePrintTemplates', false));
        }
        $this->post(route('print-templates.store'), ['page' => '/subscriptions/statement-print', 'name' => 'غير مسموح', 'layout' => $layout])->assertForbidden();
    }

    public function test_template_design_can_open_with_no_readable_subscribers(): void
    {
        $manager = User::factory()->branchAdmin()->withPermissions([PermissionKey::ManagePrintTemplates])->create();
        Subscription::factory()->create();
        $this->actingAs($manager)->get(route('subscriptions.statement.print', ['print' => 1]))->assertInertia(fn ($page) => $page
            ->component('Subscriptions/StatementPrint')->where('subscription', null)->has('entries', 0)
            ->where('printOptions.view', 'full')->where('can.managePrintTemplates', true));
    }
}
