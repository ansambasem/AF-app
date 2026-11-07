<?php

namespace Tests\Feature\Printing;

use App\Models\MeterBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintAllRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_printing_every_row_sends_all_matching_rows_in_the_tables_order(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        MeterBox::factory()->count(20)->sequence(fn ($sequence) => ['box_number' => sprintf('BOX-%02d', $sequence->index + 1)])->create();

        $this->actingAs($superAdmin)
            ->get(route('meter-boxes.index', ['sort' => 'box_number', 'direction' => 'desc', 'print' => 1, 'print_all' => 1]))
            ->assertInertia(fn ($page) => $page
                ->has('meterBoxes.data', 20)
                ->where('meterBoxes.data.0.box_number', 'BOX-20')
                ->where('meterBoxes.data.19.box_number', 'BOX-01'));
    }

    public function test_printing_only_the_page_on_screen_keeps_the_page_size(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        MeterBox::factory()->count(20)->create();

        $this->actingAs($superAdmin)
            ->get(route('meter-boxes.index', ['print' => 1, 'per_page' => 15]))
            ->assertInertia(fn ($page) => $page->has('meterBoxes.data', 15));
    }
}
