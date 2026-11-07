<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use App\Models\SubArea;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * The service area: the governorate, its area (منطقة 1) and that area's
     * sub-areas (منطقة 2).
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const LOCATIONS = [
        'الوسطى' => [
            'مخيم 2' => [
                'مدارس جديد', 'الحاج', 'الابراج', 'ابو ذر', 'ابو هويشل', 'ابو رحمة', 'الفقي', 'الشافعي', 'ابو عمرة',
                'زهرة الربيع', 'مكتبة خالد', 'المدارس', 'الحملاوي', 'النادي الأهلي', 'أرض الحلو', 'ابراج (شارع الحسنات)',
                'ابراج (مسجد النور)', 'الابراج (شمال الابراج)', 'ابراج (جنوب الابراج)', 'شوافعة ابو بطيحان',
                'شوافعة ابو عبيدة', 'شوافعة شرقية', 'شوافعة حارة الشافعي', 'حميدة', 'دوار ابو انور', 'الخوالدة',
                'المقبرة', 'شمال المقبرة', 'درويش مدخل', 'درويش عاشور', 'درويش مسجد السنة', 'شوافعة الاهرام',
                'الشقاقي', 'دوار ريان', 'فنونة',
            ],
        ],
    ];

    /**
     * Seed the locations, keeping their identifiers and correcting the governorate.
     */
    public function run(): void
    {
        foreach (self::LOCATIONS as $governorateName => $areas) {
            $governorate = Governorate::firstOrCreate(['name' => $governorateName]);

            foreach ($areas as $areaName => $subAreaNames) {
                $area = Area::updateOrCreate(['name' => $areaName], ['governorate_id' => $governorate->id]);

                foreach ($subAreaNames as $subAreaName) {
                    SubArea::firstOrCreate(['name' => $subAreaName], ['area_id' => $area->id]);
                }
            }
        }
    }

    /**
     * The مخيم 2 branch the area's boxes and subscriptions belong to: the one
     * named «مخيم 2» (or whose name contains it), else the one working in
     * the area. If there is none yet, it is created.
     */
    public static function branch(): Branch
    {
        $area = Area::query()->where('name', 'مخيم 2')->firstOrFail();
        $named = Branch::query()->where('seed_key', 'camp-two')->orWhere('name', 'like', '%مخيم 2%')->get();

        return $named->firstWhere('seed_key', 'camp-two')
            ?? $named->firstWhere('name', 'مخيم 2')
            ?? ($named->count() === 1 ? $named->first() : null)
            ?? Branch::query()->where('area_id', $area->id)->first()
            ?? Branch::query()->create(['name' => 'مكتب مخيم 2', 'governorate_id' => $area->governorate_id, 'area_id' => $area->id]);
    }
}
