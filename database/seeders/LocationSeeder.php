<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\SubArea;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * The areas of the مخيم 2 branch, each holding its meter boxes.
     *
     * @var list<string>
     */
    private const AREAS = [
        'مدارس جديد', 'الحاج', 'الابراج', 'ابو ذر', 'ابو هويشل', 'ابو رحمة', 'الفقي', 'الشافعي', 'ابو عمرة',
        'زهرة الربيع', 'مكتبة خالد', 'المدارس', 'الحملاوي', 'النادي الأهلي', 'أرض الحلو', 'ابراج (شارع الحسنات)',
        'ابراج (مسجد النور)', 'الابراج (شمال الابراج)', 'ابراج (جنوب الابراج)', 'شوافعة ابو بطيحان',
        'شوافعة ابو عبيدة', 'شوافعة شرقية', 'شوافعة حارة الشافعي', 'حميدة', 'دوار ابو انور', 'الخوالدة',
        'المقبرة', 'شمال المقبرة', 'درويش مدخل', 'درويش عاشور', 'درويش مسجد السنة', 'شوافعة الاهرام',
        'الشقاقي', 'دوار ريان', 'فنونة',
    ];

    /**
     * Seed the branch's areas, keeping the ones already there.
     */
    public function run(): void
    {
        $branch = self::branch();

        foreach (self::AREAS as $name) {
            SubArea::query()->firstOrCreate(['branch_id' => $branch->id, 'name' => $name]);
        }
    }

    /**
     * The مخيم 2 branch the areas, boxes and subscriptions belong to: the
     * one keyed «camp-two», else the one named «مخيم 2» (or the only one
     * whose name contains it). If there is none yet, it is created.
     */
    public static function branch(): Branch
    {
        $named = Branch::query()->where('seed_key', 'camp-two')->orWhere('name', 'like', '%مخيم 2%')->get();

        return $named->firstWhere('seed_key', 'camp-two')
            ?? $named->firstWhere('name', 'مخيم 2')
            ?? ($named->count() === 1 ? $named->first() : null)
            ?? Branch::query()->create(['name' => 'مكتب مخيم 2']);
    }
}
