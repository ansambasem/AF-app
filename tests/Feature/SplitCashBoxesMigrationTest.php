<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBoxPayment;
use App\Models\Closing;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SplitCashBoxesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_12_062207_split_cash_boxes_by_type.php');
    }

    /** @param array<string, mixed> $attributes */
    private function oldBox(Branch $branch, User $collector, Closing $closing, array $attributes = []): int
    {
        return DB::table('cash_boxes')->insertGetId([
            'number' => 'BOX-'.Str::ulid(), 'branch_id' => $branch->id, 'user_id' => $collector->id,
            'closing_id' => $closing->id, 'kind' => 'staff', 'status' => 'open', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(), ...$attributes,
        ]);
    }

    private function entry(Branch $branch, User $collector, int $boxId, string $method): CashBoxPayment
    {
        $transaction = SubscriptionTransaction::withoutEvents(fn () => SubscriptionTransaction::factory()->for(Subscription::factory()->for($branch))->create([
            'branch_id' => $branch->id,
            'recorded_by' => $collector->id, 'type' => 'payment', 'source_key' => 'payment:'.Str::ulid(),
            'amount' => '-100', 'currency_amount' => '100', 'currency' => 'ILS', 'payment_method' => $method,
        ]));

        return CashBoxPayment::factory()->create(['cash_box_id' => $boxId, 'subscription_transaction_id' => $transaction->id, 'method' => $method, 'status' => $method === 'cash' ? 'cash' : 'pending']);
    }

    public function test_existing_boxes_are_split_and_closed_submissions_are_backfilled(): void
    {
        $this->freezeTime();
        $migration = $this->migration();
        $migration->down();
        $branch = Branch::factory()->create();
        $collector = User::factory()->collector()->for($branch)->create();
        $other = User::factory()->collector()->for($branch)->create();
        $closing = Closing::factory()->for($branch)->create();
        $key = $branch->id.':'.$collector->id;
        $counts = json_encode(['ILS' => '100', 'USD' => '0', 'JOD' => '0']);
        $open = $this->oldBox($branch, $collector, $closing, ['open_key' => $key]);
        $received = $this->oldBox($branch, $collector, $closing, ['status' => 'received', 'closed_at' => now(), 'received_at' => now(), 'collector_counts' => $counts, 'head_counts' => $counts, 'expected_counts' => $counts, 'decisions' => json_encode(['ILS' => 'accepted']), 'reason' => 'old reason']);
        $transfer = $this->oldBox($branch, $other, $closing, ['open_key' => $branch->id.':'.$other->id]);
        $cash = $this->entry($branch, $collector, $open, 'cash');
        $bank = $this->entry($branch, $collector, $open, 'bank_transfer');
        $receivedCash = $this->entry($branch, $collector, $received, 'cash');
        $receivedBank = $this->entry($branch, $collector, $received, 'bank_transfer');
        $this->entry($branch, $other, $transfer, 'bank_transfer');
        $awaiting = $this->oldBox($branch, $collector, $closing, ['status' => 'pending', 'closed_at' => now()]);
        $empty = $this->oldBox($branch, $collector, $closing);
        $direct = $this->oldBox($branch, $collector, $closing, ['kind' => 'direct', 'user_id' => null, 'open_key' => $branch->id.':direct']);

        $migration->up();

        $this->assertDatabaseCount('cash_boxes', 8);
        $this->assertDatabaseHas('cash_boxes', ['id' => $open, 'type' => 'cash', 'open_key' => $key.':cash', 'collector_submitted_at' => null]);
        $this->assertSame($open, $cash->fresh()->cash_box_id);
        $bankBox = $bank->fresh()->cash_box_id;
        $this->assertNotSame($open, $bankBox);
        $this->assertDatabaseHas('cash_boxes', ['id' => $bankBox, 'type' => 'bank_transfer', 'branch_id' => $branch->id, 'user_id' => $collector->id, 'closing_id' => $closing->id, 'open_key' => $key.':bank_transfer', 'status' => 'open', 'collector_submitted_at' => null]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $received, 'type' => 'cash', 'collector_submitted_at' => now(), 'reason' => 'old reason']);
        $this->assertSame($received, $receivedCash->fresh()->cash_box_id);
        $this->assertDatabaseHas('cash_boxes', ['id' => $receivedBank->fresh()->cash_box_id, 'type' => 'bank_transfer', 'status' => 'received', 'collector_submitted_at' => now(), 'confirmed_at' => now(), 'collector_counts' => null, 'head_counts' => null, 'decisions' => null, 'reason' => null]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $transfer, 'type' => 'bank_transfer', 'open_key' => $branch->id.':'.$other->id.':bank_transfer']);
        $this->assertDatabaseHas('cash_boxes', ['id' => $awaiting, 'type' => 'cash', 'collector_submitted_at' => null]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $empty, 'type' => 'cash']);
        $this->assertDatabaseHas('cash_boxes', ['id' => $direct, 'type' => 'bank_transfer', 'open_key' => $branch->id.':direct:bank_transfer']);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('cash_boxes', 'type'));
        $this->assertDatabaseHas('cash_boxes', ['id' => $open, 'open_key' => $key]);
        $this->assertDatabaseHas('cash_boxes', ['id' => $bankBox, 'open_key' => null]);
        $this->assertDatabaseCount('cash_boxes', 8);
    }
}
