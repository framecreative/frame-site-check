<?php

namespace Frame\SiteCheck;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;

/**
 * The site's site-check.yaml, with the shared defaults applied.
 */
class Settings
{
    public const FILE = 'site-check.yaml';

    /** Env values every run enforces; a site's site-check.yaml can't override these. */
    public const PROTECTED_ENV = [
        'APP_ENV' => 'site-check',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'mysql',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'site_check',
        'DB_USERNAME' => 'site_check',
        'DB_PASSWORD' => 'site_check',
        'REDIS_HOST' => 'redis',
        'REDIS_PORT' => '6379',
        'REDIS_PASSWORD' => '',
        // Jobs are stored and never run: no worker runs in the container. ("null" would be read as PHP null.)
        'QUEUE_CONNECTION' => 'database',
        'STATAMIC_GIT_ENABLED' => 'false',
        'BROADCAST_DRIVER' => 'log',
        'BROADCAST_CONNECTION' => 'log',
        // Every email goes to the Mailpit container, never out; the run saves what was sent to mail.json.
        'MAIL_MAILER' => 'smtp',
        'MAIL_HOST' => 'mailpit',
        'MAIL_PORT' => '1025',
        'MAIL_SCHEME' => '',
        'MAIL_ENCRYPTION' => '',
        'MAIL_USERNAME' => '',
        'MAIL_PASSWORD' => '',
    ];

    /** Env values every run starts with; a site's site-check.yaml can override these. */
    public const DEFAULT_ENV = [
        'APP_DEBUG' => 'true',
        'APP_URL' => 'http://app:8000',
        'LOG_CHANNEL' => 'single',
        'LOG_LEVEL' => 'debug',
        'CACHE_DRIVER' => 'redis',
        'CACHE_STORE' => 'redis',
        'SESSION_DRIVER' => 'file',
        'SCOUT_DRIVER' => 'algolia',
        'SCOUT_QUEUE' => 'false',
    ];

    /** External API hosts that resolve to nowhere inside the app container. */
    public const BLOCKED_HOSTS = [
        'api.hubapi.com',
        'api.hubspot.com',
        'api.createsend.com',
        'api.mailgun.net',
        'api.eu.mailgun.net',
        'api.rollbar.com',
        'hooks.slack.com',
    ];

    public function __construct(protected array $values)
    {
    }

    public static function load(string $basePath): self
    {
        $file = rtrim($basePath, '/').'/'.self::FILE;

        if (! is_file($file)) {
            throw new \RuntimeException("No ".self::FILE." found in {$basePath}.");
        }

        return new self(Yaml::parseFile($file) ?? []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, $key, $default);
    }

    public function all(): array
    {
        return $this->values;
    }

    /** @return array<int, array{key: string, email: string, password: string, guard?: string}> */
    public function accounts(): array
    {
        return $this->get('accounts', []);
    }

    public function env(): array
    {
        $site = array_map(fn ($value) => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => (string) $value,
        }, $this->get('env', []));

        return array_merge(self::DEFAULT_ENV, $site, self::PROTECTED_ENV);
    }

    public function blockedHosts(): array
    {
        return array_values(array_unique(array_merge(self::BLOCKED_HOSTS, $this->get('block_hosts', []))));
    }
}
