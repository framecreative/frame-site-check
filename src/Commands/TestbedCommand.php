<?php

namespace Frame\Testbed\Commands;

use Frame\Testbed\Settings;
use Illuminate\Console\Command;

/**
 * Every testbed command wipes or rewrites data, so each one refuses to run anywhere but the testbed container.
 */
abstract class TestbedCommand extends Command
{
    protected Settings $settings;

    public function handle(): int
    {
        if ($reason = $this->outsideTestbed()) {
            $this->error("Refusing to run {$this->getName()}: {$reason}");

            return self::FAILURE;
        }

        $this->settings = Settings::load(base_path());

        return $this->perform();
    }

    abstract protected function perform(): int;

    protected function outsideTestbed(): ?string
    {
        if (! is_file('/.dockerenv')) {
            return 'not running inside a container.';
        }

        if (! app()->environment('testbed')) {
            return 'APP_ENV is not "testbed".';
        }

        if (app()->environmentFile() !== '.env.testbed') {
            return 'the loaded env file is not .env.testbed.';
        }

        return null;
    }
}
