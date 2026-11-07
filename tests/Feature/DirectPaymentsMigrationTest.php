<?php

namespace Tests\Feature;

use App\Models\AuditNote;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectPaymentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_14_103308_move_direct_payments_back_to_their_recorders.php');
    }

    /** @param array<string, mixed> $attributes */
    private function box(Branch $branch, Closing $closing, ?User $owner, array $attributes = []): int
    {
        return DB::table('cash_boxes')->insertGetId([
            'number' => 'BOX-'.Str::ulid(), 'branch_id' => $branch->id, 'user_id' => $owner?->id, 'closing_id' => $closing->id,
            'kind' => $owner ? 'staff' : 'direct', 'type' => 'bank_transfer', 'status' => 'open', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(), ...$attributes,
        ]);
    }

    private function entry(Branch $branch, User $recorder, int $boxId): CashBoxPayment
    {
        $transaction = SubscriptionTransaction::withoutEvents(fn () => SubscriptionTransaction::factory()->for(Subscription::factory()->for($branch))->create([
            'branch_id' => $branch->id,
            'recorded_by' => $recorder->id, 'type' => 'payment', 'source_key' => 'payment:'.Str::ulid(),
            'amount' => '-100', 'currency_amount' => '100', 'currency' => 'ILS', 'payment_method' => 'bank_transfer',
        ]));

        return CashBoxPayment::factory()->create(['cash_box_id' => $boxId, 'subscription_transaction_id' => $transaction->id, 'method' => 'bank_transfer', 'status' => 'pending']);
    }

    public function test_an_open_direct_box_returns_each_transfer_to_its_recorders_open_box_or_a_new_one(): void
    {
        $branch = Branch::factory()->create();
        $closing = Closing::factory()->for($branch)->create();
        [$sami, $layla] = User::factory()->collector()->for($branch)->count(2)->create();
        $samiBox = $this->box($branch, $closing, $sami, ['open_key' => $branch->id.':'.$sami->id.':bank_transfer']);
        $direct = $this->box($branch, $closing, null, ['open_key' => $branch->id.':direct:bank_transfer']);
        $fromSami = $this->entry($branch, $sami, $direct);
        $fromLayla = $this->entry($branch, $layla, $direct);
        $note = AuditNote::factory()->create(['closing_id' => $closing->id, 'cash_box_id' => $direct]);

        $this->migration()->up();

        $this->assertDatabaseMissing('cash_boxes', ['id' => $direct]);
        $this->assertSame(0, CashBox::query()->where('kind', 'direct')->count());
        $this->assertSame($samiBox, $fromSami->fresh()->cash_box_id);
        $laylaBox = $fromLayla->fresh()->box;
        $this->assertSame($layla->id, $laylaBox->user_id);
        $this->assertSame('staff', $laylaBox->kind);
        $this->assertSame('open', $laylaBox->status);
        $this->assertSame($branch->id.':'.$layla->id.':bank_transfer', $laylaBox->open_key);
        $this->assertSame((string) $laylaBox->id, $laylaBox->number);
        $this->assertSame($samiBox, $note->fresh()->cash_box_id);
    }

    public function test_a_closed_direct_box_becomes_closed_boxes_of_its_recorders_and_open_boxes_are_left_alone(): void
    {
        $branch = Branch::factory()->create();
        $closing = Closing::factory()->for($branch)->create();
        $sami = User::factory()->collector()->for($branch)->create();
        $samiOpen = $this->box($branch, $closing, $sami, ['open_key' => $branch->id.':'.$sami->id.':bank_transfer']);
        $direct = $this->box($branch, $closing, null, ['status' => 'received', 'closed_at' => now(), 'received_at' => now(), 'confirmed_at' => now(), 'received_in_closing_id' => $closing->id]);
        $entry = $this->entry($branch, $sami, $direct);

        $this->migration()->up();

        $box = $entry->fresh()->box;
        $this->assertNotSame($samiOpen, $box->id);
        $this->assertSame($sami->id, $box->user_id);
        $this->assertSame('received', $box->status);
        $this->assertNull($box->open_key);
        $this->assertSame($closing->id, $box->received_in_closing_id);
        $this->assertSame(0, CashBox::query()->find($samiOpen)->payments()->count());
        $this->assertDatabaseMissing('cash_boxes', ['id' => $direct]);
    }
}
