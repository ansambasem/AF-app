<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\ActionCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_record_adds_it_by_name_to_the_users_recent_activity(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('governorates.store'), ['name' => 'Baghdad'])
            ->assertSessionHasNoErrors();

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('activity.unreadCount', 1)
                ->has('activity.recent', 1)
                ->where('activity.recent.0.action', 'governorate-created')
                ->where('activity.recent.0.subject', 'Baghdad')
                ->where('activity.recent.0.read', false));
    }

    public function test_a_saved_circuit_breaker_is_named_by_its_translated_ampere_rating(): void
    {
        app()->setLocale('ar');
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('circuit-breakers.store'), ['ampere' => 20, 'minimum_payment' => 15])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['action' => 'circuit-breaker-created', 'subject' => '20 أمبير'],
            $superAdmin->notifications()->sole()->data,
        );
    }

    public function test_a_setting_saved_without_a_record_name_is_listed_without_a_subject(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->put(route('settings.reading-schedule.update'), ['reading_day' => 4, 'open_days' => [4], 'mode' => 'automatic'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['action' => 'reading-schedule-updated', 'subject' => null],
            $superAdmin->notifications()->sole()->data,
        );
    }

    public function test_a_save_that_fails_validation_does_not_add_to_recent_activity(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('governorates.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $superAdmin->notifications()->count());
    }

    public function test_recent_activity_lists_only_the_users_own_ten_newest_actions(): void
    {
        $user = User::factory()->superAdmin()->create();
        $otherUser = User::factory()->superAdmin()->create();
        $otherUser->notify(new ActionCompleted('branch-created', 'Someone else'));

        foreach (range(1, 12) as $number) {
            $this->travel(1)->minutes();
            $user->notify(new ActionCompleted('branch-created', "Branch {$number}"));
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('activity.unreadCount', 12)
                ->has('activity.recent', 10)
                ->where('activity.recent.0.subject', 'Branch 12')
                ->where('activity.recent.9.subject', 'Branch 3'));
    }

    public function test_opening_the_bell_marks_only_the_users_own_actions_as_read(): void
    {
        $user = User::factory()->superAdmin()->create();
        $otherUser = User::factory()->superAdmin()->create();
        $user->notify(new ActionCompleted('branch-created', 'Mine'));
        $otherUser->notify(new ActionCompleted('branch-created', 'Theirs'));

        $this->actingAs($user)
            ->post(route('notifications.read'))
            ->assertNoContent();

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $otherUser->unreadNotifications()->count());
    }

    public function test_guest_cannot_mark_notifications_as_read(): void
    {
        $this->post(route('notifications.read'))->assertRedirect(route('login'));
    }
}
