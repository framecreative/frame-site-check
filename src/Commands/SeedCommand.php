<?php

namespace Frame\Testbed\Commands;

use Frame\Testbed\Statamic\ContentSeeder;
use Frame\Testbed\Statamic\RunwaySeeder;

class SeedCommand extends TestbedCommand
{
    protected $signature = 'testbed:seed';

    protected $description = 'Seed Runway models and Statamic content from blueprints, then run the site seeders and create accounts';

    protected function perform(): int
    {
        // Nothing syncs to search while seeding; the prepare step reimports everything afterwards.
        config(['scout.driver' => 'null']);

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
                $this->warn("Runway: {$error}");
            }
        }

        // Site seeders run after the Runway models exist, so they can build on them (enrolments, teams, accounts…).
        foreach ($this->settings->get('seeders', []) as $seeder) {
            $this->info("Seeding {$seeder}");
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        if (! $content) {
            return self::SUCCESS;
        }

        $this->info('Seeding Statamic content from blueprints');
        $content->run();

        foreach ($content->unhandled as $type => $count) {
            $this->warn("No generator for fieldtype \"{$type}\" ({$count} fields left empty).");
        }

        $this->seedControlPanelAccounts();

        return self::SUCCESS;
    }

    protected function seedControlPanelAccounts(): void
    {
        foreach ($this->settings->accounts() as $account) {
            if (($account['guard'] ?? null) !== 'cp') {
                continue;
            }

            \Statamic\Facades\User::make()
                ->email($account['email'])
                ->password($account['password'])
                ->makeSuper()
                ->set('name', $account['name'] ?? 'Testbed '.$account['key'])
                ->save();

            $this->line("Control panel account {$account['email']} created.");
        }
    }
}
