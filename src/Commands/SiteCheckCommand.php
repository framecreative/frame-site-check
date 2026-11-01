<?php

namespace Frame\SiteCheck\Commands;

use Frame\SiteCheck\Settings;
use Illuminate\Console\Command;

/**
 * Every site check command wipes or rewrites data, so each one refuses to run anywhere but the site check container.
 */
abstract class SiteCheckCommand extends Command
{
    protected Settings $settings;

    public function handle(): int
    {
        if ($reason = $this->outsideSiteCheck()) {
            $this->error("Refusing to run {$this->getName()}: {$reason}");

            return self::FAILURE;
        }

        $this->settings = Settings::load(base_path());

        return $this->perform();
    }

    abstract protected function perform(): int;

    protected function outsideSiteCheck(): ?string
    {
        if (! is_file('/.dockerenv')) {
            return 'not running inside a container.';
        }

        if (! app()->environment('site-check')) {
            return 'APP_ENV is not "site-check".';
        }

        if (app()->environmentFile() !== '.env.site-check') {
            return 'the loaded env file is not .env.site-check.';
        }

        return null;
    }
}
