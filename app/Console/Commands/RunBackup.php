<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Phar;
use PharData;
use RuntimeException;
use Throwable;

class RunBackup extends Command
{
    /** Where the backups are kept, on the local disk (and on the off-server one). */
    private const DIRECTORY = 'backups';

    protected $signature = 'backup:run
        {--keep= : Days to keep older backups (default: BACKUP_KEEP_DAYS, 14)}
        {--no-upload : Keep the backup on this server only, even when an off-server disk is set}';

    protected $description = 'Back up the database and the stored files (cash hand-over proofs), check the copies, send them off the server and remove the old ones';

    /**
     * Three files per run: the database dump (gzipped SQL for MySQL, a
     * snapshot for SQLite), the private files and the public uploads.
     * Private and public files have separate gzipped tar archives. Each is
     * opened again to check it is whole before anything is sent or deleted.
     * With `BACKUP_DISK` set to an off-server disk (S3, say) all are
     * copied there too; backups older than the retention are removed from
     * both places.
     */
    public function handle(): int
    {
        $local = Storage::disk('local');
        $local->makeDirectory(self::DIRECTORY);
        $stamp = now()->format('Ymd-His');

        try {
            $made = [$this->backUpDatabase($stamp), $this->backUpFiles($stamp), $this->backUpFiles($stamp, 'public')];

            foreach ($made as $file) {
                $this->verify($file);
                $this->components->twoColumnDetail(basename($file), number_format(filesize($file) / 1024, 1).' KB — checked');
            }

            $disk = $this->option('no-upload') ? null : config('powercollect.backup.disk');

            if ($disk !== null && $disk !== '') {
                foreach ($made as $file) {
                    $stream = fopen($file, 'r');
                    if ($stream === false) {
                        throw new RuntimeException('Could not open '.basename($file).' for upload.');
                    }
                    try {
                        if (! Storage::disk($disk)->writeStream(self::DIRECTORY.'/'.basename($file), $stream)) {
                            throw new RuntimeException('Could not upload '.basename($file).' to '.$disk.'.');
                        }
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                }

                $this->components->twoColumnDetail('Sent to', $disk);
            }

            $removed = $this->prune('local') + ($disk ? $this->prune($disk) : 0);
            $this->components->info("Backup {$stamp} done; {$removed} old file(s) removed.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('The backup failed: '.$exception->getMessage());
            $this->components->error('The backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** The database as a file in the backups directory (absolute path). */
    private function backUpDatabase(string $stamp): string
    {
        $connection = config('database.default');
        $settings = config("database.connections.{$connection}");
        $directory = Storage::disk('local')->path(self::DIRECTORY);

        return match ($settings['driver']) {
            'sqlite' => $this->snapshotSqlite($directory, $stamp),
            'mysql', 'mariadb' => $this->dumpMysql($settings, $directory, $stamp),
            default => throw new RuntimeException("Backing up a {$settings['driver']} database is not supported."),
        };
    }

    /** A consistent copy of the SQLite file, made by the database itself, gzipped. */
    private function snapshotSqlite(string $directory, string $stamp): string
    {
        $copy = "{$directory}/powercollect-{$stamp}-database.sqlite";

        DB::statement('VACUUM INTO '.DB::getPdo()->quote($copy));

        return $this->gzip($copy, "{$copy}.gz");
    }

    /**
     * A mysqldump of the database, gzipped. The password goes to the tool
     * in its environment, so it is never in the process list.
     *
     * @param  array<string, mixed>  $settings
     */
    private function dumpMysql(array $settings, string $directory, string $stamp): string
    {
        $dump = "{$directory}/powercollect-{$stamp}-database.sql";
        $socket = $settings['unix_socket'] ?? null;
        $connection = filled($socket) ? ['--socket='.$socket] : ['--host='.$settings['host'], '--port='.$settings['port']];

        $result = Process::timeout(3600)->env(['MYSQL_PWD' => (string) $settings['password']])->run([
            'mysqldump',
            ...$connection,
            '--user='.$settings['username'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$dump,
            $settings['database'],
        ]);

        if ($result->failed()) {
            File::delete($dump);

            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }

        return $this->gzip($dump, "{$dump}.gz");
    }

    /** Archive a local uploads disk, keeping private and public restore paths separate. */
    private function backUpFiles(string $stamp, string $sourceDisk = 'local'): string
    {
        $source = Storage::disk($sourceDisk);
        if (! $source->exists('/')) {
            $source->makeDirectory('/');
        }
        $root = $source->path('');
        $suffix = $sourceDisk === 'local' ? 'files' : 'public-files';
        $tar = Storage::disk('local')->path(self::DIRECTORY."/powercollect-{$stamp}-{$suffix}.tar");
        $archive = new PharData($tar);
        $archive->buildFromDirectory(rtrim($root, '/'), '#^(?!.*/'.self::DIRECTORY.'(/|$)).*$#');

        if (count($archive) === 0) {
            // An empty disk still gets an archive, so that "no files" is a fact the backup records.
            $archive->addEmptyDir('empty');
        }

        // The entries are written out when the archive is closed; the finished tar is then gzipped and removed.
        unset($archive);
        $this->gzip($tar, "{$tar}.gz", keepSource: true);
        Phar::unlinkArchive($tar);

        return "{$tar}.gz";
    }

    /** Gzip a file into `$target`, removing the plain copy unless `$keepSource`. */
    private function gzip(string $source, string $target, bool $keepSource = false): string
    {
        $input = fopen($source, 'r');
        $output = gzopen($target, 'wb9');

        while (! feof($input)) {
            gzwrite($output, fread($input, 1024 * 1024));
        }

        fclose($input);
        gzclose($output);

        if (! $keepSource) {
            File::delete($source);
        }

        return $target;
    }

    /** Open the file again: an archive lists its entries, a dump reaches its end marker or a SQLite snapshot passes its integrity check. */
    private function verify(string $file): void
    {
        if (str_ends_with($file, '-files.tar.gz')) {
            if ($this->countArchiveEntries($file) === 0) {
                throw new RuntimeException(basename($file).' has nothing in it.');
            }

            return;
        }

        $plain = tempnam(sys_get_temp_dir(), 'backup-check-');

        try {
            $input = gzopen($file, 'rb');
            $output = fopen($plain, 'w');

            while (! gzeof($input)) {
                fwrite($output, gzread($input, 1024 * 1024));
            }

            gzclose($input);
            fclose($output);

            if (str_contains($file, '.sqlite')) {
                $snapshot = new \PDO('sqlite:'.$plain);
                $verdict = $snapshot->query('PRAGMA integrity_check')->fetchColumn();

                if ($verdict !== 'ok') {
                    throw new RuntimeException(basename($file)." failed its integrity check: {$verdict}");
                }

                return;
            }

            $tail = (string) file_get_contents($plain, false, null, max(0, filesize($plain) - 512));

            if (! str_contains($tail, 'Dump completed')) {
                throw new RuntimeException(basename($file).' is cut short: it does not end with the dump marker.');
            }
        } finally {
            File::delete($plain);
        }
    }

    /** How many entries a gzipped tar lists, read straight from its headers (a damaged one stops early or throws). */
    private function countArchiveEntries(string $file): int
    {
        $stream = gzopen($file, 'rb');
        $entries = 0;

        try {
            while (($header = gzread($stream, 512)) !== false && strlen($header) === 512 && trim($header, "\0") !== '') {
                $entries++;
                $size = (int) octdec(trim(substr($header, 124, 12)));
                $remaining = (int) (ceil($size / 512) * 512);

                while ($remaining > 0) {
                    $chunk = gzread($stream, min($remaining, 1024 * 1024));

                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException(basename($file).' is cut short.');
                    }

                    $remaining -= strlen($chunk);
                }
            }
        } finally {
            gzclose($stream);
        }

        return $entries;
    }

    /** Delete the backups older than the retention from a disk; how many went. */
    private function prune(string $disk): int
    {
        $keepDays = max(1, (int) ($this->option('keep') ?: config('powercollect.backup.keep_days')));
        $oldest = now()->subDays($keepDays)->getTimestamp();
        $storage = Storage::disk($disk);
        $removed = 0;

        foreach ($storage->files(self::DIRECTORY) as $path) {
            if (str_starts_with(basename($path), 'powercollect-') && $storage->lastModified($path) < $oldest) {
                $storage->delete($path);
                $removed++;
            }
        }

        return $removed;
    }
}
