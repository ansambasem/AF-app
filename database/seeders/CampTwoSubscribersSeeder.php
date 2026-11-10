<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\TariffCategory;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CircuitBreaker;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CampTwoSubscribersSeeder extends Seeder
{
    public const LEGACY_PREFIX = 'SAMPLE-C2-';

    /** Seed twenty local sample subscriptions using the existing source names. */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Sample subscribers are only available locally or in tests.');
        }

        $subscribers = $this->sourceNames();

        DB::transaction(function () use ($subscribers): void {
            $branch = Branch::query()->where('seed_key', 'camp-two')->whereNotNull('area_id')->lockForUpdate()->first()
                ?? throw new RuntimeException('Run BranchSeeder before adding sample subscribers.');
            $boxesBySubArea = MeterBox::query()
                ->where('branch_id', $branch->id)
                ->whereHas('subArea', fn (Builder $query): Builder => $query->where('area_id', $branch->area_id))
                ->orderBy('sub_area_id')->orderBy('id')->get()
                ->groupBy('sub_area_id')->take(5)
                ->map(fn (Collection $boxes): Collection => $boxes->take(2)->values())
                ->values();

            if ($boxesBySubArea->isEmpty()) {
                throw new RuntimeException('Assign camp meter boxes to the branch sub-areas before seeding subscribers.');
            }

            $registrar = User::query()->where('role', UserRole::SuperAdmin)->where('is_active', true)->first()
                ?? throw new RuntimeException('An active super administrator is required.');

            if (Tariff::query()->doesntExist()) {
                $this->call(TariffSeeder::class);
            }

            $tariff = Tariff::query()->where('category', TariffCategory::Residential)->firstOrFail();
            $breakers = CircuitBreaker::query()->orderBy('ampere')->get();

            if ($breakers->isEmpty()) {
                throw new RuntimeException('Run CircuitBreakerSeeder before adding sample subscribers.');
            }

            $existingSamples = Subscription::query()->with('meterBox.subArea')
                ->whereIn('legacy_number', array_map(fn (array $subscriber): string => self::LEGACY_PREFIX.$subscriber['source_number'], $subscribers))
                ->get()->keyBy('legacy_number');

            foreach ($subscribers as $index => $subscriber) {
                $legacyNumber = self::LEGACY_PREFIX.$subscriber['source_number'];
                $existing = $existingSamples->get($legacyNumber);
                $areaBoxes = $boxesBySubArea[$index % $boxesBySubArea->count()];
                $box = $areaBoxes[intdiv($index, $boxesBySubArea->count()) % $areaBoxes->count()];

                if ($existing !== null) {
                    if ($existing->branch_id !== $branch->id) {
                        throw new RuntimeException('A sample subscription number is already used in another branch.');
                    }

                    if ($existing->meterBox === null || $existing->meterBox->branch_id !== $branch->id
                        || $existing->meterBox->subArea?->area_id !== $branch->area_id) {
                        $existing->update(['meter_box_id' => $box->id]);
                    }

                    continue;
                }

                $breaker = $breakers[$index % $breakers->count()];

                Subscription::query()->create([
                    'legacy_number' => $legacyNumber,
                    'full_name' => $subscriber['full_name'],
                    'branch_id' => $branch->id,
                    'registered_by' => $registrar->id,
                    'meter_box_id' => $box->id,
                    'tariff_id' => $tariff->id,
                    'circuit_breaker_id' => $breaker->id,
                    'minimum_charge' => $breaker->minimum_payment,
                    'status' => SubscriptionStatus::Active,
                    'initial_reading' => 0,
                    'subscription_fee' => 0,
                    'subscription_date' => today(),
                    'notes' => 'مشترك تجريبي من أسماء المشروع — مكتب مخيم 2',
                ]);
            }
        });
    }

    /** @return list<array{source_number: string, full_name: string}> */
    private function sourceNames(): array
    {
        $handle = fopen(__DIR__.'/data/subscriptions.csv', 'r');

        if ($handle === false) {
            throw new RuntimeException('The existing subscription names file cannot be read.');
        }

        try {
            $header = fgetcsv($handle, escape: '') ?: [];
            $subscribers = [];

            while (count($subscribers) < 20 && ($values = fgetcsv($handle, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }

                $row = array_combine($header, $values);
                $subscribers[] = ['source_number' => trim($row['subscription_number']), 'full_name' => trim($row['name'])];
            }

            if (count($subscribers) !== 20) {
                throw new RuntimeException('The source file must contain at least twenty subscriber names.');
            }

            return $subscribers;
        } finally {
            fclose($handle);
        }
    }
}
