<?php

use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\MeterBox;
use App\Models\SubArea;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserType;

$governorate = Governorate::factory()->create(['name' => 'محافظة التجربة']);
$owner = User::query()->where('username', 'owner')->sole();

foreach (['ألف', 'باء'] as $index => $name) {
    $area = Area::factory()->for($governorate)->create(['name' => "منطقة {$name} الإدارية"]);
    $branch = Branch::factory()->create(['name' => "فرع {$name}", 'governorate_id' => $governorate->id, 'area_id' => $area->id]);
    $subArea = $index === 0 ? SubArea::factory()->for($area)->create(['name' => "حي {$name}"]) : null;
    $box = MeterBox::factory()->for($branch)->create(['name' => $index === 0 ? 'ألف' : 'ياء', 'name_suffix' => null, 'box_number' => $index === 0 ? '100' : '200', 'sub_area_id' => $subArea?->id]);
    if ($index === 0) {
        $secondBox = MeterBox::factory()->for($branch)->create(['name' => 'باء', 'name_suffix' => null, 'box_number' => '110', 'sub_area_id' => $subArea->id]);
        foreach (['سالم', 'باسم'] as $subscriberName) {
            Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $secondBox->id, 'registered_by' => $owner->id, 'subscription_name' => $subscriberName]);
        }
        foreach (['زياد', 'أحمد', 'باسم'] as $subscriberName) {
            Subscription::factory()->create(['branch_id' => $branch->id, 'meter_box_id' => $box->id, 'registered_by' => $owner->id, 'subscription_name' => $subscriberName]);
        }
    }
}

for ($number = 1; $number <= 16; $number++) {
    UserType::factory()->create(['name' => sprintf('نوع %02d', $number)]);
}
