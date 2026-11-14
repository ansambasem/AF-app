<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Branch;
use App\Models\SubArea;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * The مخيم 2 branch's area container (the former «المنطقة») and the
     * branch's areas in it (the «منطقة 2» rows, shown as «المنطقة»).
     *
     * @var array<string, list<string>>
     */
    private const LOCATIONS = [
        'مخيم 2' => [
            'مدارس جديد', 'الحاج', 'الابراج', 'ابو ذر', 'ابو هويشل', 'ابو رحمة', 'الفقي', 'الشافعي', 'ابو عمرة',
            'زهرة الربيع', 'مكتبة خالد', 'المدارس', 'الحملاوي', 'النادي الأهلي', 'أرض الحلو', 'ابراج (شارع الحسنات)',
            'ابراج (مسجد النور)', 'الابراج (شمال الابراج)', 'ابراج (جنوب الابراج)', 'شوافعة ابو بطيحان',
            'شوافعة ابو عبيدة', 'شوافعة شرقية', 'شوافعة حارة الشافعي', 'حميدة', 'دوار ابو انور', 'الخوالدة',
            'المقبرة', 'شمال المقبرة', 'درويش مدخل', 'درويش عاشور', 'درويش مسجد السنة', 'شوافعة الاهرام',
            'الشقاقي', 'دوار ريان', 'فنونة',
        ],
    ];

    /**
     * Seed the areas, keeping the ones already there and their identifiers.
     */
    public function run(): void
    {
        foreach (self::LOCATIONS as $areaName => $subAreaNames) {
            $area = Area::query()->firstOrCreate(['name' => $areaName]);
            foreach ($subAreaNames as $subAreaName) {
                SubArea::query()->firstOrCreate(['area_id' => $area->id, 'name' => $subAreaName]);
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
            ?? Branch::query()->create(['name' => 'مكتب مخيم 2', 'area_id' => $area->id]);
    }
}
