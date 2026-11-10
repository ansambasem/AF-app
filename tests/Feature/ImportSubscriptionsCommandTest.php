<?php

namespace Tests\Feature;

use App\Enums\AccountingType;
use App\Models\Area;
use App\Models\Branch;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ImportSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "subscription_number,name,phone_number,balance,subscription_type,minimum_limit,area\n";

    private const HEADER_WITH_BOX = "subscription_number,name,phone_number,balance,subscription_type,minimum_limit,area,box_number\n";

    private Area $area;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create();
        $this->user = User::factory()->dataEntry()->create([
            'branch_id' => Branch::factory()->inArea($this->area)->create()->id,
        ]);
        Tariff::factory()->residential()->create();
    }

    private function csv(string $rows, string $header = self::HEADER): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, $header.$rows);

        return $path;
    }

    private function import(string $rows, array $options = [], string $header = self::HEADER): PendingCommand
    {
        return $this->artisan('subscriptions:import', ['file' => $this->csv($rows, $header), '--user' => $this->user->username, ...$options]);
    }

    private function realBox(string $number, ?Branch $branch = null): MeterBox
    {
        return MeterBox::factory()->create([
            'box_number' => $number,
            'branch_id' => ($branch ?? $this->user->branch)->id,
            'sub_area_id' => SubArea::factory()->create(['area_id' => $this->area->id])->id,
        ]);
    }

    public function test_it_imports_subscriptions_into_the_users_branch_with_their_balance(): void
    {
        $this->import("129600,اسامة فضل,0599013094,123.05,منزلي - أسبوعي,20,شواقفة حارة الشافعي\n129608,جهاد رائد,0599833619,-0.10,منزلي - أسبوعي,20,\n129620,وديع احمد,0592015201,0,منزلي - أسبوعي,60,شواقفة حارة الشافعي\n")
            ->assertSuccessful();

        $first = Subscription::query()->where('legacy_number', '129600')->sole();
        $this->assertSame($this->user->branch_id, $first->branch_id);
        $this->assertSame($this->user->id, $first->registered_by);
        $this->assertSame('0599013094', $first->phone);
        $this->assertEquals(20, $first->minimum_charge);
        $this->assertSame(123.05, $first->balance());
        $this->assertSame(AccountingType::Weekly, $first->accounting_type);

        $credit = Subscription::query()->where('legacy_number', '129608')->sole();
        $this->assertSame(-0.10, $credit->balance());

        $this->assertSame(0, Subscription::query()->where('legacy_number', '129620')->sole()->transactions()->count());
    }

    public function test_it_never_creates_meter_boxes_or_sub_areas(): void
    {
        $this->import("129600,اسامة فضل,0599013094,10,منزلي - أسبوعي,20,درويش\n129601,محمد يوسف,0592674067,5,منزلي - أسبوعي,20,درويش\n129602,عماد نويجع,0594106569,5,منزلي - أسبوعي,20,\n")
            ->assertSuccessful();

        $this->assertSame(3, Subscription::query()->count());
        $this->assertSame(0, MeterBox::query()->count());
        $this->assertSame(0, SubArea::query()->count());
        $this->assertSame([null], Subscription::query()->pluck('meter_box_id')->unique()->values()->all());
    }

    public function test_the_old_systems_area_is_kept_in_the_notes(): void
    {
        $this->import("129600,اسامة فضل,0599013094,10,منزلي - أسبوعي,20,شواقفة حارة الشافعي\n129602,عماد نويجع,0594106569,5,منزلي - أسبوعي,20,\n")
            ->assertSuccessful();

        $this->assertSame('المنطقة في النظام القديم: شواقفة حارة الشافعي', Subscription::query()->where('legacy_number', '129600')->sole()->notes);
        $this->assertNull(Subscription::query()->where('legacy_number', '129602')->sole()->notes);
    }

    public function test_a_subscription_number_equal_to_a_real_box_number_still_imports_and_leaves_the_box_alone(): void
    {
        $box = $this->realBox('129600');

        $this->import("129600,اسامة فضل,0599013094,10,منزلي - أسبوعي,20,درويش\n")->assertSuccessful();

        $this->assertNull(Subscription::query()->sole()->meter_box_id);
        $this->assertSame(1, MeterBox::query()->count());
        $this->assertSame(0, $box->subscriptions()->count());
    }

    public function test_a_row_with_a_box_number_joins_that_real_box(): void
    {
        $box = $this->realBox('208360');

        $this->import("129600,اسامة فضل,0599013094,10,منزلي - أسبوعي,20,درويش,208360\n129601,محمد يوسف,0592674067,5,منزلي - أسبوعي,20,درويش,\n", header: self::HEADER_WITH_BOX)
            ->assertSuccessful();

        $this->assertSame($box->id, Subscription::query()->where('legacy_number', '129600')->sole()->meter_box_id);
        $this->assertNull(Subscription::query()->where('legacy_number', '129601')->sole()->meter_box_id);
        $this->assertSame(1, MeterBox::query()->count());
    }

    public function test_a_box_number_that_is_not_a_box_of_the_branch_fails_that_row_only(): void
    {
        $this->realBox('208361', Branch::factory()->create());

        $this->import("129600,اسامة,0599013094,10,منزلي - أسبوعي,20,,999999\n129601,محمد,0592674067,5,منزلي - أسبوعي,20,,208361\n129602,عماد,0594106569,5,منزلي - أسبوعي,20,,\n", header: self::HEADER_WITH_BOX)
            ->expectsOutputToContain('Line 2 (129600): There is no meter box numbered «999999».')
            ->expectsOutputToContain('Line 3 (129601): The meter box «208361» belongs to another branch.')
            ->assertFailed();

        $this->assertSame(['129602'], Subscription::query()->pluck('legacy_number')->all());
    }

    public function test_phone_numbers_are_checked_like_the_subscription_form_and_tidied_first(): void
    {
        $this->import("1,اسامة,٠٥٩٩٠١٣٠٩٤,0,منزلي - أسبوعي,20,\n2,محمد,592 674-067,0,منزلي - أسبوعي,20,\n3,عماد,+970594106569,0,منزلي - أسبوعي,20,\n4,علي,,0,منزلي - أسبوعي,20,\n5,خالد,0591234,0,منزلي - أسبوعي,20,\n6,سامي,0589123456,0,منزلي - أسبوعي,20,\n")
            ->expectsOutputToContain('Line 6 (5): The phone number «0591234» is not a mobile number')
            ->expectsOutputToContain('Line 7 (6): The phone number «0589123456» is not a mobile number')
            ->assertFailed();

        $this->assertSame(
            ['1' => '0599013094', '2' => '0592674067', '3' => '0594106569', '4' => null],
            Subscription::query()->orderBy('id')->pluck('phone', 'legacy_number')->all(),
        );
    }

    public function test_a_number_or_name_that_does_not_fit_fails_the_row_instead_of_the_database(): void
    {
        $this->import(str_repeat('9', 21).",اسامة,0599013094,10,منزلي - أسبوعي,20,\n129601,".str_repeat('م', 256).",0592674067,5,منزلي - أسبوعي,20,\n129602,,0594106569,5,منزلي - أسبوعي,20,\n")
            ->expectsOutputToContain('The subscription number is longer than 20 characters.')
            ->expectsOutputToContain('The name is longer than 255 characters.')
            ->expectsOutputToContain('The subscription number and the name are required.')
            ->assertFailed();

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_a_monthly_subscription_type_sets_the_accounting_type_and_weekly_is_the_default(): void
    {
        $this->import("129600,اسامة,0599013094,10,منزلي - شهري,20,\n129601,محمد,0592674067,5,منزلي,20,\n")->assertSuccessful();

        $this->assertSame(AccountingType::Monthly, Subscription::query()->where('legacy_number', '129600')->sole()->accounting_type);
        $this->assertSame(AccountingType::Weekly, Subscription::query()->where('legacy_number', '129601')->sole()->accounting_type);
    }

    public function test_running_the_same_file_again_does_not_duplicate_anything(): void
    {
        $rows = "129600,اسامة فضل,0599013094,123.05,منزلي - أسبوعي,20,درويش\n";
        $this->import($rows)->assertSuccessful();

        $this->import($rows)->expectsOutputToContain('already imported 1')->assertSuccessful();

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(123.05, Subscription::sole()->balance());
    }

    public function test_a_bad_row_is_reported_and_the_others_are_still_imported(): void
    {
        $this->import("129600,اسامة,0599013094,10,نوع غريب,20,درويش\n129601,محمد,0592674067,5,منزلي - أسبوعي,20,درويش\n")
            ->expectsOutputToContain('Line 2 (129600)')
            ->assertFailed();

        $this->assertSame(['129601'], Subscription::query()->pluck('legacy_number')->all());
    }

    public function test_a_number_used_twice_in_the_file_fails_the_second_row_only(): void
    {
        $this->import("129600,اسامة,0599013094,10,منزلي - أسبوعي,20,\n129600,محمد,0592674067,5,منزلي - أسبوعي,20,\n")
            ->expectsOutputToContain('Line 3 (129600): The subscription number is already used on line 2.')
            ->assertFailed();

        $this->assertSame(['اسامة'], Subscription::query()->pluck('full_name')->all());
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->import("129600,اسامة,0599013094,10,منزلي - أسبوعي,20,درويش\n", ['--dry-run' => true])
            ->expectsOutputToContain('Checked 1')
            ->assertSuccessful();

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, MeterBox::query()->count());
        $this->assertSame(0, SubArea::query()->count());
    }

    public function test_a_dry_run_rejects_exactly_the_rows_the_real_run_rejects(): void
    {
        $this->realBox('208362', Branch::factory()->create());
        $rows = implode('', [
            "100,سليم,0599013094,10,منزلي - أسبوعي,20,,\n",
            "101,,0599013094,10,منزلي - أسبوعي,20,,\n",
            "102,منى,0599013094,10,نوع غريب,20,,\n",
            "103,ليث,0599013094,abc,منزلي - أسبوعي,20,,\n",
            "104,هدى,12345,10,منزلي - أسبوعي,20,,\n",
            "105,رائد,0599013094,10,منزلي - أسبوعي,20,,208362\n",
            "106,نور,0599013094,10,منزلي - أسبوعي,20,,777777\n",
            "100,سليم مرة ثانية,0599013094,10,منزلي - أسبوعي,20,,\n",
            "107,سعيد,0599013094,10,منزلي - أسبوعي,20,,\n",
        ]);

        $outputs = [];

        foreach (['dry' => ['--dry-run' => true], 'real' => []] as $mode => $options) {
            $exitCode = Artisan::call('subscriptions:import', ['file' => $this->csv($rows, self::HEADER_WITH_BOX), '--user' => $this->user->username, ...$options]);
            $lines = array_values(array_filter(preg_split('/\r?\n/', Artisan::output())));
            $outputs[$mode] = ['exit' => $exitCode, 'summary' => preg_replace('/^(Checked|Imported)/', 'Done', $lines[0]), 'failures' => array_slice($lines, 1)];
        }

        $this->assertSame($outputs['dry'], $outputs['real']);
        $this->assertSame(1, $outputs['real']['exit']);
        $this->assertSame('Done 2 · already imported 0 · failed 7', $outputs['real']['summary']);
        $this->assertSame(['100', '107'], Subscription::query()->orderBy('id')->pluck('legacy_number')->all());
    }

    public function test_a_dry_run_counts_rows_already_imported_as_skipped(): void
    {
        $this->import("129600,اسامة,0599013094,10,منزلي - أسبوعي,20,\n")->assertSuccessful();

        $this->import("129600,اسامة,0599013094,10,منزلي - أسبوعي,20,\n129601,محمد,0592674067,5,منزلي - أسبوعي,20,\n", ['--dry-run' => true])
            ->expectsOutputToContain('Checked 1 · already imported 1 · failed 0')
            ->assertSuccessful();

        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_it_needs_a_user_who_belongs_to_a_branch(): void
    {
        $this->user->update(['branch_id' => null]);

        $this->import("129600,اسامة,0599013094,10,منزلي - أسبوعي,20,درويش\n")->assertFailed();

        $this->assertSame(0, Subscription::query()->count());
    }
}
