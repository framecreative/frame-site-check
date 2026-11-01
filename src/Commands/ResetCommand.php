<?php

namespace Frame\SiteCheck\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class ResetCommand extends SiteCheckCommand
{
    protected $signature = 'site-check:reset';

    protected $description = 'Empty the database and Redis, then rebuild the schema from the dump';

    protected function perform(): int
    {
        $this->call('db:wipe', ['--force' => true]);

        // Loaded here rather than by `migrate`, which shells out to a mysql client binary.
        // With the dump's migration rows in place, `migrate` then only runs migrations newer than the dump.
        DB::unprepared(file_get_contents(database_path('schema/mysql-schema.sql')));
        $this->info('Schema loaded from database/schema/mysql-schema.sql.');

        $this->call('migrate', ['--force' => true]);

        Redis::connection()->command('flushall');
        $this->info('Redis flushed.');

        return self::SUCCESS;
    }
}
