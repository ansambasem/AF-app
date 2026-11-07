<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationNamesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MySQL refuses a table, column, index or constraint name longer than
     * 64 characters, but SQLite (the tests' database) accepts any length.
     */
    public function test_every_name_the_migrations_create_fits_the_mysql_limit(): void
    {
        $tooLong = [];

        foreach ($this->mysqlStatements() as $file => $statements) {
            foreach ($statements as $statement) {
                preg_match_all('/`([^`]+)`/', $statement, $names);

                foreach ($names[1] as $name) {
                    if (strlen($name) > 64) {
                        $tooLong[$name] = $file;
                    }
                }
            }
        }

        $this->assertSame([], $tooLong, 'Names longer than 64 characters: '.json_encode($tooLong));
    }

    /**
     * MySQL refuses a foreign key to a table that does not exist yet, but
     * SQLite does not check, so a migration that comes too early would only
     * fail on MySQL.
     */
    public function test_every_foreign_key_points_to_a_table_an_earlier_migration_created(): void
    {
        $created = [];
        $early = [];

        foreach ($this->mysqlStatements() as $file => $statements) {
            foreach ($statements as $statement) {
                if (preg_match('/^create table `([^`]+)`/', $statement, $table)) {
                    $created[$table[1]] = true;
                }

                if (preg_match('/ references `([^`]+)`/', $statement, $target) && ! isset($created[$target[1]])) {
                    $early[$file] = $target[1];
                }
            }
        }

        $this->assertSame([], $early, 'Foreign keys to a table that does not exist yet: '.json_encode($early));
    }

    /**
     * What each migration would run on MySQL, in order, without executing
     * any of it.
     *
     * @return array<string, list<string>>
     */
    private function mysqlStatements(): array
    {
        config(['database.connections.mysql_names' => ['driver' => 'mysql_names', 'prefix' => '']]);
        DB::extend('mysql_names', fn (array $config, string $name) => new class(fn () => new \PDO('sqlite::memory:'), '', '', $config) extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.36';
            }
        });

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_names');
        Schema::clearResolvedInstance('db.schema');
        $statements = [];

        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                $statements[basename($file)] = array_column(DB::connection()->pretend(fn () => (require $file)->up()), 'query');
            }
        } finally {
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }

        return $statements;
    }
}
