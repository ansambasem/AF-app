<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PDO;
use PharData;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    /** VACUUM (the SQLite snapshot) cannot run inside the transaction RefreshDatabase would wrap a test in. */
    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo('2026-10-07 02:30:00');
    }

    /** Run the command with another database as the default connection, restoring it after. */
    private function backUpWith(string $connection, array $settings = [], string $expect = 'success'): void
    {
        config(['database.default' => $connection, ...collect($settings)->mapWithKeys(fn ($value, $key): array => ["database.connections.{$connection}.{$key}" => $value])->all()]);

        try {
            // The assertions only record what to expect; the command runs on execute(), while the connection is still switched.
            $command = $this->artisan('backup:run');
            ($expect === 'success' ? $command->assertSuccessful() : $command->assertFailed())->execute();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    /** Restore a backed-up archive into a new directory, as a person would, and return it. */
    private function restoreFiles(string $archive): string
    {
        $tar = sys_get_temp_dir().'/restore-'.uniqid().'.tar';
        file_put_contents($tar, gzdecode(file_get_contents(Storage::disk('local')->path($archive))));
        $target = sys_get_temp_dir().'/restored-'.uniqid();
        (new PharData($tar))->extractTo($target, null, true);
        File::delete($tar);

        return $target;
    }

    /** @return array<int, string> the backup files on the local disk, absolute paths */
    private function backups(): array
    {
        return array_map(fn (string $path): string => Storage::disk('local')->path($path), Storage::disk('local')->files('backups'));
    }

    public function test_a_backup_holds_the_database_and_the_files_and_can_be_restored(): void
    {
        // No rows are added: the test has no transaction to roll back, so it only reads what the migrations left.
        $migrations = DB::table('migrations')->count();
        $voucherCounter = DB::table('payment_voucher_sequences')->value('last_number');
        Storage::disk('local')->put('cash-transfers/proof.jpg', 'proof-image-bytes');

        $this->artisan('backup:run')->assertSuccessful();

        $names = array_map('basename', $this->backups());
        sort($names);
        $this->assertSame(['powercollect-20261007-023000-database.sqlite.gz', 'powercollect-20261007-023000-files.tar.gz', 'powercollect-20261007-023000-public-files.tar.gz'], $names);

        // The restore test: open the database copy as a database and the archive as files.
        $copy = tempnam(sys_get_temp_dir(), 'restore-');
        file_put_contents($copy, gzdecode(file_get_contents(Storage::disk('local')->path('backups/powercollect-20261007-023000-database.sqlite.gz'))));
        $restored = new PDO('sqlite:'.$copy);
        $this->assertGreaterThan(0, $migrations);
        $this->assertSame($migrations, (int) $restored->query('select count(*) from migrations')->fetchColumn());
        $this->assertSame($voucherCounter, (int) $restored->query('select last_number from payment_voucher_sequences')->fetchColumn());
        File::delete($copy);

        $target = $this->restoreFiles('backups/powercollect-20261007-023000-files.tar.gz');
        $this->assertSame('proof-image-bytes', file_get_contents($target.'/cash-transfers/proof.jpg'));
        $this->assertDirectoryDoesNotExist($target.'/backups', 'earlier backups are not backed up again');
        File::deleteDirectory($target);
    }

    public function test_a_second_backup_does_not_include_the_first(): void
    {
        Storage::disk('local')->put('cash-transfers/proof.jpg', 'x');
        $this->artisan('backup:run')->assertSuccessful();
        $this->travelTo('2026-10-08 02:30:00');
        $this->artisan('backup:run')->assertSuccessful();

        $target = $this->restoreFiles('backups/powercollect-20261008-023000-files.tar.gz');
        $this->assertFileExists($target.'/cash-transfers/proof.jpg');
        $this->assertDirectoryDoesNotExist($target.'/backups');
        File::deleteDirectory($target);
        $this->assertCount(6, $this->backups());
    }

    public function test_an_empty_disk_still_gives_an_archive(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertFileExists(Storage::disk('local')->path('backups/powercollect-20261007-023000-files.tar.gz'));
    }

    public function test_backups_older_than_the_retention_are_removed_and_the_new_ones_kept(): void
    {
        $disk = Storage::disk('local');
        $disk->put('backups/powercollect-20260901-020000-database.sqlite.gz', 'old');
        $disk->put('backups/powercollect-20261005-020000-database.sqlite.gz', 'recent');
        $disk->put('backups/notes.txt', 'not a backup');
        touch($disk->path('backups/powercollect-20260901-020000-database.sqlite.gz'), now()->subDays(36)->getTimestamp());
        touch($disk->path('backups/powercollect-20261005-020000-database.sqlite.gz'), now()->subDays(2)->getTimestamp());
        touch($disk->path('backups/notes.txt'), now()->subDays(99)->getTimestamp());

        $this->artisan('backup:run', ['--keep' => 7])->assertSuccessful();

        $disk->assertMissing('backups/powercollect-20260901-020000-database.sqlite.gz');
        $disk->assertExists('backups/powercollect-20261005-020000-database.sqlite.gz');
        $disk->assertExists('backups/notes.txt');
        $this->assertCount(5, $this->backups());
    }

    public function test_the_backup_is_copied_to_the_off_server_disk_and_old_ones_there_are_removed_too(): void
    {
        Storage::fake('offsite');
        config(['powercollect.backup.disk' => 'offsite']);
        Storage::disk('offsite')->put('backups/powercollect-20260801-020000-database.sqlite.gz', 'old');
        touch(Storage::disk('offsite')->path('backups/powercollect-20260801-020000-database.sqlite.gz'), now()->subDays(60)->getTimestamp());

        $this->artisan('backup:run')->assertSuccessful();

        Storage::disk('offsite')->assertExists('backups/powercollect-20261007-023000-database.sqlite.gz');
        Storage::disk('offsite')->assertExists('backups/powercollect-20261007-023000-files.tar.gz');
        Storage::disk('offsite')->assertExists('backups/powercollect-20261007-023000-public-files.tar.gz');
        Storage::disk('offsite')->assertMissing('backups/powercollect-20260801-020000-database.sqlite.gz');
        $this->assertSame(
            md5_file(Storage::disk('local')->path('backups/powercollect-20261007-023000-files.tar.gz')),
            md5(Storage::disk('offsite')->get('backups/powercollect-20261007-023000-files.tar.gz')),
        );
    }

    public function test_no_upload_keeps_the_backup_on_this_server(): void
    {
        Storage::fake('offsite');
        config(['powercollect.backup.disk' => 'offsite']);

        $this->artisan('backup:run', ['--no-upload' => true])->assertSuccessful();

        $this->assertSame([], Storage::disk('offsite')->allFiles());
        $this->assertCount(3, $this->backups());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_failed_upload_preserves_older_backups_and_closes_the_stream(bool $throws): void
    {
        $local = Storage::disk('local');
        $old = 'backups/powercollect-20260801-020000-database.sqlite.gz';
        $local->put($old, 'last-known-backup');
        touch($local->path($old), now()->subDays(60)->getTimestamp());
        $streams = [];
        $offsite = \Mockery::mock(FilesystemAdapter::class);
        $offsite->shouldReceive('writeStream')->once()->andReturnUsing(function (string $path, $stream) use (&$streams, $throws): bool {
            $streams[] = $stream;
            if ($throws) {
                throw new RuntimeException('Offsite unavailable');
            }

            return false;
        });
        $offsite->shouldNotReceive('files');
        Storage::set('offsite', $offsite);
        config(['powercollect.backup.disk' => 'offsite']);

        $this->artisan('backup:run')->assertFailed();

        $this->assertSame('last-known-backup', $local->get($old));
        $this->assertCount(1, $streams);
        $this->assertFalse(is_resource($streams[0]), 'The upload stream must close even when the disk fails.');
    }

    public function test_public_bank_logos_are_backed_up_and_can_be_restored_separately(): void
    {
        Storage::disk('public')->put('bank-logos/bank.webp', 'public-bank-logo');

        $this->artisan('backup:run')->assertSuccessful();

        $archive = 'backups/powercollect-20261007-023000-public-files.tar.gz';
        Storage::disk('local')->assertExists($archive);
        $target = $this->restoreFiles($archive);
        try {
            $this->assertSame('public-bank-logo', file_get_contents($target.'/bank-logos/bank.webp'));
        } finally {
            File::deleteDirectory($target);
        }
    }

    public function test_a_mysql_database_is_dumped_with_the_password_kept_out_of_the_command_line(): void
    {
        $captured = null;
        Process::fake(function (PendingProcess $process) use (&$captured) {
            $captured = $process;
            $resultFile = collect($process->command)->first(fn (string $argument): bool => str_starts_with($argument, '--result-file='));
            file_put_contents(substr($resultFile, strlen('--result-file=')), "-- MySQL dump\nCREATE TABLE users (id int);\n-- Dump completed on 2026-10-07 02:30:01\n");

            return Process::result();
        });

        $this->backUpWith('mysql', ['host' => 'db.internal', 'port' => '3307', 'database' => 'powercollect', 'username' => 'backup', 'password' => 's3cret-pass', 'unix_socket' => '']);

        $this->assertSame('mysqldump', $captured->command[0]);
        $this->assertContains('--host=db.internal', $captured->command);
        $this->assertContains('--port=3307', $captured->command);
        $this->assertContains('--user=backup', $captured->command);
        $this->assertContains('--single-transaction', $captured->command);
        $this->assertSame('powercollect', end($captured->command));
        $this->assertStringNotContainsString('s3cret-pass', implode(' ', $captured->command));
        $this->assertSame('s3cret-pass', $captured->environment['MYSQL_PWD']);
        $this->assertTrue(Storage::disk('local')->exists('backups/powercollect-20261007-023000-database.sql.gz'));
        $this->assertStringContainsString('Dump completed', gzdecode(Storage::disk('local')->get('backups/powercollect-20261007-023000-database.sql.gz')));
    }

    public function test_a_failed_dump_fails_the_backup_and_leaves_nothing_half_made(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Access denied for user', exitCode: 2));

        $this->backUpWith('mysql', expect: 'failure');

        $this->assertSame([], $this->backups());
    }

    public function test_a_dump_that_stops_short_is_not_accepted(): void
    {
        Process::fake(function (PendingProcess $process) {
            $resultFile = collect($process->command)->first(fn (string $argument): bool => str_starts_with($argument, '--result-file='));
            file_put_contents(substr($resultFile, strlen('--result-file=')), "-- MySQL dump\nCREATE TABLE users (id int);\nINSERT INTO users VALUES (1),(2");

            return Process::result();
        });

        $this->backUpWith('mysql', expect: 'failure');
    }

    public function test_a_database_this_cannot_dump_is_refused_clearly(): void
    {
        config(['database.default' => 'pgsql']);

        try {
            $this->artisan('backup:run')->expectsOutputToContain('not supported')->assertFailed()->execute();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }
}
