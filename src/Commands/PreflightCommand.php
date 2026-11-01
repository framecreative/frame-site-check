<?php

namespace Frame\Testbed\Commands;

use Frame\Testbed\Algolia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PreflightCommand extends TestbedCommand
{
    protected $signature = 'testbed:preflight';

    protected $description = 'Check the testbed configuration before any data is touched';

    /** @var array<int, string> */
    protected array $failures = [];

    protected function perform(): int
    {
        $this->checkEnvIsolation();
        $this->checkSettings();
        $this->checkSafety();
        $this->checkUrls();
        $this->checkServices();
        $this->checkVersions();
        $this->checkAlgolia();

        if ($this->failures) {
            $this->newLine();
            $this->error('Preflight failed:');
            foreach ($this->failures as $failure) {
                $this->line("  - {$failure}");
            }

            return self::FAILURE;
        }

        $this->info('Preflight passed.');

        return self::SUCCESS;
    }

    protected function flag(string $message): void
    {
        $this->failures[] = $message;
    }

    protected function checkEnvIsolation(): void
    {
        $others = array_filter(
            glob(base_path('.env*')) ?: [],
            fn ($file) => basename($file) !== '.env.testbed',
        );

        foreach ($others as $file) {
            $this->flag(basename($file).' is visible inside the container. Only .env.testbed may be present; check the sync excludes.');
        }
    }

    protected function checkSettings(): void
    {
        foreach ($this->settings->get('seeders', []) as $seeder) {
            if (! class_exists($seeder)) {
                $this->flag("Seeder {$seeder} in testbed.yaml doesn't exist.");
            }
        }

        foreach ($this->settings->accounts() as $index => $account) {
            foreach (['key', 'email', 'password'] as $field) {
                if (empty($account[$field])) {
                    $this->flag("Account #{$index} in testbed.yaml is missing \"{$field}\".");
                }
            }

            if (! empty($account['email']) && ! str_ends_with($account['email'], '@example.test')) {
                $this->flag("Account {$account['email']} in testbed.yaml must use an @example.test address.");
            }
        }

        if (! is_file(database_path('schema/mysql-schema.sql'))) {
            $this->flag('database/schema/mysql-schema.sql is missing. Flatten the migrations with `php artisan schema:dump --prune`.');
        }
    }

    protected function checkSafety(): void
    {
        if (config('statamic.git.enabled')) {
            $this->flag('Statamic git automation is enabled (statamic.git.enabled). It must be off in the testbed.');
        }

        if (! in_array(config('mail.default'), ['log', 'array', 'smtp'], true)) {
            $this->flag('Mail driver is "'.config('mail.default').'". Use "log", or "smtp" pointed at a local catcher.');
        }

        if (config('mail.default') === 'smtp' && ! in_array(config('mail.mailers.smtp.host'), ['mailhog', 'mailpit'], true)) {
            $this->flag('Mail is SMTP to "'.config('mail.mailers.smtp.host').'". Only a local catcher (mailhog/mailpit) is allowed.');
        }

        if (config('queue.default') !== 'database') {
            $this->flag('Queue connection is "'.config('queue.default').'". It must be "database" (stored, never run) so no jobs reach external services.');
        }

        foreach ($this->settings->get('must_be_empty', []) as $key) {
            if (filled(env($key))) {
                $this->flag("{$key} is set. It must be empty in the testbed so the integration can't make real requests.");
            }
        }
    }

    protected function checkUrls(): void
    {
        $this->checkUrl('APP_URL', (string) config('app.url'));

        foreach (array_keys(config('filesystems.disks', [])) as $disk) {
            if (! config("filesystems.disks.{$disk}.url")) {
                continue;
            }

            try {
                $this->checkUrl("filesystem disk \"{$disk}\"", Storage::disk($disk)->url('testbed/check.jpg'));
            } catch (Throwable $e) {
                $this->flag("Filesystem disk \"{$disk}\" can't build a URL: {$e->getMessage()}");
            }
        }
    }

    protected function checkUrl(string $label, string $url): void
    {
        $parts = parse_url($url);

        if (! filter_var($url, FILTER_VALIDATE_URL) || $parts === false || isset($parts['port']) && ! is_int($parts['port'])) {
            $this->flag("{$label} builds an invalid URL: {$url}");
        }
    }

    protected function checkServices(): void
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            $this->flag("Database unreachable: {$e->getMessage()}");
        }

        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $this->flag("Redis unreachable: {$e->getMessage()}");
        }
    }

    protected function checkVersions(): void
    {
        $php = (string) $this->settings->get('php', '8.3');
        if (PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION !== $php) {
            $this->flag('PHP is '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION." but testbed.yaml asks for {$php}.");
        }

        $node = (string) $this->settings->get('node', '22');
        $installed = trim((string) shell_exec('node -v 2>/dev/null'));
        if (! str_starts_with($installed, "v{$node}.")) {
            $this->flag("Node is {$installed} but testbed.yaml asks for {$node}.");
        }
    }

    protected function checkAlgolia(): void
    {
        $missing = array_filter([
            'ALGOLIA_APP_ID' => config('scout.algolia.id'),
            'ALGOLIA_SECRET' => config('scout.algolia.secret'),
            'ALGOLIA_SEARCH_KEY' => config('scout.algolia.search'),
        ], fn ($value) => blank($value));

        foreach (array_keys($missing) as $key) {
            $this->flag("{$key} is not set in .env.testbed.");
        }

        if ($missing) {
            return;
        }

        $index = 'testbed_preflight';

        try {
            $response = Algolia::admin()->listIndexes();
            if (! $response->successful()) {
                $this->flag("ALGOLIA_SECRET in .env.testbed was rejected by Algolia ({$response->status()} {$response->json('message')}). Check the test app's admin API key.");

                return;
            }

            $write = Algolia::admin()->addObject($index, ['testbed' => true]);
            if (! $write->successful()) {
                $this->flag("ALGOLIA_SECRET can't write to Algolia ({$write->status()} {$write->json('message')}). The admin key needs addObject and deleteIndex.");
            }

            $searchWrite = Algolia::search()->addObject($index, ['testbed' => true]);
            if ($searchWrite->successful()) {
                $this->flag('ALGOLIA_SEARCH_KEY can write to Algolia. It is built into the front end, so it must be a search-only key.');
            }

            $search = Algolia::search()->listIndexes();
            if ($search->status() === 403 && str_contains((string) $search->json('message'), 'Invalid Application-ID or API key')) {
                $this->flag("ALGOLIA_SEARCH_KEY in .env.testbed was rejected by Algolia ({$search->json('message')}).");
            }

            Algolia::admin()->deleteIndex($index);
        } catch (Throwable $e) {
            $this->flag("Algolia unreachable: {$e->getMessage()}");
        }
    }
}
