<?php

namespace Frame\SiteCheck\Commands;

use Frame\SiteCheck\Statamic\AdminManifest;
use Frame\SiteCheck\Statamic\ContentSeeder;
use Frame\SiteCheck\Statamic\RunwaySeeder;

class SeedCommand extends SiteCheckCommand
{
    protected $signature = 'site-check:seed {--seed= : Faker seed; the same seed reproduces the same data}';

    protected $description = 'Seed Runway models and Statamic content from blueprints, then run the site seeders and create accounts';

    protected function perform(): int
    {
        // Nothing syncs to search while seeding; the prepare step reimports everything afterwards.
        config(['scout.driver' => 'null']);

        if (($seed = $this->option('seed')) !== null) {
            fake()->seed((int) $seed);
            mt_srand((int) $seed);
            $this->line("Seeding with seed {$seed}.");
        }

        // Failures are collected so one run shows them all; the command then fails so the run stops.
        $failures = [];

        $statamic = class_exists(\Statamic\Statamic::class);
        $content = $statamic
            ? new ContentSeeder(array_merge($this->settings->get('statamic', []), ['values' => $this->settings->get('values', [])]))
            : null;

        $content?->prepareAssetContainers();

        if ($statamic && class_exists(\StatamicRadPack\Runway\Runway::class)) {
            $this->info('Seeding Runway models from blueprints');
            $runway = new RunwaySeeder($content, $this->settings->get('runway', []));
            $runway->run();

            foreach ($runway->errors as $error) {
                $failures[] = "Runway: {$error}";
            }
        }

        // Site seeders run after the Runway models exist, so they can build on them (enrolments, teams, accounts…).
        foreach ($this->settings->get('seeders', []) as $seeder) {
            $this->info("Seeding {$seeder}");
            if (($code = $this->call('db:seed', ['--class' => $seeder, '--force' => true])) !== self::SUCCESS) {
                $failures[] = "{$seeder} exited with code {$code}";
            }
        }

        if (! $content) {
            return $this->finish($failures);
        }

        $this->info('Seeding Statamic content from blueprints');
        $content->run();

        foreach ($content->unhandled as $type => $count) {
            $this->warn("No generator for fieldtype \"{$type}\" ({$count} fields left empty).");
        }

        $this->seedControlPanelAccounts();

        if ($this->settings->get('admin.enabled', true)) {
            $count = (new AdminManifest($this->settings->get('admin', []), $content))->write(storage_path('site-check-admin.json'));
            $this->line("{$count} control panel save and create targets written.");
        }

        return $this->finish($failures);
    }

    protected function finish(array $failures): int
    {
        if (! $failures) {
            return self::SUCCESS;
        }

        $this->error('Seeding failed:');
        foreach ($failures as $failure) {
            $this->error("  {$failure}");
        }

        return self::FAILURE;
    }

    protected function seedControlPanelAccounts(): void
    {
        foreach ($this->settings->accounts() as $account) {
            if (($account['guard'] ?? null) !== 'cp') {
                continue;
            }

            $user = \Statamic\Facades\User::make()
                ->email($account['email'])
                ->password($account['password'])
                ->set('name', $account['name'] ?? 'Site check '.$account['key']);

            // An account with roles tests what those roles can do; without, it's a super user.
            $roles = $account['roles'] ?? [];
            foreach ($roles as $role) {
                $user->assignRole($role);
            }
            if (! $roles) {
                $user->makeSuper();
            }
            $user->save();

            $this->line("Control panel account {$account['email']} created (".($roles ? implode(', ', $roles) : 'super user').').');
        }
    }
}
