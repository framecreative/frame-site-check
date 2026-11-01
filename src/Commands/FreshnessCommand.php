<?php

namespace Frame\Testbed\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FreshnessCommand extends TestbedCommand
{
    protected $signature = 'testbed:freshness {--json= : Write the findings to this file}';

    protected $description = 'Report gaps in the seeded data, and fail if any non-test email address exists';

    /** Tables that are expected to be empty after seeding. */
    protected const IGNORED_TABLES = [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions',
        'password_resets', 'password_reset_tokens', 'personal_access_tokens',
    ];

    protected function perform(): int
    {
        $ignoredTables = array_merge(self::IGNORED_TABLES, $this->settings->get('freshness.ignore_tables', []));
        $ignoredColumns = $this->settings->get('freshness.ignore_columns', []);

        $emptyTables = [];
        $nullColumns = [];
        $realEmails = [];

        foreach ($this->tables() as $table) {
            if (in_array($table, $ignoredTables, true)) {
                continue;
            }

            if (DB::table($table)->count() === 0) {
                $emptyTables[] = $table;

                continue;
            }

            foreach (Schema::getColumns($table) as $column) {
                $name = $column['name'];

                if (str_contains(strtolower($name), 'email')) {
                    $found = DB::table($table)->whereNotNull($name)->where($name, 'like', '%@%')
                        ->where($name, 'not like', '%@example.test')->value($name);
                    if ($found) {
                        $realEmails[] = "{$table}.{$name}: {$found}";
                    }
                }

                if ($column['nullable'] && ! in_array("{$table}.{$name}", $ignoredColumns, true)
                    && DB::table($table)->whereNotNull($name)->doesntExist()) {
                    $nullColumns[] = "{$table}.{$name}";
                }
            }
        }

        foreach (glob(base_path('users/*.yaml')) ?: [] as $file) {
            if (! str_ends_with(basename($file, '.yaml'), '@example.test')) {
                $realEmails[] = 'users/'.basename($file);
            }
        }

        $this->report('Tables with no seeded rows', $emptyTables);
        $this->report('Columns null in every seeded row', $nullColumns);
        $this->report('Non-test email addresses', $realEmails, error: true);

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode(compact('emptyTables', 'nullColumns', 'realEmails'), JSON_PRETTY_PRINT));
        }

        return $realEmails ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, string> */
    protected function tables(): array
    {
        return array_map(fn ($table) => $table['name'], Schema::getTables());
    }

    protected function report(string $title, array $items, bool $error = false): void
    {
        $this->line(sprintf('%s: %d', $title, count($items)));

        foreach ($items as $item) {
            $error ? $this->error("  {$item}") : $this->line("  {$item}");
        }
    }
}
