<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BranchSeeder extends Seeder
{
    /**
     * Keep each key unchanged when renaming a branch.
     *
     * @var list<array{key: string, name: string}>
     */
    private const BRANCHES = [
        ['key' => 'camp-two', 'name' => 'مكتب مخيم 2'],
        ['key' => 'bureij-office', 'name' => 'مكتب البريج'],
        ['key' => 'new-camp-office', 'name' => 'مكتب المخيم الجديد'],
        ['key' => 'nuseirat-office', 'name' => 'مكتب النصيرات'],
        ['key' => 'zawaida-office', 'name' => 'مكتب الزوايدة'],
    ];

    /**
     * Seed the agreed branches, without replacing existing records (an
     * existing branch of the same name takes the key), then the camp
     * branch's areas.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::BRANCHES as $definition) {
                $branch = Branch::query()->where('seed_key', $definition['key'])->first();

                if ($branch === null) {
                    $matches = Branch::query()->whereNull('seed_key')->get()
                        ->filter(fn (Branch $candidate): bool => $this->normalizedName($candidate->name) === $this->normalizedName($definition['name']));

                    if ($matches->count() > 1) {
                        throw new RuntimeException("Multiple existing branches match [{$definition['key']}]; resolve duplicates before seeding.");
                    }

                    $branch = $matches->first() ?? new Branch;
                }

                $branch->seed_key = $definition['key'];
                $branch->fill(['name' => trim($definition['name'])]);
                $branch->save();
            }

            $this->call(LocationSeeder::class);
        });
    }

    private function normalizedName(string $name): string
    {
        return (string) preg_replace('/^(?:فرع\s+)?(?:مكتب\s+)?/u', '', trim($name));
    }
}
