<?php

namespace App\Console\Commands;

use App\Enums\AccountingType;
use App\Enums\SubscriptionStatus;
use App\Enums\TariffCategory;
use App\Models\MeterBox;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Messaging\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportSubscriptions extends Command
{
    /** The columns the file must have, in any order. */
    private const COLUMNS = ['subscription_number', 'name', 'phone_number', 'balance', 'subscription_type', 'minimum_limit', 'area'];

    /** The longest legacy number the subscriptions table holds. */
    private const MAX_NUMBER_LENGTH = 20;

    protected $signature = 'subscriptions:import
        {file : CSV file with a header row: subscription_number, name, phone_number, balance, subscription_type, minimum_limit, area, and optionally box_number}
        {--user= : Username of the user registering the subscriptions; they join that user\'s branch}
        {--dry-run : Check the file and report what would be imported without saving anything}';

    protected $description = 'Import subscriptions from the old system, with their balance as an opening line on the account';

    /**
     * Each row becomes a subscription of the user's branch, found later by
     * its old number. A row already imported is skipped, so the file can be
     * run again. A subscription joins a meter box only when the row gives a
     * `box_number` that is a real box of the branch: no box or sub-area is
     * ever created, and without one the subscription has none. The old
     * system's area is kept in the subscription's notes. A positive balance
     * is what the subscription owes; a negative one is credit in their
     * favour. A dry run checks every row exactly as the real run does, and
     * saves nothing.
     */
    public function handle(): int
    {
        $user = User::query()->with('branch')->where('username', $this->option('user'))->first();

        if ($user === null || $user->branch_id === null) {
            $this->error('Pass --user=<username> of a user who belongs to a branch.');

            return self::FAILURE;
        }

        $handle = is_readable($this->argument('file')) ? fopen($this->argument('file'), 'r') : false;

        if ($handle === false) {
            $this->error('The file cannot be read.');

            return self::FAILURE;
        }

        $header = array_map(fn (?string $name): string => trim(ltrim((string) $name, "\xEF\xBB\xBF")), fgetcsv($handle) ?: []);

        if (array_diff(self::COLUMNS, $header) !== []) {
            $this->error('The header row must contain: '.implode(', ', self::COLUMNS));

            return self::FAILURE;
        }

        $boxes = MeterBox::query()->get(['id', 'box_number', 'branch_id'])->keyBy('box_number');
        $alreadyImported = Subscription::query()->whereNotNull('legacy_number')->pluck('legacy_number')->flip();
        $dryRun = (bool) $this->option('dry-run');
        $imported = $skipped = 0;
        $failures = [];
        $seen = [];

        for ($line = 2; ($values = fgetcsv($handle)) !== false; $line++) {
            if ($values === [null]) {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));

            try {
                // A number used twice in the file is two different people: the second is left out, not mistaken for one already imported.
                $legacyNumber = trim((string) $row['subscription_number']);

                if ($legacyNumber !== '' && isset($seen[$legacyNumber])) {
                    throw new RuntimeException("The subscription number is already used on line {$seen[$legacyNumber]}.");
                }

                $seen[$legacyNumber] = $line;
                $this->requireNumberAndName($row, $legacyNumber);

                if ($alreadyImported->has($legacyNumber)) {
                    $skipped++;

                    continue;
                }

                $subscription = $this->subscriptionFrom($row, $legacyNumber, $user, $boxes);

                if (! $dryRun) {
                    $this->save($subscription, $user);
                }

                $imported++;
            } catch (RuntimeException $exception) {
                $failures[] = "Line {$line} ({$row['subscription_number']}): {$exception->getMessage()}";
            }
        }

        fclose($handle);

        $this->info(($dryRun ? 'Checked' : 'Imported')." {$imported} · already imported {$skipped} · failed ".count($failures));
        array_map(fn (string $failure) => $this->warn($failure), $failures);

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, ?string> $row */
    private function requireNumberAndName(array $row, string $legacyNumber): void
    {
        if ($legacyNumber === '' || trim((string) $row['name']) === '') {
            throw new RuntimeException('The subscription number and the name are required.');
        }

        if (mb_strlen($legacyNumber) > self::MAX_NUMBER_LENGTH) {
            throw new RuntimeException('The subscription number is longer than '.self::MAX_NUMBER_LENGTH.' characters.');
        }

        if (mb_strlen(trim((string) $row['name'])) > 255) {
            throw new RuntimeException('The name is longer than 255 characters.');
        }
    }

    /**
     * The subscription a row stands for, once everything in it is checked
     * (the dry run stops here).
     *
     * @param  array<string, ?string>  $row
     * @param  Collection<string, MeterBox>  $boxes  the meter boxes, by number
     * @return array{legacy_number: string, full_name: string, phone: ?string, meter_box_id: ?int, tariff_id: int, minimum_charge: ?string, accounting_type: AccountingType, notes: ?string, balance: float}
     */
    private function subscriptionFrom(array $row, string $legacyNumber, User $user, Collection $boxes): array
    {
        $tariff = $this->tariffFor($row);
        $area = trim((string) $row['area']);

        return [
            'legacy_number' => $legacyNumber,
            'full_name' => trim((string) $row['name']),
            'phone' => $this->phoneOf($row),
            'meter_box_id' => $this->meterBoxFor($row, $user, $boxes)?->id,
            'tariff_id' => $tariff->id,
            'minimum_charge' => is_numeric($row['minimum_limit']) ? $row['minimum_limit'] : null,
            'accounting_type' => str_contains((string) $row['subscription_type'], 'شهري') ? AccountingType::Monthly : AccountingType::Weekly,
            'notes' => $area === '' ? null : 'المنطقة في النظام القديم: '.$area,
            'balance' => $this->balanceOf($row),
        ];
    }

    /**
     * @param  array{legacy_number: string, full_name: string, phone: ?string, meter_box_id: ?int, tariff_id: int, minimum_charge: ?string, accounting_type: AccountingType, notes: ?string, balance: float}  $attributes
     */
    private function save(array $attributes, User $user): void
    {
        DB::transaction(function () use ($attributes, $user): void {
            $subscription = Subscription::create([
                ...collect($attributes)->except('balance')->all(),
                'branch_id' => $user->branch_id,
                'status' => SubscriptionStatus::Active,
                'registered_by' => $user->id,
            ]);

            if ($attributes['balance'] !== 0.0) {
                $subscription->transactions()->create([
                    'recorded_by' => $user->id,
                    'type' => $attributes['balance'] > 0 ? SubscriptionTransaction::TYPE_INVOICE : SubscriptionTransaction::TYPE_CREDIT,
                    'source_key' => 'import:'.$attributes['legacy_number'],
                    'amount' => number_format($attributes['balance'], 2, '.', ''),
                    'notes' => 'رصيد افتتاحي من النظام القديم',
                ]);
            }
        });
    }

    /** @param array<string, ?string> $row */
    private function tariffFor(array $row): Tariff
    {
        $category = match (true) {
            str_contains((string) $row['subscription_type'], 'منزلي') => TariffCategory::Residential,
            str_contains((string) $row['subscription_type'], 'تجاري') => TariffCategory::Commercial,
            default => throw new RuntimeException("Unknown subscription type «{$row['subscription_type']}»."),
        };

        return Tariff::query()->where('category', $category->value)->first()
            ?? throw new RuntimeException("There is no {$category->value} tariff; run the TariffSeeder first.");
    }

    /** @param array<string, ?string> $row */
    private function balanceOf(array $row): float
    {
        return is_numeric($row['balance']) ? round((float) $row['balance'], 2) : throw new RuntimeException("The balance «{$row['balance']}» is not a number.");
    }

    /**
     * The mobile number as the subscription form wants it (10 digits, 059 or
     * 056), tidied first: Arabic-Indic digits, spaces, dashes, a missing
     * leading zero or the 970 country code. A row without one has none.
     *
     * @param  array<string, ?string>  $row
     */
    private function phoneOf(array $row): ?string
    {
        $typed = trim((string) $row['phone_number']);

        if ($typed === '') {
            return null;
        }

        $digits = PhoneNumber::digits($typed);
        $digits = match (true) {
            preg_match('/\A970(5[69]\d{7})\z/', $digits, $matches) === 1 => '0'.$matches[1],
            preg_match('/\A5[69]\d{7}\z/', $digits) === 1 => '0'.$digits,
            default => $digits,
        };

        return preg_match('/\A05[69][0-9]{7}\z/', $digits) === 1
            ? $digits
            : throw new RuntimeException("The phone number «{$typed}» is not a mobile number (10 digits starting with 059 or 056).");
    }

    /**
     * The real meter box of the branch that the row's `box_number` names,
     * or none when the file has no such column or the row leaves it empty.
     *
     * @param  array<string, ?string>  $row
     * @param  Collection<string, MeterBox>  $boxes  the meter boxes, by number
     */
    private function meterBoxFor(array $row, User $user, Collection $boxes): ?MeterBox
    {
        $number = trim((string) ($row['box_number'] ?? ''));

        if ($number === '') {
            return null;
        }

        $box = $boxes->get($number) ?? throw new RuntimeException("There is no meter box numbered «{$number}».");

        if ($box->branch_id !== $user->branch_id) {
            throw new RuntimeException("The meter box «{$number}» belongs to another branch.");
        }

        return $box;
    }
}
