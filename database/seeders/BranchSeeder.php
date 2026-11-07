<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Governorate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BranchSeeder extends Seeder
{
    /**
     * Keep each key unchanged when renaming a branch.
     *
     * @var list<array{key: string, name: string, governorate: string, area: string}>
     */
    private const BRANCHES = [
        ['key' => 'camp-two', 'name' => 'مكتب مخيم 2', 'governorate' => 'الوسطى', 'area' => 'مخيم 2'],
        ['key' => 'bureij-office', 'name' => 'مكتب البريج', 'governorate' => 'الوسطى', 'area' => 'البريج'],
        ['key' => 'new-camp-office', 'name' => 'مكتب المخيم الجديد', 'governorate' => 'الوسطى', 'area' => 'المخيم الجديد'],
        ['key' => 'nuseirat-office', 'name' => 'مكتب النصيرات', 'governorate' => 'الوسطى', 'area' => 'النصيرات'],
        ['key' => 'zawaida-office', 'name' => 'مكتب الزوايدة', 'governorate' => 'الوسطى', 'area' => 'الزوايدة'],
    ];

    /** Seed the agreed branch names and locations without replacing existing records. */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(LocationSeeder::class);

            foreach (self::BRANCHES as $definition) {
                $governorate = Governorate::firstOrCreate(['name' => $definition['governorate']]);
                $area = Area::updateOrCreate(
                    ['name' => $definition['area']],
                    ['governorate_id' => $governorate->id],
                );

                $branch = Branch::query()->where('seed_key', $definition['key'])->first();

                if ($branch === null) {
                    $matches = Branch::query()->whereNull('seed_key')->where('area_id', $area->id)->get()
                        ->filter(fn (Branch $candidate): bool => $this->normalizedName($candidate->name) === $this->normalizedName($definition['name']));

                    if ($matches->count() > 1) {
                        throw new RuntimeException("Multiple existing branches match [{$definition['key']}]; resolve duplicates before seeding.");
                    }

                    $branch = $matches->first() ?? new Branch;
                }

                $branch->seed_key = $definition['key'];
                $branch->fill(['name' => trim($definition['name']), 'governorate_id' => $governorate->id, 'area_id' => $area->id]);
                $branch->save();
            }
        });
    }

    private function normalizedName(string $name): string
    {
        return (string) preg_replace('/^(?:فرع\s+)?(?:مكتب\s+)?/u', '', trim($name));
    }
}
