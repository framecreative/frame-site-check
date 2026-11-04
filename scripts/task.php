<?php

/*
 * Runs one site check task against the site's own Laravel app, inside the container only:
 *
 *   php /frame-site-check/scripts/task.php site-check:seed
 *
 * The tasks are registered for this process alone, so nothing is added to the site's artisan or config.
 */

$root = getcwd();

require $root.'/vendor/autoload.php';

// Load the package from the checkout the runner came from, not the copy Composer installed, so they always match.
spl_autoload_register(function (string $class) {
    $prefix = 'Frame\\SiteCheck\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Site seeders read their accounts and options from config('site-check'), for this process only.
$app['config']->set('site-check', Frame\SiteCheck\Settings::load($root)->all());

foreach ([
    Frame\SiteCheck\Commands\PreflightCommand::class,
    Frame\SiteCheck\Commands\ResetCommand::class,
    Frame\SiteCheck\Commands\SeedCommand::class,
    Frame\SiteCheck\Commands\FreshnessCommand::class,
    Frame\SiteCheck\Commands\AlgoliaResetCommand::class,
] as $command) {
    $kernel->registerCommand($app->make($command));
}

$input = new Symfony\Component\Console\Input\ArgvInput(['artisan', ...array_slice($argv, 1)]);
$status = $kernel->handle($input, new Symfony\Component\Console\Output\ConsoleOutput);
$kernel->terminate($input, $status);

exit($status);
